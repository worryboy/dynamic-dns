<?php

final class Config
{
    private string $host;
    private string $user;
    private string $password;
    private string $context;
    private string $ownerUser;
    private string $ownerContext;
    private string $systemNs;
    private ?string $targetHost;
    private ?string $targetZone;
    private ?string $targetSubdomain;
    private array $domains;
    private array $ipv4Providers;
    private array $ipv6Providers;
    private bool $ipv4Enabled;
    private bool $ipv6Enabled;
    private bool $dryRun;
    private bool $debug;
    private bool $runOnce;
    private int $connectTimeout;
    private int $requestTimeout;
    private int $checkInterval;
    private string $stateDir;
    private string $logTarget;
    private string $projectRoot;
    private bool $envFileExists;

    private function __construct(array $data)
    {
        $this->host = $data['host'];
        $this->user = $data['user'];
        $this->password = $data['password'];
        $this->context = $data['context'];
        $this->ownerUser = $data['owner_user'];
        $this->ownerContext = $data['owner_context'];
        $this->systemNs = $data['system_ns'];
        $this->targetHost = $data['target_host'];
        $this->targetZone = $data['target_zone'];
        $this->targetSubdomain = $data['target_subdomain'];
        $this->domains = $data['domains'];
        $this->ipv4Providers = $data['ipv4_providers'];
        $this->ipv6Providers = $data['ipv6_providers'];
        $this->ipv4Enabled = $data['ipv4_enabled'];
        $this->ipv6Enabled = $data['ipv6_enabled'];
        $this->dryRun = $data['dry_run'];
        $this->debug = $data['debug'];
        $this->runOnce = $data['run_once'];
        $this->connectTimeout = $data['connect_timeout'];
        $this->requestTimeout = $data['request_timeout'];
        $this->checkInterval = $data['check_interval'];
        $this->stateDir = $data['state_dir'];
        $this->logTarget = $data['log_target'];
        $this->projectRoot = $data['project_root'];
        $this->envFileExists = $data['env_file_exists'];
    }

