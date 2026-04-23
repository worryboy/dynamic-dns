# InterNetX DynDNS

Repository name: `internetx-dyndns`

`InterNetX DynDNS` is a small containerized worker that keeps selected DNS records in the InterNetX XML API in sync with the current public IP addresses of the machine or network running the container.

The project originated from a SchlundTech-related DynDNS workflow. SchlundTech is now historical context only; selected legacy configuration names remain supported temporarily so existing deployments can migrate without breaking immediately.

Domain Support confirmed that the InterNetX API can be used for this setup. This setup uses InterNetX API authentication context `9`.

## What It Does

- runs as a PHP CLI worker in a container
- detects the current public IPv4 address
- optionally detects the current public IPv6 address
- updates DNS only when the detected IP changes
- updates existing `A` and optional `AAAA` records through the InterNetX XML gateway
- stores last successful IP values in a persistent state directory
- logs to stdout and does not log credentials

The legacy `update.php` endpoint is still included for older router or DynDNS-style integrations. The primary deployment model is the self-running container worker.

## Naming And Compatibility

Current naming:

- repository: `internetx-dyndns`
- product name: `InterNetX DynDNS`
- compose service: `internetx-dyndns`
- container name: `internetx-dyndns`
- local image name: `internetx-dyndns:local`
- preferred environment prefix: `INTERNETX_*`

Temporarily retained compatibility names:

- `SCHLUNDTECH_*` environment variables are still accepted as fallback when the matching `INTERNETX_*` variable is not set.
- legacy `config.php` constants such as `HOST`, `USER`, `PASSWORD`, `CONTEXT`, `SYSTEM_NS`, `DOMAIN`, `SUBDOMAIN`, and `DOMAINS` are still accepted.
- `update.php`, `functions.php`, and `config.example.php` remain legacy compatibility paths.

Migration rule: set the new `INTERNETX_*` variables first. Remove `SCHLUNDTECH_*` variables after the container runs successfully with the new names.

## Configuration

Configuration is environment-first. Copy [`.env.example`](.env.example) to `.env` and fill in your values.

Required InterNetX settings:

```env
INTERNETX_HOST=https://gateway.autodns.com
INTERNETX_AUTH_MODE=credentials
INTERNETX_USER=your-user
INTERNETX_PASSWORD=your-api-password
INTERNETX_CONTEXT=9
INTERNETX_SYSTEM_NS=ns1.routing.net
```

Required DNS target settings, single domain:

```env
DOMAIN=example.com
SUBDOMAINS=home,vpn
```

Or multiple domains:

```env
DOMAINS_JSON={"example.com":["home","vpn"],"example.org":["office"]}
```

`DOMAINS_JSON` takes precedence over `DOMAIN` and `SUBDOMAINS`.

Trusted application mode is also supported when InterNetX provides those credentials:

```env
INTERNETX_AUTH_MODE=trusted_application
INTERNETX_TRUSTED_APP_UUID=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
INTERNETX_TRUSTED_APP_PASSWORD=your-trusted-app-password
INTERNETX_TRUSTED_APP_NAME=internetx-dyndns
INTERNETX_SYSTEM_NS=ns1.routing.net
```

Optional worker settings:

```env
CHECK_INTERVAL_SECONDS=300
STATE_DIR=/app/state
ENABLE_IPV6=false
PUBLIC_IPV4_PROVIDERS=https://api.ipify.org,https://ipv4.icanhazip.com,https://ifconfig.me/ip
PUBLIC_IPV6_PROVIDERS=https://api64.ipify.org,https://ipv6.icanhazip.com
HTTP_CONNECT_TIMEOUT=10
HTTP_REQUEST_TIMEOUT=20
LOG_TARGET=php://stdout
REMOTE_PASS=
```

`REMOTE_PASS` only applies to the legacy `update.php` compatibility endpoint.

## InterNetX XML API

The implementation keeps the existing XML gateway workflow:

- `request-get.xml` uses task code `0205` for zone inquiry
- `request-put.xml` uses task code `0202` for zone update
- credential auth writes `user`, `password`, and `context` into the XML request
- trusted application auth replaces the legacy auth block with a `trusted_application` authentication block

