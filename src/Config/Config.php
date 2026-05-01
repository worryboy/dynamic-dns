<?php

/**
 * Loads runtime config from the environment and keeps target parsing in one place.
 * It does not talk to the API or decide whether an update should happen.
 */
final class Config
{
    private string $host;
    private string $user;
    private string $password;
    private string $context;
    private string $systemNs;
    private string $pushoverAppKey;
    private string $pushoverUserKey;
    private string $pushoverLocationPrefix;
    private bool $deprecatedPushoverLocationPrefixFallbackUsed;
    private array $targets;
    private array $domains;
    private array $ipv4Providers;
    private array $ipv6Providers;
    private bool $ipv4Enabled;
    private bool $ipv6Enabled;
    private bool $dryRun;
    private bool $forceUpdateOnNoChange;
    private bool $debug;
    private bool $runOnce;
    private int $connectTimeout;
    private int $requestTimeout;
    private int $checkInterval;
    private string $stateDir;
    private string $ipStatusLog;
    private string $healthStatusFile;
    private string $logTarget;
    private string $projectRoot;
    private bool $envFileExists;

    private function __construct(array $data)
    {
        $this->host = $data['host'];
        $this->user = $data['user'];
        $this->password = $data['password'];
        $this->context = $data['context'];
        $this->systemNs = $data['system_ns'];
        $this->pushoverAppKey = $data['pushover_app_key'];
        $this->pushoverUserKey = $data['pushover_user_key'];
        $this->pushoverLocationPrefix = $data['pushover_location_prefix'];
        $this->deprecatedPushoverLocationPrefixFallbackUsed = $data['deprecated_pushover_location_prefix_fallback_used'];
        $this->targets = $data['targets'];
        $this->domains = $data['domains'];
        $this->ipv4Providers = $data['ipv4_providers'];
        $this->ipv6Providers = $data['ipv6_providers'];
        $this->ipv4Enabled = $data['ipv4_enabled'];
        $this->ipv6Enabled = $data['ipv6_enabled'];
        $this->dryRun = $data['dry_run'];
        $this->forceUpdateOnNoChange = $data['force_update_on_no_change'];
        $this->debug = $data['debug'];
        $this->runOnce = $data['run_once'];
        $this->connectTimeout = $data['connect_timeout'];
        $this->requestTimeout = $data['request_timeout'];
        $this->checkInterval = $data['check_interval'];
        $this->stateDir = $data['state_dir'];
        $this->ipStatusLog = $data['ip_status_log'];
        $this->healthStatusFile = $data['health_status_file'];
        $this->logTarget = $data['log_target'];
        $this->projectRoot = $data['project_root'];
        $this->envFileExists = $data['env_file_exists'];
    }

