<?php

/**
 * Runs one full DynDNS cycle and keeps the high-level flow in one place.
 * It relies on helper classes for config, API calls, state, and notifications.
 */
final class DynDnsService
{
    private const STAGE_STARTUP_CONFIG = 'startup/config validation';
    private const STAGE_AUTH_SESSION = 'authentication/session preflight';
    private const STAGE_TARGET_VALIDATION = 'target/zone validation';
    private const STAGE_PUBLIC_IP_DETECTION = 'public IP detection';
    private const STAGE_UPDATE_DECISION = 'update decision';
    private const STAGE_LIVE_MUTATION = 'live mutation';
    private const STAGE_SESSION_CLEANUP = 'session cleanup';

    private Config $config;
    private Logger $logger;
    private StateStore $stateStore;
    private PublicIpResolver $resolver;
    private DnsProvider $provider;
    private PushoverNotifier $pushover;
    private string $currentStage = self::STAGE_STARTUP_CONFIG;
    private bool $liveMutationAttempted = false;

    public function __construct(
        Config $config,
        Logger $logger,
        StateStore $stateStore,
        PublicIpResolver $resolver,
        DnsProvider $provider,
        PushoverNotifier $pushover
    ) {
        $this->config = $config;
        $this->logger = $logger;
        $this->stateStore = $stateStore;
        $this->resolver = $resolver;
        $this->provider = $provider;
        $this->pushover = $pushover;
        $this->provider->setStage($this->currentStage);
    }

