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
            $this->validateStartup();
            $this->stateStore->ensureDirectoryExists();
            $this->exitStage('configuration_validated');

            $this->enterStage(self::STAGE_AUTH_SESSION);
            $this->logger->info('Starting InterNetX authentication/session preflight', array(
                'auth_flow' => 'auth_session',
                'session_create_task_code' => '1321001',
                'auth_variants' => $this->config->authVariants(),
                'mutation_allowed' => $this->mutationAllowed() ? 'true' : 'false',
            ));
            $this->createSessionWithConfiguredVariants();
            $this->exitStage('session_established');

            $this->enterStage(self::STAGE_TARGET_VALIDATION);
            $this->validateTargetAccess();
            $this->exitStage('target_validated');

            $this->enterStage(self::STAGE_PUBLIC_IP_DETECTION);
            $ipv4 = $this->resolver->resolve('ipv4');
            if ($ipv4 === null) {
                throw new RuntimeException('IPv4 detection is required but no IPv4 address was detected.');
            }
            $this->logger->info('Detected IPv4 address', array('ipv4' => $ipv4));

            $ipv6 = null;
            if ($this->config->ipv6Enabled()) {
                $this->logger->info('IPv6 detection enabled by configuration', array('attempted' => 'true'));
                $ipv6 = $this->resolver->resolve('ipv6');
                if ($ipv6 === null) {
                    $this->logger->warning('IPv6 detection enabled but no IPv6 address detected');
                } else {
                    $this->logger->info('Detected IPv6 address', array('ipv6' => $ipv6));
                }
            } else {
                $this->logger->info('IPv6 detection disabled by configuration', array('attempted' => 'false'));
            }
            $this->exitStage('public_ip_detection_complete');

            $this->enterStage(self::STAGE_UPDATE_DECISION);
            $lastIpv4 = $this->stateStore->get('last_ipv4');
            $lastIpv6 = $this->config->ipv6Enabled() ? $this->stateStore->get('last_ipv6') : null;
            $ipv4Changed = $lastIpv4 !== $ipv4;
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
                'dry_run' => $this->config->dryRun() ? 'true' : 'false',
                'mutation_allowed' => $this->mutationAllowed() ? 'true' : 'false',
                'live_mutation_attempted' => $this->liveMutationAttempted ? 'true' : 'false',
            ));

            if (!$updateRequired) {
                $context = array('ipv4' => $ipv4);
                if ($ipv6 !== null) {
                    $context['ipv6'] = $ipv6;
                }
                $context['update_required'] = 'false';
                $context['intended_action'] = 'none';
                $context['reason'] = 'stored_ip_matches_detected_ip';
                $context['dry_run'] = $this->config->dryRun() ? 'true' : 'false';
                $context['mutation_allowed'] = $this->mutationAllowed() ? 'true' : 'false';
                $context['live_mutation_attempted'] = 'false';
                $this->logger->info('IP unchanged', $context);
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
                'ipv4' => $ipv4,
                'ipv4_changed' => $ipv4Changed ? 'true' : 'false',
                'update_required' => 'true',
                'target_host' => $this->config->targetHost(),
                'target_zone' => $this->config->targetZone(),
                'target_subdomain' => $this->config->targetSubdomain(),
            );
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

            $this->stateStore->put('last_ipv4', $ipv4);
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
            'auth_variants' => $this->config->authVariants(),
            'configured_owner' => $this->config->hasConfiguredOwner() ? 'present' : 'not_set',
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
            'auth_variants' => $this->config->authVariants(),
            'configured_owner' => $this->config->hasConfiguredOwner() ? 'present' : 'not_set',
        ));
    }

    private function createSessionWithConfiguredVariants(): void
    {
        $lastException = null;
        $lastVariant = null;
        $testedVariants = array();

        foreach ($this->config->authVariants() as $variant) {
            $owner = $this->ownerForAuthVariant($variant);
            $testedVariants[] = $variant;
            $lastVariant = $variant;
            $this->logger->info('Testing InterNetX authentication variant', array(
                'auth_variant' => $variant,
                'owner_block_included' => $owner['included'] ? 'true' : 'false',
                'owner_source' => $owner['source'],
                'mutation' => 'false',
                'dry_run' => $this->config->dryRun() ? 'true' : 'false',
            ));

            try {
                $this->gateway->createSession($variant, $owner['user'], $owner['context']);
                return;
            } catch (Throwable $exception) {
                $lastException = $exception;
                $context = array(
                    'auth_variant' => $variant,
                    'owner_block_included' => $owner['included'] ? 'true' : 'false',
                    'owner_source' => $owner['source'],
                    'error' => $exception->getMessage(),
                    'session_established' => 'false',
                    'live_mutation_attempted' => $this->liveMutationAttempted ? 'true' : 'false',
                );
                $context = array_merge($context, $this->exceptionDiagnostics($exception));
                $this->logger->warning('InterNetX authentication variant failed', $context);
            }
        }

        $diagnostics = array(
            'operation' => 'AuthSessionCreate',
            'stage' => self::STAGE_AUTH_SESSION,
            'tested_auth_variants' => $testedVariants,
            'last_auth_variant' => $lastVariant,
            'session_established' => 'false',
        );
        if ($lastException !== null) {
            $diagnostics = array_merge($diagnostics, $this->exceptionDiagnostics($lastException));
        }

        throw new InterNetXApiException('InterNetX authentication/session creation failed for all configured variants.', $diagnostics);
    }

    private function ownerForAuthVariant(string $variant): array
    {
        if ($variant === 'owner_same') {
            return array(
                'included' => true,
                'source' => 'auth_user_context',
                'user' => $this->config->user(),
                'context' => $this->config->context(),
            );
        }

        if ($variant === 'owner_configured') {
            return array(
                'included' => true,
                'source' => 'INTERNETX_OWNER_USER/INTERNETX_OWNER_CONTEXT',
                'user' => $this->config->ownerUser(),
                'context' => $this->config->ownerContext(),
            );
        }

        return array(
            'included' => false,
            'source' => 'none',
            'user' => null,
            'context' => null,
        );
    }

    private function validateTargetAccess(): void
    {
        foreach ($this->config->domains() as $domain => $subdomains) {
            $this->logger->info('Validating read-only InterNetX zone access', array(
                'zone' => $domain,
                'subdomains' => $subdomains,
                'auth_mode' => 'auth_session',
                'mutation' => 'false',
            ));
            $this->gateway->validateTargetAccess($domain, $subdomains, $this->config->ipv6Enabled());
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
            return 'Dry-run public IP detection failed';
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