    public static function load(string $projectRoot): self
    {
        $projectRoot = rtrim($projectRoot, DIRECTORY_SEPARATOR);
        self::loadEnvFile($projectRoot);

        $targets = self::loadTargets();
        if (empty($targets)) {
            throw new RuntimeException('No target configuration found. Set TARGET_HOST or TARGET_HOSTS.');
        }

        $ipv4Providers = self::parseCsv(self::env('PUBLIC_IPV4_PROVIDERS'));
        if (empty($ipv4Providers)) {
            $ipv4Providers = array(
                'https://api.ipify.org',
                'https://ipv4.icanhazip.com',
                'https://ifconfig.me/ip',
            );
        }

        $ipv6Providers = self::parseCsv(self::env('PUBLIC_IPV6_PROVIDERS'));
        if (empty($ipv6Providers)) {
            $ipv6Providers = array(
                'https://api64.ipify.org',
                'https://ipv6.icanhazip.com',
            );
        }

        $pushoverLocationPrefix = (string) self::env('PUSHOVER_LOCATION_PREFIX', '');
        $legacyPushoverLocationPrefix = (string) self::env('PUSHOVER_LOCATION_NAME', '');
        $deprecatedPushoverLocationPrefixFallbackUsed = $pushoverLocationPrefix === '' && $legacyPushoverLocationPrefix !== '';
        if ($deprecatedPushoverLocationPrefixFallbackUsed) {
            $pushoverLocationPrefix = $legacyPushoverLocationPrefix;
        }

        $stateDir = self::resolvePath($projectRoot, self::env('STATE_DIR', 'state'));
        $ipStatusLog = self::normalizeOptional(self::env('IP_STATUS_LOG'));
        $healthStatusFile = self::normalizeOptional(self::env('HEALTH_STATUS_FILE'));

        return new self(array(
            'host' => self::env('INTERNETX_HOST', 'https://gateway.autodns.com'),
            'user' => self::env('INTERNETX_USER', ''),
            'password' => self::env('INTERNETX_PASSWORD', ''),
            'context' => (string) self::env('INTERNETX_CONTEXT', '9'),
            'system_ns' => (string) self::env('INTERNETX_SYSTEM_NS', ''),
            'pushover_app_key' => (string) self::env('PUSHOVER_APP_KEY', ''),
            'pushover_user_key' => (string) self::env('PUSHOVER_USER_KEY', ''),
            'pushover_location_prefix' => $pushoverLocationPrefix,
            'deprecated_pushover_location_prefix_fallback_used' => $deprecatedPushoverLocationPrefixFallbackUsed,
            'targets' => $targets,
            'domains' => self::groupTargetsByZone($targets),
            'ipv4_providers' => $ipv4Providers,
            'ipv6_providers' => $ipv6Providers,
            'ipv4_enabled' => self::boolEnv(self::env('ENABLE_IPV4', 'true')),
            'ipv6_enabled' => self::boolEnv(self::env('ENABLE_IPV6', 'false')),
            'dry_run' => self::boolEnv(self::env('DRY_RUN', 'false')),
            'force_update_on_no_change' => self::boolEnv(self::env('FORCE_UPDATE_ON_NO_CHANGE', 'false')),
            'debug' => self::boolEnv(self::env('DEBUG', 'false')),
            'run_once' => self::boolEnv(self::env('RUN_ONCE', 'false')),
            'connect_timeout' => self::positiveInt(self::env('HTTP_CONNECT_TIMEOUT', '10'), 10),
            'request_timeout' => self::positiveInt(self::env('HTTP_REQUEST_TIMEOUT', '20'), 20),
            'check_interval' => self::positiveInt(self::env('CHECK_INTERVAL_SECONDS', '300'), 300),
            'state_dir' => $stateDir,
            'ip_status_log' => $ipStatusLog === null ? '' : self::resolvePath($projectRoot, $ipStatusLog),
            'health_status_file' => $healthStatusFile === null ? $stateDir . DIRECTORY_SEPARATOR . 'health.json' : self::resolvePath($projectRoot, $healthStatusFile),
            'log_target' => self::env('LOG_TARGET', 'php://stdout'),
            'project_root' => $projectRoot,
            'env_file_exists' => is_file($projectRoot . DIRECTORY_SEPARATOR . '.env'),
        ));
    }

    public function host(): string
    {
        return $this->host;
    }

    public function user(): string
    {
        return $this->user;
    }

    public function password(): string
    {
        return $this->password;
    }

    public function context(): string
    {
        return $this->context;
    }

    public function systemNs(): string
    {
        return $this->systemNs;
    }

    public function pushoverAppKey(): string
    {
        return $this->pushoverAppKey;
    }

    public function pushoverUserKey(): string
    {
        return $this->pushoverUserKey;
    }

    public function pushoverLocationPrefix(): string
    {
        return $this->pushoverLocationPrefix;
    }

    public function deprecatedPushoverLocationPrefixFallbackUsed(): bool
    {
        return $this->deprecatedPushoverLocationPrefixFallbackUsed;
    }

    public function xmlGetZone(): string
    {
        return $this->projectRoot . DIRECTORY_SEPARATOR . 'src'
            . DIRECTORY_SEPARATOR . 'Provider'
            . DIRECTORY_SEPARATOR . 'InterNetX'
            . DIRECTORY_SEPARATOR . 'templates'
            . DIRECTORY_SEPARATOR . 'zone-info.xml';
    }

    public function xmlPutZone(): string
    {
        return $this->projectRoot . DIRECTORY_SEPARATOR . 'src'
            . DIRECTORY_SEPARATOR . 'Provider'
            . DIRECTORY_SEPARATOR . 'InterNetX'
            . DIRECTORY_SEPARATOR . 'templates'
            . DIRECTORY_SEPARATOR . 'zone-update.xml';
    }

    public function stateDir(): string
    {
        return $this->stateDir;
    }

    public function ipStatusLog(): string
    {
        return $this->ipStatusLog;
    }

    public function healthStatusFile(): string
    {
        return $this->healthStatusFile;
    }