    public function runOnce(): int
    {
        $resultCode = 1;

        try {
            $this->enterStage(self::STAGE_STARTUP_CONFIG);
            $this->logger->info('Execution cycle started', array(
                'version' => AppInfo::version(),
                'dry_run' => $this->config->dryRun() ? 'true' : 'false',
                'force_update_on_no_change' => $this->config->forceUpdateOnNoChange() ? 'true' : 'false',
                'run_once' => $this->config->runOnce() ? 'true' : 'false',
                'targets' => $this->config->targetCount(),
            ));
            $this->validateStartup();
            $this->stateStore->ensureDirectoryExists();
            $this->debug('State directory ready', array(
                'state_dir' => $this->stateStore->directory(),
                'last_ipv4_path' => $this->stateStore->filePath('last_ipv4'),
                'last_ipv6_path' => $this->stateStore->filePath('last_ipv6'),
                'health_status_file' => $this->config->healthStatusFile(),
                'ip_status_log' => $this->config->ipStatusLog() !== '' ? $this->config->ipStatusLog() : 'disabled',
            ));
            $this->exitStage('configuration_validated');

            $lastIpv4 = $this->config->ipv4Enabled() ? $this->stateStore->get('last_ipv4') : null;
            $lastIpv6 = $this->config->ipv6Enabled() ? $this->stateStore->get('last_ipv6') : null;

            $this->enterStage(self::STAGE_PUBLIC_IP_DETECTION);
            $detectedIp = $this->detectPublicIpAddresses($lastIpv4, $lastIpv6);
            $ipv4 = $detectedIp['ipv4'];
            $ipv6 = $detectedIp['ipv6'];
            $this->exitStage('public_ip_detection_complete');

            $this->enterStage(self::STAGE_AUTH_SESSION);
            $this->logger->info('Starting DNS provider authentication/session preflight', array(
                'provider' => $this->provider->providerName(),
                'provider_interface' => $this->provider->interfaceName(),
                'auth_flow' => $this->provider->authModel(),
                'mutation_allowed' => $this->mutationAllowed() ? 'true' : 'false',
            ));
            $this->provider->open();
            $this->exitStage('session_established');

            $this->enterStage(self::STAGE_TARGET_VALIDATION);
            $inspection = $this->inspectTargets($ipv4 !== null, $ipv6 !== null);
            $this->exitStage('target_validated');

            $this->enterStage(self::STAGE_UPDATE_DECISION);
            $this->debug('Loaded previous state', array(
                'last_ipv4' => $lastIpv4 ?? 'none',
                'last_ipv4_path' => $this->stateStore->filePath('last_ipv4'),
                'last_ipv6' => $lastIpv6 ?? 'none',
                'last_ipv6_path' => $this->stateStore->filePath('last_ipv6'),
            ));

            $decisions = $this->buildTargetDecisions($inspection['records'], $ipv4, $ipv6);
            $this->logTargetDecisions($decisions, $ipv4, $ipv6);

            $updateRequired = $this->anyTargetRequiresUpdate($decisions);
            $publicIpChanged = $this->publicIpChanged($lastIpv4, $lastIpv6, $ipv4, $ipv6);
            $ipv4Status = $this->publicIpStatus($this->config->ipv4Enabled(), $lastIpv4, $ipv4);
            $ipv6Status = $this->publicIpStatus($this->config->ipv6Enabled(), $lastIpv6, $ipv6);
            $forceNoChangeUpdate = !$publicIpChanged && !$updateRequired && $this->config->forceUpdateOnNoChange();
            $this->debug('Update decision', array(
                'update_required' => $updateRequired ? 'true' : 'false',
                'public_ip_changed' => $publicIpChanged ? 'true' : 'false',
                'force_update_on_no_change' => $this->config->forceUpdateOnNoChange() ? 'true' : 'false',
                'ipv4_detected' => $ipv4 !== null ? 'true' : 'false',
                'ipv6_detected' => $ipv6 !== null ? 'true' : 'false',
                'targets' => count($decisions),
                'dry_run' => $this->config->dryRun() ? 'true' : 'false',
                'mutation_allowed' => $this->mutationAllowed() ? 'true' : 'false',
                'live_mutation_attempted' => $this->liveMutationAttempted ? 'true' : 'false',
            ));

            if (!$updateRequired && !$forceNoChangeUpdate) {
                $this->logger->info('Skipping live update because detected IP is unchanged and all targets are already in sync', array(
                    'public_ip_changed' => $publicIpChanged ? 'true' : 'false',
                    'force_update_on_no_change' => $this->config->forceUpdateOnNoChange() ? 'true' : 'false',
                    'targets_in_sync' => $this->countTargetsAlreadyInSync($decisions),
                    'targets_requiring_update' => '0',
                    'live_mutation_attempted' => 'false',
                ));
                $this->logger->info('Execution cycle completed; no target IP change detected', array(
                    'update_required' => 'false',
                    'targets' => count($decisions),
                    'reason' => 'all_targets_already_match_detected_ip',
                    'ipv4_detected' => $ipv4 !== null ? 'true' : 'false',
                    'ipv6_detected' => $ipv6 !== null ? 'true' : 'false',
                    'dry_run' => $this->config->dryRun() ? 'true' : 'false',
                    'mutation_allowed' => $this->mutationAllowed() ? 'true' : 'false',
                    'live_mutation_attempted' => 'false',
                ));
                if ($this->config->dryRun()) {
                    $this->logger->info('Dry-run completed; no live mutation sent', array(
                        'reason' => 'no_update_required',
                        'mutation_allowed' => 'false',
                        'live_mutation_attempted' => 'false',
                    ));
                }
                $this->persistDetectedState($ipv4, $ipv6, 'no_update_required');
                $this->notifyIpChangeIfNeeded($ipv4, $ipv6, $ipv4Status, $ipv6Status);
                $this->logRunSummary($lastIpv4, $lastIpv6, $ipv4, $ipv6, $decisions, 0, $publicIpChanged);
                $this->exitStage('no_update_required');
                return 0;
            }

            if (!$publicIpChanged && $this->config->forceUpdateOnNoChange()) {
                $this->logger->info('FORCE_UPDATE_ON_NO_CHANGE enabled; continuing with live update policy', array(
                    'force_update_on_no_change' => 'true',
                    'targets_requiring_update' => $this->countTargetsRequiringUpdate($decisions),
                    'targets_in_sync' => $this->countTargetsAlreadyInSync($decisions),
                ));
            }

            if (!$publicIpChanged && $this->countTargetsRequiringUpdate($decisions) > 0) {
                $count = $this->countTargetsRequiringUpdate($decisions);
                $this->logger->info(sprintf(
                    'Detected IP unchanged, but %d target%s %s out of sync and require%s update',
                    $count,
                    $count === 1 ? '' : 's',
                    $count === 1 ? 'is' : 'are',
                    $count === 1 ? 's' : ''
                ));
            }

            if ($this->config->dryRun()) {
                $this->logger->info('Dry-run completed; no live mutation sent', array(
                    'reason' => 'dry_run_enabled',
                    'mutation_allowed' => 'false',
                    'live_mutation_attempted' => 'false',
                    'targets_requiring_update' => $this->countTargetsRequiringUpdate($decisions),
                    'force_update_on_no_change' => $this->config->forceUpdateOnNoChange() ? 'true' : 'false',
                ));
                $this->notifyIpChangeIfNeeded($ipv4, $ipv6, $ipv4Status, $ipv6Status);
                $this->logRunSummary($lastIpv4, $lastIpv6, $ipv4, $ipv6, $decisions, 0, $publicIpChanged);
                $this->exitStage('dry_run_enabled');
                return 0;
            }

            $this->exitStage('update_required');
            $this->enterStage(self::STAGE_LIVE_MUTATION);
            $this->liveMutationAttempted = true;

            foreach ($inspection['zones'] as $domain => $zoneInspection) {
                $changedTargets = $this->selectedTargetsForZone($decisions, $domain, $forceNoChangeUpdate);
                if (empty($changedTargets)) {
                    continue;
                }

                $hostnames = array();
                foreach ($changedTargets as $target) {
                    $hostnames[] = $target->host();
                }

                $this->logger->info('Live DNS update attempted for zone targets', array(
                    'zone' => $domain,
                    'target_hosts' => $hostnames,
                    'mutation_allowed' => 'true',
                ));
                $this->provider->updateZoneRecords($zoneInspection['document'], $domain, $changedTargets, $ipv4, $ipv6);
            }

            $this->persistDetectedState($ipv4, $ipv6, 'live_update_successful');
            $this->notifyIpChangeIfNeeded($ipv4, $ipv6, $ipv4Status, $ipv6Status);
            $updatedTargets = $forceNoChangeUpdate ? count($decisions) : $this->countTargetsRequiringUpdate($decisions);

            $successContext = array(
                'targets_updated' => $updatedTargets,
            );
            if ($ipv4 !== null) {
                $successContext['ipv4'] = $ipv4;
            }
            if ($ipv6 !== null) {
                $successContext['ipv6'] = $ipv6;
            }

            $this->logger->info('Update successful', $successContext);
            $this->logRunSummary($lastIpv4, $lastIpv6, $ipv4, $ipv6, $decisions, $updatedTargets, $publicIpChanged);
            $this->exitStage('live_update_successful');
            return 0;
        } catch (Throwable $exception) {
            $this->logStageFailure($exception);
            $this->writeHealthStatus(false, array(
                'stage' => $this->currentStage,
                'error' => $exception->getMessage(),
                'live_mutation_attempted' => $this->liveMutationAttempted ? 'true' : 'false',
            ));
            return $resultCode;
        } finally {
            $this->cleanupSession();
        }
    }

