<?php

$projectRoot = __DIR__;
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

if (PHP_SAPI === 'cli') {
    $options = getopt('', array('pass:', 'domain:', 'ipaddr:', 'ip6addr:'));
    $input = array(
        'pass' => $options['pass'] ?? null,
        'domain' => $options['domain'] ?? null,
        'ipaddr' => $options['ipaddr'] ?? null,
        'ip6addr' => $options['ip6addr'] ?? null,
    );
} else {
    $input = array(
        'pass' => $_GET['pass'] ?? null,
        'domain' => $_GET['domain'] ?? null,
        'ipaddr' => $_GET['ipaddr'] ?? null,
        'ip6addr' => $_GET['ip6addr'] ?? null,
    );
}

$response = $service->handleLegacyRequest($input);

if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('Content-Type: application/json');
}

echo json_encode($response);
if (PHP_SAPI === 'cli') {
    echo PHP_EOL;
}

exit(($response['status'] ?? 'failed') === 'success' ? 0 : 1);
