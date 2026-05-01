<?php

$projectRoot = dirname(__DIR__);

require_once $projectRoot . '/src/bootstrap.php';

try {
    $config = Config::load($projectRoot);
} catch (Throwable $exception) {
    $logger = new Logger('php://stdout');
    $targetHostPresent = getenv('TARGET_HOST') !== false && trim((string) getenv('TARGET_HOST')) !== '';
    $targetHostsPresent = getenv('TARGET_HOSTS') !== false && trim((string) getenv('TARGET_HOSTS')) !== '';
    $logger->info('Startup validation', array(
        'env_file' => is_file($projectRoot . '/.env') ? 'present' : 'missing',
        'config_source' => is_file($projectRoot . '/.env') ? '.env/environment' : 'environment',
        'target_host' => $targetHostPresent ? 'present' : 'missing',
        'target_hosts' => $targetHostsPresent ? 'present' : 'missing',
    ));
    if ($targetHostPresent && $targetHostsPresent) {
        $logger->warning('Conflicting target configuration detected', array(
            'target_host' => 'present',
            'target_hosts' => 'present',
            'resolution' => 'fail_fast',
            'reason' => 'TARGET_HOST is single-target mode and TARGET_HOSTS is multi-target mode; configure only one.',
        ));
    }
    $logger->error('Configuration loading failed', array('error' => $exception->getMessage()));
    writeStartupHealthStatus(false, array(
        'stage' => 'startup/config validation',
        'error' => $exception->getMessage(),
        'version' => AppInfo::version(),
    ));
    exit(1);
}

$logger = new Logger($config->logTarget());
$stateStore = new StateStore($config->stateDir());
$resolver = new PublicIpResolver(
    $config->ipv4Providers(),
    $config->ipv6Providers(),
    $logger,
    $config->connectTimeout(),
    $config->requestTimeout()
);
$provider = new InterNetXXmlProvider(new InterNetXXmlGatewayClient($config, $logger));
$pushover = new PushoverNotifier(
    $logger,
    $config->pushoverAppKey(),
    $config->pushoverUserKey(),
    $config->pushoverLocationPrefix(),
    $config->connectTimeout(),
    $config->requestTimeout()
);
$service = new DynDnsService($config, $logger, $stateStore, $resolver, $provider, $pushover);

$exitCode = $service->runOnce();
exit($exitCode);

function writeStartupHealthStatus(bool $success, array $context): void
{
    $path = startupHealthStatusFile();
    $payload = array_merge(array(
        'timestamp' => Clock::nowIso8601(),
        'last_run_success' => $success,
        'last_success_at' => null,
    ), $context);

    $directory = dirname($path);
    if ($directory !== '' && $directory !== '.' && !is_dir($directory)) {
        @mkdir($directory, 0775, true);
    }

    @file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX);
}

function startupHealthStatusFile(): string
{
    $stateDir = getenv('STATE_DIR');
    if ($stateDir === false || trim((string) $stateDir) === '') {
        $stateDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'state';
    }

    $healthStatusFile = getenv('HEALTH_STATUS_FILE');
    if ($healthStatusFile === false || trim((string) $healthStatusFile) === '') {
        return rtrim((string) $stateDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'health.json';
    }

    return (string) $healthStatusFile;
}