    private function validateStartup(): void
    {
        $this->logger->info('Startup validation', array(
            'env_file' => $this->config->envFileExists() ? 'present' : 'missing',
            'config_source' => $this->config->envFileExists() ? '.env/environment' : 'environment',
            'dry_run' => $this->config->dryRun() ? 'true' : 'false',
            'debug' => $this->config->debug() ? 'true' : 'false',
            'force_update_on_no_change' => $this->config->forceUpdateOnNoChange() ? 'true' : 'false',
            'run_once' => $this->config->runOnce() ? 'true' : 'false',
            'ipv4_enabled' => $this->config->ipv4Enabled() ? 'true' : 'false',
            'ipv6_enabled' => $this->config->ipv6Enabled() ? 'true' : 'false',
            'mutation_allowed' => $this->mutationAllowed() ? 'true' : 'false',
        ));

        $this->logger->info('DNS provider configuration', array(
            'provider' => $this->provider->providerName(),
            'provider_interface' => $this->provider->interfaceName(),
            'api_host' => $this->config->host() !== '' ? 'present' : 'missing',
            'auth_flow' => $this->provider->authModel(),
            'session_create_uses_credentials' => 'true',
            'follow_up_auth' => $this->provider->authModel(),
            'username' => $this->config->user() !== '' ? 'present' : 'missing',
            'password' => $this->config->password() !== '' ? 'present' : 'missing',
            'context' => $this->config->context() !== '' ? 'present' : 'missing',
        ));

        $this->logger->info('Project DNS target configuration', array(
            'target_mode' => $this->config->multiTargetMode() ? 'multiple' : 'single',
            'target_count' => $this->config->targetCount(),
            'primary_target_host' => $this->config->targetHost() !== null ? $this->config->targetHost() : 'missing',
            'system_ns_optional' => $this->config->systemNs() !== '' ? 'present' : 'not_set',
        ));

        $this->logger->info('Pushover notification configuration', array(
            'pushover_enabled' => $this->pushover->enabled() ? 'true' : 'false',
            'location_prefix' => $this->pushover->enabled() ? $this->pushover->locationPrefix() : 'not_set',
            'app_key' => $this->config->pushoverAppKey() !== '' ? 'present' : 'missing',
            'user_key' => $this->config->pushoverUserKey() !== '' ? 'present' : 'missing',
        ));
        if ($this->config->deprecatedPushoverLocationPrefixFallbackUsed()) {
            $this->logger->warning('PUSHOVER_LOCATION_NAME is deprecated; use PUSHOVER_LOCATION_PREFIX instead');
        }

        $this->logger->info('Optional runtime settings', array(
            'configured' => $this->config->configuredOptionalSettings(),
        ));

        $this->config->validateProviderConfig();
        $this->logger->info('Configuration format validated', array(
            'dry_run_config_sufficient' => 'true',
        ));

        foreach ($this->config->targets() as $target) {
            $this->logger->info('Target host mapping', array(
                'full_host' => $target->host(),
                'domain' => $target->zone(),
                'zone' => $target->zone(),
                'subdomain' => $target->subdomain(),
                'target_zone_source' => $target->zoneSource(),
            ));
        }

        $this->debug('Selected API host', array(
            'host' => $this->config->host(),
            'provider' => $this->provider->providerName(),
            'provider_interface' => $this->provider->interfaceName(),
            'auth_flow' => $this->provider->authModel(),
        ));
    }

