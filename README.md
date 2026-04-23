# InterNetX DynDNS

`InterNetX DynDNS` is a containerized PHP worker that keeps one explicitly configured DNS host in sync with the current public IP address of the machine or network running the container.

The worker uses the InterNetX/AutoDNS XML gateway. The default live XML API endpoint remains `https://gateway.autodns.com`, which is current provider API terminology. Runtime API calls use the documented XML `auth_session` flow: create a session, reuse its hash for this run, then close it.

## What It Does

- runs as a PHP CLI worker in a container
- detects the current public IPv4 address
- optionally detects the current public IPv6 address
- reads one configured `TARGET_HOST`
- creates an InterNetX XML AuthSession before provider validation
- fetches the current InterNetX zone data with a non-mutating zone inquiry
- updates existing `A` and optional `AAAA` records only when the detected IP changed
- closes the InterNetX XML AuthSession at the end of the run
- stores last successful IP values in a persistent state directory
- supports `DRY_RUN=true` for safe local validation before any live update
- logs diagnostics without logging passwords or secret credentials

## Configuration

Copy [`.env.example`](.env.example) to `.env` and fill in the required values.

Required:

```env
INTERNETX_HOST=https://gateway.autodns.com
INTERNETX_USER=your-user
INTERNETX_PASSWORD=your-api-password
INTERNETX_CONTEXT=9
TARGET_HOST=subleveldomain.domain.com
```

For safe local validation, keep:

```env
DRY_RUN=true
RUN_ONCE=true
DEBUG=true
```

For a real update run, set:

```env
DRY_RUN=false
RUN_ONCE=false
DEBUG=false
```

`TARGET_HOST` is the only DNS target this worker considers. For example:

- `TARGET_HOST=subleveldomain.domain.com` becomes zone `domain.com` and subdomain `subleveldomain`.
- `TARGET_HOST=vpn.office.example.com` becomes zone `example.com` and subdomain `vpn.office`.
- By default, the zone is inferred from the last two labels.
- Set `TARGET_ZONE=example.co.uk` if the zone cannot be inferred from the last two labels.

### Variable Reference

| Variable | Group | Required? | Meaning | Example |
| --- | --- | --- | --- | --- |
| `INTERNETX_HOST` | required | Yes | InterNetX/AutoDNS XML API endpoint. | `https://gateway.autodns.com` |
| `INTERNETX_USER` | required | Yes | XML API username used to create and delete the AuthSession. | `my-user` |
| `INTERNETX_PASSWORD` | required | Yes | XML API password used to create and delete the AuthSession. Never logged. | `secret` |
| `INTERNETX_CONTEXT` | required | Yes | XML API auth context. This setup uses `9`. | `9` |
| `TARGET_HOST` | required | Yes | One fully qualified host this worker may validate and update. | `subleveldomain.domain.com` |
| `DRY_RUN` | optional runtime/debug | No | Validates config, may run read-only zone preflight, detects public IP, compares state, and logs what would happen without performing a live DNS update. | `true` |
| `RUN_ONCE` | optional runtime/debug | No | Executes one check cycle and exits instead of looping. | `true` |
| `DEBUG` | optional runtime/debug | No | Prints detailed diagnostics without exposing secrets, including sanitized XML request/response payloads for provider calls. | `true` |
| `TARGET_ZONE` | optional runtime/debug | No | Overrides zone inference from `TARGET_HOST`. | `example.co.uk` |
| `INTERNETX_SYSTEM_NS` | optional runtime/debug | No | Optional zone inquiry selector for the first system-managed nameserver. Usually unset. | `ns1.routing.net` |
| `INTERNETX_AUTH_VARIANTS` | optional auth diagnostics | No | Comma-separated AuthSessionCreate probe variants. Supported values: `auth_only`, `owner_same`, `owner_configured`. | `auth_only,owner_same` |
| `INTERNETX_OWNER_USER` | optional auth diagnostics | No | Owner user for the `owner_configured` probe variant. Not needed unless InterNetX support says this account must act for a subuser owner. | `owner-user` |
| `INTERNETX_OWNER_CONTEXT` | optional auth diagnostics | No | Owner context for the `owner_configured` probe variant. Must be set together with `INTERNETX_OWNER_USER`. | `9` |
| `STATE_DIR` | optional runtime/debug | No | Directory for persisted `last_ipv4` and `last_ipv6` values. | `/app/state` |
| `CHECK_INTERVAL_SECONDS` | optional runtime/debug | No | Sleep interval for continuous container mode. Ignored when `RUN_ONCE=true`. | `300` |
| `ENABLE_IPV6` | optional runtime/debug | No | Enables IPv6 detection and `AAAA` record updates. | `false` |
| `PUBLIC_IPV4_PROVIDERS` | optional runtime/debug | No | Comma-separated public IPv4 detection endpoints. | `https://api.ipify.org` |
| `PUBLIC_IPV6_PROVIDERS` | optional runtime/debug | No | Comma-separated public IPv6 detection endpoints. | `https://api64.ipify.org` |
| `HTTP_CONNECT_TIMEOUT` | optional runtime/debug | No | HTTP connect timeout for IP and XML requests. | `10` |
| `HTTP_REQUEST_TIMEOUT` | optional runtime/debug | No | Total HTTP request timeout for IP and XML requests. | `20` |
| `LOG_TARGET` | optional runtime/debug | No | PHP stream or file path for logs. | `php://stdout` |

