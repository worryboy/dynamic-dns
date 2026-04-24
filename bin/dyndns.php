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
$gateway = new XmlGatewayClient($config, $logger);
$pushover = new PushoverNotifier(
    $logger,
    $config->pushoverAppKey(),
    $config->pushoverUserKey(),
    $config->pushoverLocationName(),
    $config->connectTimeout(),
    $config->requestTimeout()
);
$service = new DynDnsService($config, $logger, $stateStore, $resolver, $gateway, $pushover);

$exitCode = $service->runOnce();
exit($exitCode);