    private function detectPublicIpAddresses(?string $lastIpv4, ?string $lastIpv6): array
    {
        $this->logger->info('Starting public IP detection', array(
            'ipv4_enabled' => $this->config->ipv4Enabled() ? 'true' : 'false',
            'ipv6_enabled' => $this->config->ipv6Enabled() ? 'true' : 'false',
        ));

        $ipv4 = null;
        if ($this->config->ipv4Enabled()) {
            $ipv4 = $this->resolver->resolve('ipv4');
            if ($ipv4 === null) {
                $this->logger->warning('IPv4 detection failed');
            } elseif ($lastIpv4 === $ipv4) {
                $this->logger->info('Detected public IPv4 unchanged from previous run: ' . $ipv4, array(
                    'ipv4' => $ipv4,
                    'previous_ipv4' => $lastIpv4,
                    'public_ip_changed' => 'false',
                ));
            } else {
                $this->logger->info('Detected IPv4 address: ' . $ipv4, array('ipv4' => $ipv4));
                $this->logger->success('Public IPv4 detection completed', array('status' => 'changed_or_first_seen'));
            }
        } else {
            $this->logger->info('IPv4 detection disabled by configuration', array(
                'attempted' => 'false',
                'status' => 'disabled',
            ));
        }

        $ipv6 = null;
        if ($this->config->ipv6Enabled()) {
            $this->logger->info('IPv6 detection enabled by configuration', array('attempted' => 'true'));
            $ipv6 = $this->resolver->resolve('ipv6');
            if ($ipv6 === null) {
                $this->logger->warning('IPv6 detection enabled but no IPv6 address found');
            } elseif ($lastIpv6 === $ipv6) {
                $this->logger->info('Detected public IPv6 unchanged from previous run: ' . $ipv6, array(
                    'ipv6' => $ipv6,
                    'previous_ipv6' => $lastIpv6,
                    'public_ip_changed' => 'false',
                ));
            } else {
                $this->logger->info('Detected IPv6 address: ' . $ipv6, array('ipv6' => $ipv6));
                $this->logger->success('Public IPv6 detection completed', array('status' => 'changed_or_first_seen'));
            }
        } else {
            $this->logger->info('IPv6 detection disabled by configuration', array(
                'attempted' => 'false',
                'status' => 'disabled',
            ));
        }

        if ($ipv4 === null && $ipv6 === null) {
            $this->logger->error('No usable public IP address detected; aborting before authentication', array(
                'ipv4_detected' => 'false',
                'ipv6_detected' => 'false',
                'authentication_attempted' => 'false',
            ));
            throw new RuntimeException('No usable public IP address detected.');
        }

        if ($ipv4 === null && $ipv6 !== null) {
            $this->logger->warning('IPv4 detection failed but IPv6 is available', array('ipv6' => $ipv6));
        }

        if ($ipv4 !== null && $ipv6 === null && $this->config->ipv6Enabled()) {
            $this->logger->warning('IPv6 detection failed but IPv4 is available', array('ipv4' => $ipv4));
        }

        $this->logger->success('Public IP detection completed with at least one usable address', array(
            'ipv4_detected' => $ipv4 !== null ? 'true' : 'false',
            'ipv6_detected' => $ipv6 !== null ? 'true' : 'false',
        ));

        return array(
            'ipv4' => $ipv4,
            'ipv6' => $ipv6,
        );
    }

