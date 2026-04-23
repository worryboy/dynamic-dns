<?php

$projectRoot = dirname(__DIR__);

require_once $projectRoot . '/src/bootstrap.php';

try {
    $config = Config::load($projectRoot);
} catch (Throwable $exception) {
    $logger = new Logger('php://stdout');
    $logger->info('Startup validation', array(
        'env_file' => is_file($projectRoot . '/.env') ? 'present' : 'missing',
        'config_source' => is_file($projectRoot . '/.env') ? '.env/environment' : 'environment',
        'target_host' => getenv('TARGET_HOST') !== false && trim((string) getenv('TARGET_HOST')) !== '' ? 'present' : 'missing',
    ));
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
$service = new DynDnsService($config, $logger, $stateStore, $resolver, $gateway);

$exitCode = $service->runOnce();
exit($exitCode);
