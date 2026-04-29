<?php

$stateDir = getenv('STATE_DIR');
if ($stateDir === false || trim((string) $stateDir) === '') {
    $stateDir = '/app/state';
}

$statusFile = getenv('HEALTH_STATUS_FILE');
if ($statusFile === false || trim((string) $statusFile) === '') {
    $statusFile = rtrim((string) $stateDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'health.json';
}

$checkInterval = positiveInt(getenv('CHECK_INTERVAL_SECONDS'), 300);
$defaultMaxAge = max(300, ($checkInterval * 3) + 60);
$maxAge = positiveInt(getenv('HEALTH_MAX_AGE_SECONDS'), $defaultMaxAge);

if (!is_file((string) $statusFile)) {
    fwrite(STDERR, sprintf("health status file missing: %s\n", $statusFile));
    exit(1);
}

$decoded = json_decode((string) file_get_contents((string) $statusFile), true);
if (!is_array($decoded)) {
    fwrite(STDERR, sprintf("health status file is not valid JSON: %s\n", $statusFile));
    exit(1);
}

if (($decoded['last_run_success'] ?? false) !== true) {
    fwrite(STDERR, "last DynDNS cycle did not succeed\n");
    exit(1);
}

$lastSuccessAt = (string) ($decoded['last_success_at'] ?? '');
$lastSuccessTimestamp = strtotime($lastSuccessAt);
if ($lastSuccessTimestamp === false) {
    fwrite(STDERR, "health status file has no valid last_success_at timestamp\n");
    exit(1);
}

$age = time() - $lastSuccessTimestamp;
if ($age > $maxAge) {
    fwrite(STDERR, sprintf("last successful DynDNS cycle is stale: age=%s max_age=%s\n", $age, $maxAge));
    exit(1);
}

fwrite(STDOUT, sprintf("healthy: last_success_at=%s age=%s\n", $lastSuccessAt, $age));
exit(0);

function positiveInt($value, int $default): int
{
    if ($value === false || $value === null || trim((string) $value) === '') {
        return $default;
    }

    $parsed = filter_var($value, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)));
    return $parsed === false ? $default : (int) $parsed;
}