    private function inspectTargets(bool $requireIpv4, bool $requireIpv6): array
    {
        $targetsByZone = array();
        foreach ($this->config->targets() as $target) {
            if (!isset($targetsByZone[$target->zone()])) {
                $targetsByZone[$target->zone()] = array();
            }
            $targetsByZone[$target->zone()][] = $target;
        }

        $zones = array();
        $records = array();
        foreach ($targetsByZone as $domain => $targets) {
            $hostnames = array();
            foreach ($targets as $target) {
                $hostnames[] = $target->host();
            }

            $this->logger->info('Validating read-only DNS provider zone access', array(
                'provider' => $this->provider->providerName(),
                'provider_interface' => $this->provider->interfaceName(),
                'zone' => $domain,
                'target_hosts' => $hostnames,
                'auth_mode' => $this->provider->authModel(),
                'require_a_record' => $requireIpv4 ? 'true' : 'false',
                'require_aaaa_record' => $requireIpv6 ? 'true' : 'false',
                'mutation' => 'false',
            ));

            $inspection = $this->provider->inspectTargets($domain, $targets, $requireIpv4, $requireIpv6);
            $zones[$domain] = array(
                'document' => $inspection['zone_document'],
                'targets' => $targets,
            );
            foreach ($targets as $target) {
                $record = $inspection['records'][$target->host()];
                $records[$target->host()] = array(
                    'target' => $target,
                    'current_ipv4' => $record['ipv4'],
                    'current_ipv6' => $record['ipv6'],
                );
                $this->logger->info('Read-only DNS provider target validation successful', array(
                    'provider' => $this->provider->providerName(),
                    'provider_interface' => $this->provider->interfaceName(),
                    'target_host' => $target->host(),
                    'zone' => $target->zone(),
                    'subdomain' => $target->subdomain(),
                    'has_a_record' => $record['ipv4'] !== null ? 'true' : 'false',
                    'has_aaaa_record' => $record['ipv6'] !== null ? 'true' : 'false',
                ));
            }
        }

        return array(
            'zones' => $zones,
            'records' => $records,
        );
    }

    private function buildTargetDecisions(array $records, ?string $ipv4, ?string $ipv6): array
    {
        $decisions = array();
        foreach ($records as $host => $record) {
            $target = $record['target'];
            $ipv4NeedsUpdate = $this->config->ipv4Enabled() && $ipv4 !== null && $record['current_ipv4'] !== $ipv4;
            $ipv6NeedsUpdate = $this->config->ipv6Enabled() && $ipv6 !== null && $record['current_ipv6'] !== $ipv6;

            $decisions[$host] = array(
                'target' => $target,
                'current_ipv4' => $record['current_ipv4'],
                'current_ipv6' => $record['current_ipv6'],
                'ipv4_needs_update' => $ipv4NeedsUpdate,
                'ipv6_needs_update' => $ipv6NeedsUpdate,
                'update_required' => $ipv4NeedsUpdate || $ipv6NeedsUpdate,
            );
        }

        return $decisions;
    }

    private function logTargetDecisions(array $decisions, ?string $ipv4, ?string $ipv6): void
    {
        foreach ($decisions as $decision) {
            $target = $decision['target'];
            $context = array(
                'target_host' => $target->host(),
                'target_zone' => $target->zone(),
                'target_subdomain' => $target->subdomain(),
                'ipv4_needs_update' => $decision['ipv4_needs_update'] ? 'true' : 'false',
                'ipv6_needs_update' => $decision['ipv6_needs_update'] ? 'true' : 'false',
                'update_required' => $decision['update_required'] ? 'true' : 'false',
            );

            if ($decision['update_required']) {
                if ($decision['ipv4_needs_update'] && $ipv4 !== null) {
                    $context['ipv4'] = $ipv4;
                }
                if ($decision['ipv6_needs_update'] && $ipv6 !== null) {
                    $context['ipv6'] = $ipv6;
                }

                if ($this->config->dryRun()) {
                    $context['intended_action'] = 'would_update_dns_records';
                    $context['dry_run'] = 'true';
                    $context['mutation_allowed'] = 'false';
                    $this->logger->info('Dry-run would require target update, but mutation is disabled', $context);
                } else {
                    $this->logger->info('Target requires update', $context);
                }
                continue;
            }

            $this->logger->info('Target already in sync with detected public IP', $context);
        }
    }

    private function anyTargetRequiresUpdate(array $decisions): bool
    {
        foreach ($decisions as $decision) {
            if ($decision['update_required']) {
                return true;
            }
        }

        return false;
    }

    private function changedTargetsForZone(array $decisions, string $zone): array
    {
        $targets = array();
        foreach ($decisions as $decision) {
            /** @var TargetHost $target */
            $target = $decision['target'];
            if ($target->zone() === $zone && $decision['update_required']) {
                $targets[] = $target;
            }
        }

        return $targets;
    }