    public static function load(string $projectRoot): self
    {
        $projectRoot = rtrim($projectRoot, DIRECTORY_SEPARATOR);
        self::loadEnvFile($projectRoot);

        $domains = self::loadTargetDomains();
        if (empty($domains)) {
            throw new RuntimeException('No target configuration found. Set TARGET_HOST.');
        }
        $target = self::targetFromDomains($domains);

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

        return new self(array(
            'host' => self::env('INTERNETX_HOST', 'https://gateway.autodns.com'),
            'user' => self::env('INTERNETX_USER', ''),
            'password' => self::env('INTERNETX_PASSWORD', ''),
            'context' => (string) self::env('INTERNETX_CONTEXT', '9'),
            'owner_user' => (string) self::env('INTERNETX_OWNER_USER', ''),
            'owner_context' => (string) self::env('INTERNETX_OWNER_CONTEXT', ''),
            'system_ns' => (string) self::env('INTERNETX_SYSTEM_NS', ''),
            'target_host' => $target['host'],
            'target_zone' => $target['zone'],
            'target_subdomain' => $target['subdomain'],
            'domains' => $domains,
            'ipv4_providers' => $ipv4Providers,
            'ipv6_providers' => $ipv6Providers,
            'ipv4_enabled' => self::boolEnv(self::env('ENABLE_IPV4', 'true')),
            'ipv6_enabled' => self::boolEnv(self::env('ENABLE_IPV6', 'false')),
            'dry_run' => self::boolEnv(self::env('DRY_RUN', 'false')),
            'debug' => self::boolEnv(self::env('DEBUG', 'false')),
            'run_once' => self::boolEnv(self::env('RUN_ONCE', 'false')),
            'connect_timeout' => self::positiveInt(self::env('HTTP_CONNECT_TIMEOUT', '10'), 10),
            'request_timeout' => self::positiveInt(self::env('HTTP_REQUEST_TIMEOUT', '20'), 20),
            'check_interval' => self::positiveInt(self::env('CHECK_INTERVAL_SECONDS', '300'), 300),
            'state_dir' => self::resolvePath($projectRoot, self::env('STATE_DIR', 'state')),
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

    public function ownerUser(): string
    {
        return $this->ownerUser;
    }

    public function ownerContext(): string
    {
        return $this->ownerContext;
    }

    public function hasConfiguredOwner(): bool
    {
        return $this->ownerUser !== '' && $this->ownerContext !== '';
    }

    public function systemNs(): string
    {
        return $this->systemNs;
    }

    public function xmlGetZone(): string
    {
        return $this->projectRoot . DIRECTORY_SEPARATOR . 'request-get.xml';
    }

    public function xmlPutZone(): string
    {
        return $this->projectRoot . DIRECTORY_SEPARATOR . 'request-put.xml';
    }

    public function stateDir(): string
    {
        return $this->stateDir;
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
        return $this->targetHost;
    }

    public function targetZone(): ?string
    {
        return $this->targetZone;
    }

    public function targetSubdomain(): ?string
    {
        return $this->targetSubdomain;
    }

    public function targetZoneExplicit(): bool
    {
        return self::normalizeOptional(self::env('TARGET_ZONE')) !== null;
    }

    public function configuredOptionalSettings(): array
    {
        return $this->configuredNames(array(
            'TARGET_ZONE',
            'DRY_RUN',
            'DEBUG',
            'RUN_ONCE',
            'INTERNETX_AUTH_VARIANTS',
            'INTERNETX_OWNER_USER',
            'INTERNETX_OWNER_CONTEXT',
            'INTERNETX_SYSTEM_NS',
            'STATE_DIR',
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

    public function authVariants(): array
    {
        $configured = self::parseCsv(self::env('INTERNETX_AUTH_VARIANTS'));
        if (!empty($configured)) {
            return self::normalizeAuthVariants($configured, $this->hasConfiguredOwner());
        }

        if ($this->dryRun && $this->debug) {
            $variants = array('auth_only', 'owner_same');
            if ($this->hasConfiguredOwner()) {
                $variants[] = 'owner_configured';
            }

            return $variants;
        }

        return array('auth_only');
    }

    public function validateForGateway(): void
    {
        $required = array(
            'INTERNETX_HOST' => $this->host,
            'INTERNETX_USER' => $this->user,
            'INTERNETX_PASSWORD' => $this->password,
            'INTERNETX_CONTEXT' => $this->context,
            'TARGET_HOST' => $this->targetHost ?? '',
        );

        foreach ($required as $name => $value) {
            if ($value === '') {
                throw new RuntimeException(sprintf('Missing required configuration: %s', $name));
            }
        }

        $hostParts = parse_url($this->host);
        if (!is_array($hostParts) || empty($hostParts['scheme']) || empty($hostParts['host'])) {
            throw new RuntimeException('INTERNETX_HOST must be a plausible absolute URL.');
        }

        if (($this->ownerUser === '') !== ($this->ownerContext === '')) {
            throw new RuntimeException('INTERNETX_OWNER_USER and INTERNETX_OWNER_CONTEXT must be configured together.');
        }

        if (!$this->ipv4Enabled && !$this->ipv6Enabled) {
            throw new RuntimeException('At least one public IP family must be enabled. Set ENABLE_IPV4=true or ENABLE_IPV6=true.');
        }

        foreach ($this->domains as $domain => $subdomains) {
            self::assertValidDomainName($domain, 'target zone');
            foreach ($subdomains as $subdomain) {
                $host = $subdomain === '@' ? $domain : $subdomain . '.' . $domain;
                self::assertValidDomainName($host, 'target host');
            }
        }
    }

    private static function loadTargetDomains(): array
    {
        $targetHost = self::normalizeOptional(self::env('TARGET_HOST'));
        if ($targetHost === null) {
            return array();
        }

        return self::domainsFromTargetHost($targetHost, self::normalizeOptional(self::env('TARGET_ZONE')));
    }

    private static function domainsFromTargetHost(string $targetHost, ?string $targetZone): array
    {
        $host = strtolower(rtrim(trim($targetHost), '.'));
        self::assertValidDomainName($host, 'TARGET_HOST');

        if ($targetZone === null) {
            $labels = explode('.', $host);
            if (count($labels) < 3) {
                throw new RuntimeException('TARGET_HOST must include a host label and a zone, for example subleveldomain.domain.com.');
            }

            $targetZone = implode('.', array_slice($labels, -2));
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

        return array($zone => array($subdomain));
    }

    private static function targetFromDomains(array $domains): array
    {
        $domain = (string) array_key_first($domains);
        $subdomains = $domains[$domain];
        $subdomain = (string) reset($subdomains);
        $host = $subdomain === '@' ? $domain : $subdomain . '.' . $domain;

        return array(
            'host' => $host,
            'zone' => $domain,
            'subdomain' => $subdomain,
        );
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

    private static function normalizeAuthVariants(array $variants, bool $hasConfiguredOwner): array
    {
        $normalized = array();
        foreach ($variants as $variant) {
            $name = strtolower(str_replace('-', '_', trim($variant)));
            if ($name === 'a') {
                $name = 'auth_only';
            } elseif ($name === 'b') {
                $name = 'owner_same';
            } elseif ($name === 'c') {
                $name = 'owner_configured';
            }

            if (!in_array($name, array('auth_only', 'owner_same', 'owner_configured'), true)) {
                throw new RuntimeException(sprintf('Unsupported INTERNETX_AUTH_VARIANTS value: %s', $variant));
            }

            if ($name === 'owner_configured' && !$hasConfiguredOwner) {
                continue;
            }

            if (!in_array($name, $normalized, true)) {
                $normalized[] = $name;
            }
        }

        if (empty($normalized)) {
            throw new RuntimeException('No usable authentication variants configured.');
        }

        return $normalized;
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
