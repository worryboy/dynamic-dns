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
    private XmlGatewayClient $gateway;
    private PushoverNotifier $pushover;
    private string $currentStage = self::STAGE_STARTUP_CONFIG;
    private bool $liveMutationAttempted = false;

    public function __construct(
        Config $config,
        Logger $logger,
        StateStore $stateStore,
        PublicIpResolver $resolver,
        XmlGatewayClient $gateway,
        PushoverNotifier $pushover
    ) {
        $this->config = $config;
        $this->logger = $logger;
        $this->stateStore = $stateStore;
        $this->resolver = $resolver;
        $this->gateway = $gateway;
        $this->pushover = $pushover;
        $this->gateway->setStage($this->currentStage);
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
            $this->exitStage('configuration_validated');

            $lastIpv4 = $this->config->ipv4Enabled() ? $this->stateStore->get('last_ipv4') : null;
            $lastIpv6 = $this->config->ipv6Enabled() ? $this->stateStore->get('last_ipv6') : null;

            $this->enterStage(self::STAGE_PUBLIC_IP_DETECTION);
            $detectedIp = $this->detectPublicIpAddresses($lastIpv4, $lastIpv6);
            $ipv4 = $detectedIp['ipv4'];
            $ipv6 = $detectedIp['ipv6'];
            $this->exitStage('public_ip_detection_complete');

            $this->enterStage(self::STAGE_AUTH_SESSION);
            $this->logger->info('Starting InterNetX authentication/session preflight', array(
                'auth_flow' => 'auth_session',
                'session_create_task_code' => '1321001',
                'mutation_allowed' => $this->mutationAllowed() ? 'true' : 'false',
            ));
            $this->gateway->createSession();
            $this->exitStage('session_established');

            $this->enterStage(self::STAGE_TARGET_VALIDATION);
            $inspection = $this->inspectTargets($ipv4 !== null, $ipv6 !== null);
            $this->exitStage('target_validated');

            $this->enterStage(self::STAGE_UPDATE_DECISION);
            $this->debug('Loaded previous state', array(
                'last_ipv4' => $lastIpv4 ?? 'none',
                'last_ipv6' => $lastIpv6 ?? 'none',
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
                $this->gateway->updateZoneRecords($zoneInspection['document'], $domain, $changedTargets, $ipv4, $ipv6);
            }

            if ($ipv4 !== null) {
                $this->stateStore->put('last_ipv4', $ipv4);
            }
            if ($ipv6 !== null) {
                $this->stateStore->put('last_ipv6', $ipv6);
            }
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

        $this->logger->info('XML authentication configuration', array(
            'api_host' => $this->config->host() !== '' ? 'present' : 'missing',
            'auth_flow' => 'auth_session',
            'session_create_uses_credentials' => 'true',
            'follow_up_auth' => 'auth_session',
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

        $this->config->validateForGateway();
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
            'auth_flow' => 'auth_session',
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

            $this->logger->info('Validating read-only InterNetX zone access', array(
                'zone' => $domain,
                'target_hosts' => $hostnames,
                'auth_mode' => 'auth_session',
                'require_a_record' => $requireIpv4 ? 'true' : 'false',
                'require_aaaa_record' => $requireIpv6 ? 'true' : 'false',
                'mutation' => 'false',
            ));

            $inspection = $this->gateway->inspectTargets($domain, $targets, $requireIpv4, $requireIpv6);
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
                $this->logger->info('Read-only InterNetX target validation successful', array(
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
        if (!$this->gateway->hasSession()) {
            return;
        }

        $previousStage = $this->currentStage;
        $this->enterStage(self::STAGE_SESSION_CLEANUP);

        try {
            $this->gateway->closeSession();
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
            $this->logger->warning('InterNetX session cleanup failed; main result preserved', $context);
        }

        $this->currentStage = $previousStage;
        $this->gateway->setStage($previousStage);
    }

    private function enterStage(string $stage): void
    {
        $this->currentStage = $stage;
        $this->gateway->setStage($stage);
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
            'session_established' => $this->gateway->hasSession() ? 'true' : 'false',
            'live_mutation_attempted' => $this->liveMutationAttempted ? 'true' : 'false',
        );
        $context = array_merge($context, $this->exceptionDiagnostics($exception));

        if ($this->config->dryRun()) {
            $this->logger->error($this->dryRunFailureMessage(), $context);
            return;
        }

        if ($this->currentStage === self::STAGE_AUTH_SESSION) {
            $this->logger->error('InterNetX authentication/session creation failed', $context);
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
        if (!$exception instanceof InterNetXApiException) {
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