    private function selectedTargetsForZone(array $decisions, string $zone, bool $forceNoChangeUpdate): array
    {
        // Forced no-change mode sends the current values again for every target in the zone.
        if (!$forceNoChangeUpdate) {
            return $this->changedTargetsForZone($decisions, $zone);
        }

        $targets = array();
        foreach ($decisions as $decision) {
            /** @var TargetHost $target */
            $target = $decision['target'];
            if ($target->zone() === $zone) {
                $targets[] = $target;
            }
        }

        return $targets;
    }

    private function countTargetsRequiringUpdate(array $decisions): int
    {
        $count = 0;
        foreach ($decisions as $decision) {
            if ($decision['update_required']) {
                $count++;
            }
        }

        return $count;
    }

    private function countTargetsAlreadyInSync(array $decisions): int
    {
        return count($decisions) - $this->countTargetsRequiringUpdate($decisions);
    }

    private function notifyIpChangeIfNeeded(?string $ipv4, ?string $ipv6, string $ipv4Status, string $ipv6Status): void
    {
        // Notifications are only for real public IP changes, not target sync fixes.
        $ipv4Changed = in_array($ipv4Status, array('changed', 'first_seen'), true);
        $ipv6Changed = in_array($ipv6Status, array('changed', 'first_seen'), true);

        if (!$ipv4Changed && !$ipv6Changed) {
            $this->logger->info('Pushover notification skipped because no IP change occurred', array(
                'pushover_enabled' => $this->pushover->enabled() ? 'true' : 'false',
            ));
            return;
        }

        if ($this->config->dryRun()) {
            $this->logger->info('Pushover notification skipped because DRY_RUN is enabled', array(
                'pushover_enabled' => $this->pushover->enabled() ? 'true' : 'false',
            ));
            return;
        }

        try {
            $this->pushover->notifyIpChange($ipv4, $ipv6, $ipv4Changed, $ipv6Changed);
        } catch (Throwable $exception) {
            $this->logger->warning('Pushover notification failed but DNS update flow continued', array(
                'error' => $exception->getMessage(),
                'pushover_enabled' => $this->pushover->enabled() ? 'true' : 'false',
            ));
        }
    }

    private function persistDetectedState(?string $ipv4, ?string $ipv6, string $reason): void
    {
        if ($ipv4 === null && $ipv6 === null) {
            $this->debug('State persistence skipped because no usable public IP was detected', array(
                'reason' => $reason,
            ));
            return;
        }

        if ($ipv4 !== null) {
            $this->logger->info('Saving current IPv4 to state', array(
                'reason' => $reason,
                'state_file' => $this->stateStore->filePath('last_ipv4'),
                'ipv4' => $ipv4,
            ));
            try {
                $this->stateStore->put('last_ipv4', $ipv4);
                $this->logger->success('Current IPv4 state saved', array(
                    'state_file' => $this->stateStore->filePath('last_ipv4'),
                ));
            } catch (Throwable $exception) {
                $this->logger->error('Current IPv4 state save failed', array(
                    'state_file' => $this->stateStore->filePath('last_ipv4'),
                    'error' => $exception->getMessage(),
                ));
                throw $exception;
            }
        }

        if ($ipv6 !== null) {
            $this->logger->info('Saving current IPv6 to state', array(
                'reason' => $reason,
                'state_file' => $this->stateStore->filePath('last_ipv6'),
                'ipv6' => $ipv6,
            ));
            try {
                $this->stateStore->put('last_ipv6', $ipv6);
                $this->logger->success('Current IPv6 state saved', array(
                    'state_file' => $this->stateStore->filePath('last_ipv6'),
                ));
            } catch (Throwable $exception) {
                $this->logger->error('Current IPv6 state save failed', array(
                    'state_file' => $this->stateStore->filePath('last_ipv6'),
                    'error' => $exception->getMessage(),
                ));
                throw $exception;
            }
        }
    }

    private function logRunSummary(
        ?string $lastIpv4,
        ?string $lastIpv6,
        ?string $ipv4,
        ?string $ipv6,
        array $decisions,
        int $targetsUpdated,
        bool $publicIpChanged
    ): void {
        $summary = array(
            'public_ipv4_status' => $this->publicIpStatus($this->config->ipv4Enabled(), $lastIpv4, $ipv4),
            'public_ipv6_status' => $this->publicIpStatus($this->config->ipv6Enabled(), $lastIpv6, $ipv6),
            'public_ip_changed' => $publicIpChanged ? 'true' : 'false',
            'force_update_on_no_change' => $this->config->forceUpdateOnNoChange() ? 'true' : 'false',
            'targets_in_sync' => $this->countTargetsAlreadyInSync($decisions),
            'targets_requiring_update' => $this->countTargetsRequiringUpdate($decisions),
            'targets_updated' => $targetsUpdated,
            'live_mutation_attempted' => $this->liveMutationAttempted ? 'true' : 'false',
        );

        if ($ipv4 !== null) {
            $summary['ipv4'] = $ipv4;
        }
        if ($ipv6 !== null) {
            $summary['ipv6'] = $ipv6;
        }

        $this->logger->info('Execution cycle summary', $summary);
        $this->appendIpStatusLog($summary);
        $this->writeHealthStatus(true, $summary);
    }