`INTERNETX_SYSTEM_NS` is not part of XML authentication and is not required for the normal update path. If set, it is sent as `<system_ns>` in the zone inquiry request. If unset, the field is omitted.

There are no optional advanced authentication settings in the current worker. Credentials are used for `AuthSessionCreate` and `AuthSessionDelete`; zone inquiry and update requests use `<auth_session><hash>...</hash></auth_session>`.

## Safe Local Validation

Use dry-run mode before allowing live DNS updates:

```bash
cp .env.example .env
# edit .env and set INTERNETX_USER, INTERNETX_PASSWORD, and TARGET_HOST
docker compose build
docker compose run --rm internetx-dyndns
```

The recommended validation flags are:

```env
DRY_RUN=true
RUN_ONCE=true
DEBUG=true
```

- `DRY_RUN=true` validates config, creates and closes an InterNetX AuthSession, may run read-only InterNetX zone validation, detects the public IP, compares state, and logs what would happen without sending a live DNS update.
- `RUN_ONCE=true` executes a single check cycle and exits instead of looping continuously.
- `DEBUG=true` prints detailed diagnostic logging without exposing passwords, session hashes, or sensitive credentials. Provider request/response payloads are sanitized and labeled as `auth_session_create`, `read_only_preflight`, `live_mutation`, or `session_cleanup`.
- With `DEBUG=true`, the worker logs each runtime stage as it moves through config validation, authentication/session preflight, target validation, public IP detection, update decision, live mutation, and session cleanup.

IPv4 detection is required and is always logged. IPv6 detection is optional:

- If `ENABLE_IPV6=false`, the worker logs `IPv6 detection disabled by configuration`.
- If `ENABLE_IPV6=true` and an address is found, the worker logs `Detected IPv6 address`.
- If `ENABLE_IPV6=true` but no IPv6 address is detected, the worker logs `IPv6 detection enabled but no IPv6 address detected` and continues with IPv4.

Example dry-run startup:

```text
[2026-04-23T08:00:00+00:00] DEBUG Runtime stage entered stage=startup/config validation dry_run=true mutation_allowed=false
[2026-04-23T08:00:00+00:00] INFO Startup validation env_file=present config_source=.env/environment dry_run=true debug=true run_once=true
[2026-04-23T08:00:00+00:00] INFO XML authentication configuration api_host=present auth_flow=auth_session session_create_uses_credentials=true follow_up_auth=auth_session username=present password=present context=present auth_variants=auth_only,owner_same configured_owner=not_set
[2026-04-23T08:00:00+00:00] INFO Project DNS target configuration target_host=present system_ns_optional=not_set
[2026-04-23T08:00:00+00:00] INFO Optional runtime settings configured=DRY_RUN,DEBUG,RUN_ONCE
[2026-04-23T08:00:00+00:00] INFO Configuration format validated dry_run_config_sufficient=true
[2026-04-23T08:00:00+00:00] INFO Target host mapping full_host=subleveldomain.domain.com domain=domain.com zone=domain.com subdomain=subleveldomain target_zone_source=inferred_last_two_labels
[2026-04-23T08:00:00+00:00] DEBUG Runtime stage entered stage=authentication/session preflight dry_run=true mutation_allowed=false live_mutation_attempted=false
[2026-04-23T08:00:00+00:00] INFO Starting InterNetX authentication/session preflight auth_flow=auth_session session_create_task_code=1321001 auth_variants=auth_only,owner_same mutation_allowed=false
[2026-04-23T08:00:00+00:00] INFO Testing InterNetX authentication variant auth_variant=auth_only owner_block_included=false owner_source=none mutation=false dry_run=true
[2026-04-23T08:00:00+00:00] DEBUG InterNetX XML request prepared operation=AuthSessionCreate task_code=1321001 api_call_type=auth_session_create stage=authentication/session preflight mutation=false dry_run=true owner_block_included=false auth_mode=session_create auth_variant=auth_only session_established=false payload=<request>...</request>
[2026-04-23T08:00:00+00:00] DEBUG InterNetX XML response received operation=AuthSessionCreate task_code=1321001 api_call_type=auth_session_create stage=authentication/session preflight mutation=false dry_run=true owner_block_included=false auth_mode=session_create auth_variant=auth_only session_established=false http_status=200 transport_success=true response_result_status_code=S1321001 response_result_status_type=success stid=20260423-app1 api_business_success=true payload=<response>...</response>
[2026-04-23T08:00:00+00:00] INFO InterNetX session login successful auth_variant=auth_only auth_mode=auth_session owner_block_included=false session_hash=9b4b...73dd session_persisted=false
[2026-04-23T08:00:01+00:00] DEBUG Runtime stage entered stage=target/zone validation dry_run=true mutation_allowed=false live_mutation_attempted=false
[2026-04-23T08:00:01+00:00] INFO Validating read-only InterNetX zone access zone=domain.com subdomains=subleveldomain mutation=false
[2026-04-23T08:00:01+00:00] DEBUG InterNetX XML request prepared operation=ZoneInfo task_code=0205 api_call_type=read_only_preflight stage=target/zone validation mutation=false dry_run=true owner_block_included=false auth_mode=auth_session auth_variant=auth_only session_established=true payload=<request>...</request>
[2026-04-23T08:00:01+00:00] DEBUG InterNetX XML response received operation=ZoneInfo task_code=0205 api_call_type=read_only_preflight stage=target/zone validation mutation=false dry_run=true owner_block_included=false auth_mode=auth_session auth_variant=auth_only session_established=true http_status=200 transport_success=true response_result_status_code=S0205 response_result_status_type=success stid=20260423-app1 api_business_success=true payload=<response>...</response>
[2026-04-23T08:00:01+00:00] INFO Read-only InterNetX zone access validation successful target_host=subleveldomain.domain.com
[2026-04-23T08:00:02+00:00] DEBUG Runtime stage entered stage=public IP detection dry_run=true mutation_allowed=false live_mutation_attempted=false
[2026-04-23T08:00:02+00:00] INFO Detected IPv4 address ipv4=203.0.113.10
[2026-04-23T08:00:02+00:00] INFO IPv6 detection disabled by configuration attempted=false
[2026-04-23T08:00:02+00:00] DEBUG Runtime stage entered stage=update decision dry_run=true mutation_allowed=false live_mutation_attempted=false
[2026-04-23T08:00:02+00:00] DEBUG Loaded previous state last_ipv4=none last_ipv6=none
[2026-04-23T08:00:02+00:00] DEBUG Update decision update_required=true ipv4_changed=true ipv6_changed=false dry_run=true mutation_allowed=false
[2026-04-23T08:00:02+00:00] INFO Dry-run would require update, but mutation is disabled ipv4=203.0.113.10 ipv4_changed=true update_required=true target_host=subleveldomain.domain.com target_zone=domain.com target_subdomain=subleveldomain intended_action=would_update_dns_records dry_run=true mutation_allowed=false
[2026-04-23T08:00:02+00:00] INFO Dry-run completed; no live mutation sent reason=dry_run_enabled mutation_allowed=false live_mutation_attempted=false
[2026-04-23T08:00:02+00:00] DEBUG Runtime stage entered stage=session cleanup dry_run=true mutation_allowed=false live_mutation_attempted=false
[2026-04-23T08:00:02+00:00] DEBUG InterNetX XML request prepared operation=AuthSessionDelete task_code=1321003 api_call_type=session_cleanup stage=session cleanup mutation=false dry_run=true owner_block_included=false auth_mode=session_delete auth_variant=auth_only session_established=true payload=<request>...</request>
[2026-04-23T08:00:02+00:00] INFO InterNetX session closed auth_variant=auth_only auth_mode=session_delete owner_block_included=false session_hash=9b4b...73dd
```

