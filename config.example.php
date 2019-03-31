<?php

/*
 * Legacy compatibility configuration.
 *
 * The containerized app reads environment variables first.
 * This file remains supported so older deployments can keep using
 * config.php without changing their bootstrap flow.
 */

// Log target. Use php://stdout for containers.
define('LOG', getenv('LOG_TARGET') ?: 'php://stdout');

// InterNetX XML gateway endpoint.
define('HOST', getenv('INTERNETX_HOST') ?: (getenv('SCHLUNDTECH_HOST') ?: 'https://gateway.autodns.com'));

// Authentication mode: credentials or trusted_application
define('INTERNETX_AUTH_MODE', getenv('INTERNETX_AUTH_MODE') ?: (getenv('SCHLUNDTECH_AUTH_MODE') ?: 'credentials'));

// Credential-based authentication
define('USER', getenv('INTERNETX_USER') ?: (getenv('SCHLUNDTECH_USER') ?: ''));
define('PASSWORD', getenv('INTERNETX_PASSWORD') ?: (getenv('SCHLUNDTECH_PASSWORD') ?: ''));
define('CONTEXT', getenv('INTERNETX_CONTEXT') ?: (getenv('SCHLUNDTECH_CONTEXT') ?: 9));

// Trusted application authentication
define('TRUSTED_APP_UUID', getenv('INTERNETX_TRUSTED_APP_UUID') ?: (getenv('SCHLUNDTECH_TRUSTED_APP_UUID') ?: ''));
define('TRUSTED_APP_PASSWORD', getenv('INTERNETX_TRUSTED_APP_PASSWORD') ?: (getenv('SCHLUNDTECH_TRUSTED_APP_PASSWORD') ?: ''));
define('TRUSTED_APP_NAME', getenv('INTERNETX_TRUSTED_APP_NAME') ?: (getenv('SCHLUNDTECH_TRUSTED_APP_NAME') ?: ''));

// Templates to access the gateway
define('XML_GET_ZONE', getenv('XML_GET_ZONE') ?: 'request-get.xml');
define('XML_PUT_ZONE', getenv('XML_PUT_ZONE') ?: 'request-put.xml');

// Domain configuration for legacy update.php mode
// Single domain
define('DOMAIN', getenv('DOMAIN') ?: 'example.com');
define('SUBDOMAIN', getenv('SUBDOMAIN') ?: 'home');
// Optional multi-domain support, e.g.
// define('DOMAINS', serialize(array("example.com" => array("home", "base"))));

// Nameserver (for single- or multi-domain legacy mode)
define('SYSTEM_NS', getenv('INTERNETX_SYSTEM_NS') ?: (getenv('SCHLUNDTECH_SYSTEM_NS') ?: 'ns.example.com'));

// Credentials to access the legacy HTTP/CLI compatibility endpoint
define('REMOTE_PASS', getenv('REMOTE_PASS') ?: 'mylongdyndnspassword');

?>