    public function logTarget(): string
    {
        return $this->logTarget;
    }

    public function projectRoot(): string
    {
        return $this->projectRoot;
    }

    public function envFileExists(): bool
    {
        return $this->envFileExists;
    }

    public function targetHost(): ?string
    {
        $target = $this->primaryTarget();
        return $target === null ? null : $target->host();
    }

    public function targetZone(): ?string
    {
        $target = $this->primaryTarget();
        return $target === null ? null : $target->zone();
    }

    public function targetSubdomain(): ?string
    {
        $target = $this->primaryTarget();
        return $target === null ? null : $target->subdomain();
    }

    public function targetZoneExplicit(): bool
    {
        $target = $this->primaryTarget();
        return $target !== null && $target->zoneSource() === 'explicit';
    }

    public function targetCount(): int
    {
        return count($this->targets);
    }

    public function multiTargetMode(): bool
    {
        return $this->targetCount() > 1;
    }

    public function targets(): array
    {
        return $this->targets;
    }

    public function configuredOptionalSettings(): array
    {
        return $this->configuredNames(array(
            'TARGET_HOSTS',
            'TARGET_ZONE',
            'TARGET_HOST_ZONES',
            'PUSHOVER_APP_KEY',
            'PUSHOVER_USER_KEY',
            'PUSHOVER_LOCATION_PREFIX',
            'PUSHOVER_LOCATION_NAME',
            'DRY_RUN',
            'FORCE_UPDATE_ON_NO_CHANGE',
            'DEBUG',
            'RUN_ONCE',
            'INTERNETX_SYSTEM_NS',
            'STATE_DIR',
            'IP_STATUS_LOG',
            'HEALTH_STATUS_FILE',
            'HEALTH_MAX_AGE_SECONDS',
            'CHECK_INTERVAL_SECONDS',
            'ENABLE_IPV4',
            'ENABLE_IPV6',
            'PUBLIC_IPV4_PROVIDERS',
            'PUBLIC_IPV6_PROVIDERS',
            'HTTP_CONNECT_TIMEOUT',
            'HTTP_REQUEST_TIMEOUT',
            'LOG_TARGET',
        ));
    }

    public function domains(): array
    {
        return $this->domains;
    }

    public function ipv4Providers(): array
    {
        return $this->ipv4Providers;
    }

    public function ipv6Providers(): array
    {
        return $this->ipv6Providers;
    }

    public function ipv4Enabled(): bool
    {
        return $this->ipv4Enabled;
    }

    public function ipv6Enabled(): bool
    {
        return $this->ipv6Enabled;
    }

    public function dryRun(): bool
    {
        return $this->dryRun;
    }

    public function debug(): bool
    {
        return $this->debug;
    }

    public function forceUpdateOnNoChange(): bool
    {
        return $this->forceUpdateOnNoChange;
    }

    public function runOnce(): bool
    {
        return $this->runOnce;
    }

    public function connectTimeout(): int
    {
        return $this->connectTimeout;
    }

    public function requestTimeout(): int
    {
        return $this->requestTimeout;
    }

    public function checkInterval(): int
    {
        return $this->checkInterval;
    }

    public function validateProviderConfig(): void
    {
        $required = array(
            'INTERNETX_HOST' => $this->host,
            'INTERNETX_USER' => $this->user,
            'INTERNETX_PASSWORD' => $this->password,
            'INTERNETX_CONTEXT' => $this->context,
        );

        foreach ($required as $name => $value) {
            if ($value === '') {
                throw new RuntimeException(sprintf('Missing required configuration: %s', $name));
            }
        }

        if (empty($this->targets)) {
            throw new RuntimeException('Missing required target configuration: set TARGET_HOST or TARGET_HOSTS.');
        }

        $hostParts = parse_url($this->host);
        if (!is_array($hostParts) || empty($hostParts['scheme']) || empty($hostParts['host'])) {
            throw new RuntimeException('INTERNETX_HOST must be a plausible absolute URL.');
        }

        if (!$this->ipv4Enabled && !$this->ipv6Enabled) {
            throw new RuntimeException('At least one public IP family must be enabled. Set ENABLE_IPV4=true or ENABLE_IPV6=true.');
        }

        foreach ($this->targets as $target) {
            self::assertValidDomainName($target->zone(), 'target zone');
            self::assertValidDomainName($target->host(), 'target host');
        }
    }

