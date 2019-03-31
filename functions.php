<?php

/* Legacy helpers retained for backward compatibility with older integrations. */

function requestCurl($data) {
  $host = defined('HOST') ? HOST : (getenv('INTERNETX_HOST') ?: 'https://gateway.autodns.com');
  $ch = curl_init($host);
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
  curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/xml; charset=utf-8'));
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
  curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

  $result = curl_exec($ch);
  if ($result === false) {
    trigger_error('Curl execution error: ' . curl_error($ch), E_USER_ERROR);
    curl_close($ch);
    return false;
  }

  curl_close($ch);
  return $result;
}

function validIP($ip) {
  if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
    return true;
  }
  return false;
}

function validIPv6($ip) {
  if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
    return true;
  }
  return false;
}

function validDomain($domain)
{
  return (preg_match("/^([a-z\d](-*[a-z\d])*)(\.([a-z\d](-*[a-z\d])*))*$/i", $domain) //valid chars check
    && preg_match("/^.{1,253}$/", $domain) //overall length check
    && preg_match("/^[^\.]{1,63}(\.[^\.]{1,63})*$/", $domain)); //length of each label
}

function configuredDomain($domain)
{
  if (defined('DOMAINS')) {
    $domains = unserialize(DOMAINS);
    return array_key_exists($domain, $domains);
  } else {
    return $domain == DOMAIN;
  }
}

function validCred($pass) {
  if ($pass == REMOTE_PASS) {
    return true;
  }
  return false;
}

/* Respond with status & message in JSON */
function respond($status, $msg = "") {
  if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('Content-type: application/json');
  }
  $response = array();
  $response["status"] = $status;
  if (!empty($msg)) {
    $response["msg"] = $msg;
  }
  echo json_encode($response);
  if (PHP_SAPI === 'cli') {
    echo PHP_EOL;
  }
  exit();
}

/* Custom error handler to log to file */
function dyndns_error_handler($errno, $errstr, $errfile, $errline)
{
  if (!(error_reporting() & $errno)) {
    // This error code is not included in error_reporting
    return;
  }

  $date = date(DATE_W3C);
  $str = "";

  switch ($errno) {
  case E_USER_ERROR:
    $str .= "$date ERROR [$errno]: $errstr, Fatal error on line $errline in file $errfile";
    break;

  case E_USER_WARNING:
    $str .= "$date WARNING [$errno]: $errstr\n";
    break;

  case E_USER_NOTICE:
    $str .= "$date NOTICE [$errno]: $errstr\n";
    break;

  default:
    $str .= "$date Unknown error type: [$errno] $errstr\n";
    break;
  }

  $target = defined('LOG') ? LOG : 'php://stdout';
  file_put_contents($target, $str, FILE_APPEND);

  /* Don't execute PHP internal error handler */
  return true;
}

?>