The default API host is `https://gateway.autodns.com`, which is the InterNetX/AutoDNS XML gateway endpoint. AutoDNS may still appear in InterNetX documentation and gateway URLs; this repository uses InterNetX as the primary integration name.

The updater does not create missing DNS records. The zone and target records must already exist and be editable by the configured InterNetX account context.

## Domain Setup

Before running the worker, make sure:

- the zone exists in InterNetX
- the configured account can edit the zone
- the domain uses the relevant InterNetX-managed nameserver data
- every configured subdomain already has an `A` record
- every configured subdomain has an `AAAA` record if IPv6 updates are enabled
- `INTERNETX_SYSTEM_NS` contains the nameserver hostname required by the zone inquiry request

Example:

```env
DOMAIN=example.com
SUBDOMAINS=home
INTERNETX_SYSTEM_NS=ns1.routing.net
```

## Public IP Detection

IPv4 detection is enabled by default and uses:

```env
PUBLIC_IPV4_PROVIDERS=https://api.ipify.org,https://ipv4.icanhazip.com,https://ifconfig.me/ip
```

IPv6 detection is disabled by default. Enable it with:

```env
ENABLE_IPV6=true
PUBLIC_IPV6_PROVIDERS=https://api64.ipify.org,https://ipv6.icanhazip.com
```

When IPv6 is enabled, a successful IPv6 lookup is required and matching `AAAA` records are updated with the same change-detection flow.

## Change Detection And State

The worker stores successful IP values in the mounted state directory:

- `state/last_ipv4`
- `state/last_ipv6` when IPv6 is enabled

If neither enabled address family changed, the worker logs `IP unchanged` and skips the XML API update. State is written only after a successful remote update.

## Docker

Build and run:

```bash
cp .env.example .env
docker compose build
docker compose up -d
docker compose logs -f
```

The compose setup contains one long-running service: `internetx-dyndns`.

Container layout:

- base image: `php:8.3-cli-alpine`
- app image: `internetx-dyndns:local`
- worker entry point: [`docker/start.sh`](docker/start.sh)
- CLI entry point: [`bin/dyndns.php`](bin/dyndns.php)
- persistent state mount: `./state:/app/state`

Local one-shot run, if PHP is installed:

```bash
php bin/dyndns.php
```

Legacy compatibility run:

```bash
php update.php --pass="your-remote-pass" --domain="example.com" --ipaddr="203.0.113.10" --ip6addr="2001:db8::10"
```

## Legacy Migration

Deprecated environment variables still accepted as fallback:

| Deprecated | Preferred |
| --- | --- |
| `SCHLUNDTECH_HOST` | `INTERNETX_HOST` |
| `SCHLUNDTECH_AUTH_MODE` | `INTERNETX_AUTH_MODE` |
| `SCHLUNDTECH_USER` | `INTERNETX_USER` |
| `SCHLUNDTECH_PASSWORD` | `INTERNETX_PASSWORD` |
| `SCHLUNDTECH_CONTEXT` | `INTERNETX_CONTEXT` |
| `SCHLUNDTECH_SYSTEM_NS` | `INTERNETX_SYSTEM_NS` |
| `SCHLUNDTECH_TRUSTED_APP_UUID` | `INTERNETX_TRUSTED_APP_UUID` |
| `SCHLUNDTECH_TRUSTED_APP_PASSWORD` | `INTERNETX_TRUSTED_APP_PASSWORD` |
| `SCHLUNDTECH_TRUSTED_APP_NAME` | `INTERNETX_TRUSTED_APP_NAME` |

Manual steps outside this repository:

1. Rename the GitHub repository to `internetx-dyndns`.
2. Update local remotes if the GitHub URL changes.
3. Update deployment `.env` files to use `INTERNETX_*`.
4. Rebuild or retag any externally published container images that still use the old name.
5. Update external documentation, bookmarks, scheduler names, and monitoring labels that still refer to SchlundTech.

## Files Of Interest

- [`src/Config.php`](src/Config.php)
- [`src/XmlGatewayClient.php`](src/XmlGatewayClient.php)
- [`src/PublicIpResolver.php`](src/PublicIpResolver.php)
- [`src/DynDnsService.php`](src/DynDnsService.php)
- [`.env.example`](.env.example)
- [`docker-compose.yml`](docker-compose.yml)
- [`Dockerfile`](Dockerfile)