    private function appendIpStatusLog(array $summary): void
    {
        if ($this->config->ipStatusLog() === '') {
            return;
        }

        $entry = array(
            'timestamp' => Clock::nowIso8601(),
            'ipv4' => $summary['ipv4'] ?? null,
            'ipv6' => $summary['ipv6'] ?? null,
            'ipv4_status' => $summary['public_ipv4_status'] ?? 'unknown',
            'ipv6_status' => $summary['public_ipv6_status'] ?? 'unknown',
            'public_ip_changed' => $this->jsonBoolean($summary['public_ip_changed'] ?? false),
        );

        try {
            $this->appendJsonLine($this->config->ipStatusLog(), $entry);
        } catch (Throwable $exception) {
            $this->logger->warning('IP status history log write failed', array(
                'ip_status_log' => $this->config->ipStatusLog(),
                'error' => $exception->getMessage(),
            ));
        }
    }

    private function writeHealthStatus(bool $success, array $context): void
    {
        $now = Clock::nowIso8601();
        $status = array_merge(array(
            'timestamp' => $now,
            'last_run_success' => $success,
            'last_success_at' => $success ? $now : null,
            'version' => AppInfo::version(),
        ), $this->normalizeHealthContext($context));

        try {
            $this->writeJsonFile($this->config->healthStatusFile(), $status);
        } catch (Throwable $exception) {
            $this->logger->warning('Health status file write failed', array(
                'health_status_file' => $this->config->healthStatusFile(),
                'error' => $exception->getMessage(),
            ));
        }
    }

    private function normalizeHealthContext(array $context): array
    {
        foreach (array(
            'public_ip_changed',
            'force_update_on_no_change',
            'live_mutation_attempted',
        ) as $key) {
            if (!isset($context[$key])) {
                continue;
            }

            if ($context[$key] === 'true') {
                $context[$key] = true;
            } elseif ($context[$key] === 'false') {
                $context[$key] = false;
            }
        }

        return $context;
    }

    private function jsonBoolean($value): bool
    {
        if ($value === true || $value === 'true' || $value === 1 || $value === '1') {
            return true;
        }

        return false;
    }

    private function appendJsonLine(string $path, array $entry): void
    {
        $this->ensureParentDirectory($path);
        $json = json_encode($entry, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Unable to encode IP status log entry.');
        }

        $written = file_put_contents($path, $json . PHP_EOL, FILE_APPEND | LOCK_EX);
        if ($written === false) {
            throw new RuntimeException(sprintf('Unable to write IP status log: %s', $path));
        }
    }

    private function writeJsonFile(string $path, array $data): void
    {
        $this->ensureParentDirectory($path);
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Unable to encode health status file.');
        }