The read-only access validation uses InterNetX ZoneInfo task `0205`. It can confirm that the credentials can read the configured zone and that the configured record exists. It cannot guarantee every future provider-side update policy will allow mutation, but it is the safest non-mutating preflight this XML workflow provides.

## InterNetX XML API

The worker uses:

- `request-get.xml` with task code `0205` for zone inquiry
- `request-put.xml` with task code `0202` for zone update
- `AuthSessionCreate` with task code `1321001` to create a session from `user`, `password`, and `context`
- `<auth_session><hash>...</hash></auth_session>` for follow-up zone inquiry and update calls
- `AuthSessionDelete` with task code `1321003` to close the session

The session hash is treated as a secret. It is kept only in memory, never persisted, and redacted in sanitized XML debug logs. Short masked display such as `9b4b...73dd` may appear in operational logs.

The XML basics documentation lists `owner` as an optional top-level subuser block. The Auth object itself remains `user/context/password`. For debugging InterNetX or SchlundTech-style account setups, dry-run debug mode can test AuthSessionCreate variants explicitly:

- `auth_only`: `<auth>` only.
- `owner_same`: `<auth>` plus `<owner>` with the same user/context.
- `owner_configured`: `<auth>` plus `<owner>` from `INTERNETX_OWNER_USER` and `INTERNETX_OWNER_CONTEXT`.

When `DRY_RUN=true` and `DEBUG=true`, the default probe order is `auth_only`, `owner_same`, and `owner_configured` only if configured owner values are present. The worker stops at the first successful session login. If every variant fails, it stops before zone validation and logs the tested variant, whether owner was included, InterNetX response code/text, `stid`, `session_established=false`, and `live_mutation_attempted=false`.

The updater does not create missing DNS records. The target `A` record must already exist. If `ENABLE_IPV6=true`, the target `AAAA` record must already exist too.

## Docker-Compatible Runtime

Build and run continuously:

```bash
cp .env.example .env
# edit .env
docker compose build
docker compose up -d
docker compose logs -f
```

Run one safe validation cycle:

```bash
cp .env.example .env
# edit .env and keep DRY_RUN=true, RUN_ONCE=true, DEBUG=true
docker compose build
docker compose run --rm internetx-dyndns
```

The compose setup contains one service: `internetx-dyndns`.

Container layout:

- base image: `php:8.3-cli-alpine`
- app image: `internetx-dyndns:local`
- worker entry point: [`docker/start.sh`](docker/start.sh)
- CLI entry point: [`bin/dyndns.php`](bin/dyndns.php)
- persistent state mount: `./state:/app/state`

## macOS Without Docker Desktop

Docker Desktop is not required. Any Docker-compatible backend that supports the Docker CLI and Compose can run the worker.

Colima is one valid option on macOS:

```bash
brew install colima docker docker-compose
colima start
docker version
docker compose version
```

Then run the same `docker compose` commands shown above.

## Files Of Interest

- [`src/Config.php`](src/Config.php)
- [`src/XmlGatewayClient.php`](src/XmlGatewayClient.php)
- [`src/PublicIpResolver.php`](src/PublicIpResolver.php)
- [`src/DynDnsService.php`](src/DynDnsService.php)
- [`.env.example`](.env.example)
- [`docker-compose.yml`](docker-compose.yml)
- [`Dockerfile`](Dockerfile)
