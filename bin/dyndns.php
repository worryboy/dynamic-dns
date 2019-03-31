<?php

$projectRoot = dirname(__DIR__);
$legacyConfig = $projectRoot . '/config.php';
if (is_file($legacyConfig)) {
    require_once $legacyConfig;
}

require_once $projectRoot . '/src/bootstrap.php';

$config = Config::load($projectRoot);
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