    private function primaryTarget(): ?TargetHost
    {
        if (empty($this->targets)) {
            return null;
        }

        return $this->targets[0];
    }

    private static function loadTargets(): array
    {
        $targetHost = self::normalizeOptional(self::env('TARGET_HOST'));
        $targetHosts = self::parseCsv(self::env('TARGET_HOSTS'));
        $targetZone = self::normalizeOptional(self::env('TARGET_ZONE'));
        $targetHostZones = self::parseTargetHostZones(self::env('TARGET_HOST_ZONES'));

        if ($targetHost !== null && !empty($targetHosts)) {
            throw new RuntimeException('Use either TARGET_HOST or TARGET_HOSTS, not both.');
        }

        if ($targetHost === null && empty($targetHosts)) {
            return array();
        }

        if (!empty($targetHosts) && $targetZone !== null) {
            throw new RuntimeException('TARGET_ZONE is only supported in single-target mode. Remove TARGET_ZONE or use TARGET_HOST.');
        }

        if ($targetHost !== null && !empty($targetHostZones)) {
            throw new RuntimeException('TARGET_HOST_ZONES is only supported with TARGET_HOSTS multi-target mode.');
        }

        $targets = array();
        if ($targetHost !== null) {
            $targets[] = self::targetFromHost($targetHost, $targetZone);
        } else {
            foreach ($targetHosts as $host) {
                $normalizedHost = strtolower(rtrim(trim($host), '.'));
                $targets[] = self::targetFromHost(
                    $host,
                    $targetHostZones[$normalizedHost] ?? null
                );
            }

            self::assertNoUnusedTargetHostZones($targetHosts, $targetHostZones);
        }

        return self::deduplicateTargets($targets);
    }

    private static function targetFromHost(string $targetHost, ?string $targetZone): TargetHost
    {
        $host = strtolower(rtrim(trim($targetHost), '.'));
        self::assertValidDomainName($host, 'TARGET_HOST');

        if ($targetZone === null) {
            $labels = explode('.', $host);
            if (count($labels) < 3) {
                throw new RuntimeException('TARGET_HOST must include a host label and a zone, for example subleveldomain.domain.com.');
            }

            if (self::requiresExplicitZoneHint($labels)) {
                throw new RuntimeException(sprintf(
                    'Ambiguous zone inference for target host %s. Set TARGET_ZONE for single-target mode or TARGET_HOST_ZONES=%s=<zone> for multi-target mode.',
                    $host,
                    $host
                ));
            }

            $targetZone = implode('.', array_slice($labels, -2));
            $zoneSource = 'inferred_last_two_labels';
        } else {
            $zoneSource = 'explicit';
        }

        $zone = strtolower(rtrim(trim($targetZone), '.'));
        self::assertValidDomainName($zone, 'TARGET_ZONE');

        if ($host === $zone) {
            $subdomain = '@';
        } else {
            $suffix = '.' . $zone;
            if (substr($host, -strlen($suffix)) !== $suffix) {
                throw new RuntimeException('TARGET_HOST must be inside TARGET_ZONE.');
            }
            $subdomain = substr($host, 0, -strlen($suffix));
        }

        if ($subdomain === '') {
            throw new RuntimeException('TARGET_HOST did not produce a valid subdomain label.');
        }

        return new TargetHost($host, $zone, $subdomain, $zoneSource);
    }

    private static function deduplicateTargets(array $targets): array
    {
        $unique = array();
        foreach ($targets as $target) {
            $unique[$target->host()] = $target;
        }

        return array_values($unique);
    }

    private static function groupTargetsByZone(array $targets): array
    {
        $domains = array();
        foreach ($targets as $target) {
            if (!isset($domains[$target->zone()])) {
                $domains[$target->zone()] = array();
            }

            if (!in_array($target->subdomain(), $domains[$target->zone()], true)) {
                $domains[$target->zone()][] = $target->subdomain();
            }
        }

        return $domains;
    }

    private static function parseCsv(?string $value): array
    {
        if ($value === null) {
            return array();
        }

        $parts = array_map('trim', explode(',', $value));
        return array_values(array_filter($parts, static function ($item) {
            return $item !== '';
        }));
    }