        $written = file_put_contents($path, $json . PHP_EOL, LOCK_EX);
        if ($written === false) {
            throw new RuntimeException(sprintf('Unable to write health status file: %s', $path));
        }
    }

    private function ensureParentDirectory(string $path): void
    {
        $directory = dirname($path);
        if ($directory === '' || $directory === '.') {
            return;
        }

        if (is_dir($directory)) {
            return;
        }

        if (!mkdir($concurrentDirectory = $directory, 0775, true) && !is_dir($concurrentDirectory)) {
            throw new RuntimeException(sprintf('Unable to create directory: %s', $directory));
        }
    }

    private function publicIpStatus(bool $enabled, ?string $previous, ?string $current): string
    {
        if (!$enabled) {
            return 'disabled';
        }

        if ($current === null) {
            return 'not_detected';
        }

        if ($previous === null) {
            return 'first_seen';
        }

        return $previous === $current ? 'unchanged' : 'changed';
    }

    private function publicIpChanged(?string $lastIpv4, ?string $lastIpv6, ?string $ipv4, ?string $ipv6): bool
    {
        $ipv4Status = $this->publicIpStatus($this->config->ipv4Enabled(), $lastIpv4, $ipv4);
        $ipv6Status = $this->publicIpStatus($this->config->ipv6Enabled(), $lastIpv6, $ipv6);

        return in_array($ipv4Status, array('changed', 'first_seen'), true)
            || in_array($ipv6Status, array('changed', 'first_seen'), true);
    }

    private function cleanupSession(): void
    {
        if (!$this->provider->hasOpenSession()) {
            return;
        }

        $previousStage = $this->currentStage;
        $this->enterStage(self::STAGE_SESSION_CLEANUP);

        try {
            $this->provider->close();
            $this->exitStage('session_closed');
        } catch (Throwable $exception) {
            $context = array(
                'stage' => self::STAGE_SESSION_CLEANUP,
                'error' => $exception->getMessage(),
                'dry_run' => $this->config->dryRun() ? 'true' : 'false',
                'mutation_allowed' => $this->mutationAllowed() ? 'true' : 'false',
                'live_mutation_attempted' => $this->liveMutationAttempted ? 'true' : 'false',
            );
            $context = array_merge($context, $this->exceptionDiagnostics($exception));
            $this->logger->warning('DNS provider session cleanup failed; main result preserved', $context);
        }

        $this->currentStage = $previousStage;
        $this->provider->setStage($previousStage);
    }

    private function enterStage(string $stage): void
    {
        $this->currentStage = $stage;
        $this->provider->setStage($stage);
        $this->debug('Runtime stage entered', array(
            'stage' => $stage,
            'dry_run' => $this->config->dryRun() ? 'true' : 'false',
            'mutation_allowed' => $this->mutationAllowed() ? 'true' : 'false',
            'live_mutation_attempted' => $this->liveMutationAttempted ? 'true' : 'false',
        ));
    }

    private function exitStage(string $reason): void
    {
        $this->debug('Runtime stage exited', array(
            'stage' => $this->currentStage,
            'reason' => $reason,
            'dry_run' => $this->config->dryRun() ? 'true' : 'false',
            'mutation_allowed' => $this->mutationAllowed() ? 'true' : 'false',
            'live_mutation_attempted' => $this->liveMutationAttempted ? 'true' : 'false',
        ));
    }

    private function mutationAllowed(): bool
    {
        return !$this->config->dryRun();
    }

    private function logStageFailure(Throwable $exception): void
    {
        $context = array(
            'stage' => $this->currentStage,
            'error' => $exception->getMessage(),
            'dry_run' => $this->config->dryRun() ? 'true' : 'false',
            'mutation_allowed' => $this->mutationAllowed() ? 'true' : 'false',
            'session_established' => $this->provider->hasOpenSession() ? 'true' : 'false',
            'provider' => $this->provider->providerName(),
            'provider_interface' => $this->provider->interfaceName(),
            'live_mutation_attempted' => $this->liveMutationAttempted ? 'true' : 'false',
        );
        $context = array_merge($context, $this->exceptionDiagnostics($exception));

        if ($this->config->dryRun()) {
            $this->logger->error($this->dryRunFailureMessage(), $context);
            return;
        }

        if ($this->currentStage === self::STAGE_AUTH_SESSION) {
            $this->logger->error('DNS provider authentication/session creation failed', $context);
            return;
        }

        if ($this->currentStage === self::STAGE_TARGET_VALIDATION) {
            $this->logger->error('Read-only zone validation failed after successful session login', $context);
            return;
        }

        if ($this->currentStage === self::STAGE_LIVE_MUTATION || $this->liveMutationAttempted) {
            $this->logger->error('Live DNS update failed', $context);
            return;
        }

        $this->logger->error('Runtime failed before live DNS update', $context);
    }

    private function dryRunFailureMessage(): string
    {
        if ($this->currentStage === self::STAGE_AUTH_SESSION) {
            return 'Dry-run authentication preflight failed';
        }

        if ($this->currentStage === self::STAGE_TARGET_VALIDATION) {
            return 'Dry-run target validation failed';
        }

        if ($this->currentStage === self::STAGE_PUBLIC_IP_DETECTION) {
            return 'Dry-run public IP detection failed before authentication';
        }

        if ($this->currentStage === self::STAGE_UPDATE_DECISION) {
            return 'Dry-run matching failed';
        }

        return 'Dry-run preflight failed';
    }

    private function exceptionDiagnostics(Throwable $exception): array
    {
        if (!$exception instanceof DnsProviderException) {
            return array();
        }

        return $exception->diagnostics();
    }

    private function debug(string $message, array $context = array()): void
    {
        if (!$this->config->debug()) {
            return;
        }

        $this->logger->debug($message, $context);
    }
}
