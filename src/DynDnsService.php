<?php

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
    private string $currentStage = self::STAGE_STARTUP_CONFIG;
    private bool $liveMutationAttempted = false;

    public function __construct(
        Config $config,
        Logger $logger,
        StateStore $stateStore,
        PublicIpResolver $resolver,
        XmlGatewayClient $gateway
    ) {
        $this->config = $config;
        $this->logger = $logger;
        $this->stateStore = $stateStore;
        $this->resolver = $resolver;
        $this->gateway = $gateway;
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
                'run_once' => $this->config->runOnce() ? 'true' : 'false',
            ));
            $this->validateStartup();
            $this->stateStore->ensureDirectoryExists();
            $this->exitStage('configuration_validated');

            $this->enterStage(self::STAGE_PUBLIC_IP_DETECTION);
            $detectedIp = $this->detectPublicIpAddresses();
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
            $this->validateTargetAccess($ipv4 !== null, $ipv6 !== null);
            $this->exitStage('target_validated');

            $this->enterStage(self::STAGE_UPDATE_DECISION);
            $lastIpv4 = $this->config->ipv4Enabled() ? $this->stateStore->get('last_ipv4') : null;
            $lastIpv6 = $this->config->ipv6Enabled() ? $this->stateStore->get('last_ipv6') : null;
            $ipv4Changed = $this->config->ipv4Enabled() && $ipv4 !== null ? $lastIpv4 !== $ipv4 : false;
            $ipv6Changed = $this->config->ipv6Enabled() && $ipv6 !== null ? $lastIpv6 !== $ipv6 : false;
            $updateRequired = $ipv4Changed || $ipv6Changed;

            $this->debug('Loaded previous state', array(
                'last_ipv4' => $lastIpv4 ?? 'none',
                'last_ipv6' => $lastIpv6 ?? 'none',
            ));
            $this->debug('Update decision', array(
                'update_required' => $updateRequired ? 'true' : 'false',
                'ipv4_changed' => $ipv4Changed ? 'true' : 'false',
                'ipv6_changed' => $ipv6Changed ? 'true' : 'false',
                'ipv4_detected' => $ipv4 !== null ? 'true' : 'false',
                'ipv6_detected' => $ipv6 !== null ? 'true' : 'false',
                'dry_run' => $this->config->dryRun() ? 'true' : 'false',
                'mutation_allowed' => $this->mutationAllowed() ? 'true' : 'false',
                'live_mutation_attempted' => $this->liveMutationAttempted ? 'true' : 'false',
            ));

            if (!$updateRequired) {
                $this->logger->info('Execution cycle completed; no IP change detected', array(
                    'update_required' => 'false',
                    'intended_action' => 'none',
                    'reason' => 'stored_ip_matches_detected_ip',
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
                $this->exitStage('no_update_required');
                $resultCode = 0;
                return $resultCode;
            }

            $context = array(
                'ipv4_changed' => $ipv4Changed ? 'true' : 'false',
                'update_required' => 'true',
                'target_host' => $this->config->targetHost(),
                'target_zone' => $this->config->targetZone(),
                'target_subdomain' => $this->config->targetSubdomain(),
            );
            if ($ipv4 !== null) {
                $context['ipv4'] = $ipv4;
            }
            if ($ipv6 !== null) {
                $context['ipv6'] = $ipv6;
                $context['ipv6_changed'] = $ipv6Changed ? 'true' : 'false';
            }

            if ($this->config->dryRun()) {
                $context['intended_action'] = 'would_update_dns_records';
                $context['dry_run'] = 'true';
                $context['mutation_allowed'] = 'false';
                $context['live_mutation_attempted'] = 'false';
                $this->logger->info('Dry-run would require update, but mutation is disabled', $context);
                $this->logger->info('Dry-run completed; no live mutation sent', array(
                    'reason' => 'dry_run_enabled',
                    'mutation_allowed' => 'false',
                    'live_mutation_attempted' => 'false',
                ));
                $this->exitStage('dry_run_enabled');
                $resultCode = 0;
                return $resultCode;
            }

            $this->exitStage('update_required');
            $this->enterStage(self::STAGE_LIVE_MUTATION);
            $this->liveMutationAttempted = true;
            $context['mutation_allowed'] = 'true';
            $this->logger->info('Live DNS update attempted', $context);
            foreach ($this->config->domains() as $domain => $subdomains) {
                $this->gateway->updateZoneRecords($domain, $subdomains, $ipv4, $ipv6);
            }

            if ($ipv4 !== null) {
                $this->stateStore->put('last_ipv4', $ipv4);
            }
            if ($ipv6 !== null) {
                $this->stateStore->put('last_ipv6', $ipv6);
            }

            $successContext = array('ipv4' => $ipv4);
            if ($ipv6 !== null) {
                $successContext['ipv6'] = $ipv6;
            }

            $this->logger->info('Update successful', $successContext);
            $this->exitStage('live_update_successful');
            $resultCode = 0;
            return $resultCode;
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
            'target_host' => $this->config->targetHost() !== null ? 'present' : 'missing',
            'system_ns_optional' => $this->config->systemNs() !== '' ? 'present' : 'not_set',
        ));

        $this->logger->info('Optional runtime settings', array(
            'configured' => $this->config->configuredOptionalSettings(),
        ));

        $this->config->validateForGateway();
        $this->logger->info('Configuration format validated', array(
            'dry_run_config_sufficient' => 'true',
        ));

        $this->logger->info('Target host mapping', array(
            'full_host' => $this->config->targetHost(),
            'domain' => $this->config->targetZone(),
            'zone' => $this->config->targetZone(),
            'subdomain' => $this->config->targetSubdomain(),
            'target_zone_source' => $this->config->targetZoneExplicit() ? 'explicit' : 'inferred_last_two_labels',
        ));

        $this->debug('Selected API host', array(
            'host' => $this->config->host(),
            'auth_flow' => 'auth_session',
        ));
    }

    private function detectPublicIpAddresses(): array
    {
        $this->logger->info('Starting public IP detection', array(
            'ipv4_enabled' => $this->config->ipv4Enabled() ? 'true' : 'false',
            'ipv6_enabled' => $this->config->ipv6Enabled() ? 'true' : 'false',
        ));

        $lastIpv4 = $this->stateStore->get('last_ipv4');
        $lastIpv6 = $this->stateStore->get('last_ipv6');

        $ipv4 = null;
        if ($this->config->ipv4Enabled()) {
            $ipv4 = $this->resolver->resolve('ipv4');
            if ($ipv4 === null) {
                $this->logger->warning('IPv4 detection failed');
            } elseif ($lastIpv4 === $ipv4) {
                $this->logger->success('IPv4 unchanged');
                $this->debug('Detected IPv4 address', array('ipv4' => $ipv4));
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
                $this->logger->success('IPv6 unchanged');
                $this->debug('Detected IPv6 address', array('ipv6' => $ipv6));
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

    private function validateTargetAccess(bool $requireIpv4, bool $requireIpv6): void
    {
        foreach ($this->config->domains() as $domain => $subdomains) {
            $this->logger->info('Validating read-only InterNetX zone access', array(
                'zone' => $domain,
                'subdomains' => $subdomains,
                'auth_mode' => 'auth_session',
                'require_a_record' => $requireIpv4 ? 'true' : 'false',
                'require_aaaa_record' => $requireIpv6 ? 'true' : 'false',
                'mutation' => 'false',
            ));
            $this->gateway->validateTargetAccess($domain, $subdomains, $requireIpv4, $requireIpv6);
        }

        $this->logger->info('Read-only InterNetX zone access validation successful', array(
            'target_host' => $this->config->targetHost(),
        ));
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