    private static function parseTargetHostZones(?string $value): array
    {
        $mappings = array();
        foreach (self::parseCsv($value) as $entry) {
            $separator = strpos($entry, '=');
            if ($separator === false) {
                throw new RuntimeException('TARGET_HOST_ZONES entries must use host=zone format.');
            }

            $host = strtolower(rtrim(trim(substr($entry, 0, $separator)), '.'));
            $zone = strtolower(rtrim(trim(substr($entry, $separator + 1)), '.'));
            if ($host === '' || $zone === '') {
                throw new RuntimeException('TARGET_HOST_ZONES entries must use non-empty host=zone format.');
            }

            self::assertValidDomainName($host, 'TARGET_HOST_ZONES host');
            self::assertValidDomainName($zone, 'TARGET_HOST_ZONES zone');
            $mappings[$host] = $zone;
        }

        return $mappings;
    }

    private static function assertNoUnusedTargetHostZones(array $targetHosts, array $targetHostZones): void
    {
        if (empty($targetHostZones)) {
            return;
        }

        $normalizedTargets = array();
        foreach ($targetHosts as $targetHost) {
            $normalizedTargets[strtolower(rtrim(trim($targetHost), '.'))] = true;
        }

        foreach (array_keys($targetHostZones) as $mappedHost) {
            if (!isset($normalizedTargets[$mappedHost])) {
                throw new RuntimeException(sprintf(
                    'TARGET_HOST_ZONES contains a mapping for %s, but that host is not present in TARGET_HOSTS.',
                    $mappedHost
                ));
            }
        }
    }

    private static function requiresExplicitZoneHint(array $labels): bool
    {
        if (count($labels) < 4) {
            return false;
        }

        $tld = $labels[count($labels) - 1];
        $secondLevel = $labels[count($labels) - 2];
        if (strlen($tld) !== 2) {
            return false;
        }

        return in_array($secondLevel, array(
            'ac',
            'co',
            'com',
            'edu',
            'gov',
            'net',
            'org',
        ), true);
    }

    private static function resolvePath(string $projectRoot, string $path): string
    {
        if ($path === '') {
            return $projectRoot;
        }

        if (self::isAbsolutePath($path)) {
            return $path;
        }

        return $projectRoot . DIRECTORY_SEPARATOR . $path;
    }

    private static function isAbsolutePath(string $path): bool
    {
        return $path !== '' && ($path[0] === '/' || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1);
    }

    private static function env(string $name, ?string $default = null): ?string
    {
        $value = getenv($name);
        if ($value === false) {
            return $default;
        }

        return (string) $value;
    }

    private static function loadEnvFile(string $projectRoot): void
    {
        $path = $projectRoot . DIRECTORY_SEPARATOR . '.env';
        if (!is_file($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            throw new RuntimeException('Unable to read .env file.');
        }

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || $trimmed[0] === '#') {
                continue;
            }

            $separator = strpos($trimmed, '=');
            if ($separator === false) {
                continue;
            }

            $name = trim(substr($trimmed, 0, $separator));
            $value = trim(substr($trimmed, $separator + 1));
            if ($name === '' || getenv($name) !== false) {
                continue;
            }

            if (
                strlen($value) >= 2
                && (($value[0] === '"' && substr($value, -1) === '"') || ($value[0] === "'" && substr($value, -1) === "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
        }
    }

    private static function normalizeOptional(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }

    private static function positiveInt(?string $value, int $default): int
    {
        if ($value === null || trim($value) === '') {
            return $default;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)));
        return $parsed === false ? $default : (int) $parsed;
    }

    private static function boolEnv(?string $value): bool
    {
        if ($value === null) {
            return false;
        }

        $normalized = strtolower(trim($value));
        return in_array($normalized, array('1', 'true', 'yes', 'on'), true);
    }

    private static function assertValidDomainName(string $domain, string $label): void
    {
        if (
            preg_match("/^([a-z\\d](-*[a-z\\d])*)(\\.([a-z\\d](-*[a-z\\d])*))*$/i", $domain) !== 1
            || preg_match("/^.{1,253}$/", $domain) !== 1
            || preg_match("/^[^\\.]{1,63}(\\.[^\\.]{1,63})*$/", $domain) !== 1
        ) {
            throw new RuntimeException(sprintf('%s is not a plausible DNS name: %s', $label, $domain));
        }
    }

    private function configuredNames(array $names): array
    {
        $configured = array();
        foreach ($names as $name) {
            if (getenv($name) !== false) {
                $configured[] = $name;
            }
        }

        return $configured;
    }
}
