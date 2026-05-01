# Dynamic DNS Worker

`internetx-dyndns` is a containerized, provider-oriented Dynamic DNS worker. It keeps one or more explicitly configured DNS hostnames in sync with the current public IP address of the machine or network running the container.

The worker core handles public IP detection, target parsing, state, update decisions, dry-run behavior, notifications, logging, and health output. DNS-provider API details sit behind a provider/interface layer.

Current implementation:

- Provider: InterNetX / AutoDNS / SchlundTech-related DNS
- Interface: InterNetX XML
- Auth model: XML `auth_session`

Source code is available on GitHub: [worryboy/internetx-dyndns](https://github.com/worryboy/internetx-dyndns)
Container image is available on Docker Hub: [worryboy/internetx-dyndns](https://hub.docker.com/r/worryboy/internetx-dyndns)

## Documentation Map

- Provider-specific InterNetX XML details: [docs/providers/internetx-xml.md](docs/providers/internetx-xml.md)
- Docker runtime and image details: [README.Docker.md](README.Docker.md)
- Traefik/CrowdSec integration example: [docs/integrations/traefik-crowdsec.md](docs/integrations/traefik-crowdsec.md)
- Provider-extension guide: [docs/development/adding-a-provider.md](docs/development/adding-a-provider.md)
- Verification design notes: [docs/design/verification.md](docs/design/verification.md)

## What It Does

- runs as a PHP CLI worker in a container
- detects the current public IPv4 address
- optionally detects the current public IPv6 address
- reads one configured `TARGET_HOST` or multiple `TARGET_HOSTS`
- opens the configured DNS provider/interface session or equivalent auth flow
- fetches current provider-side DNS state with a non-mutating lookup
- updates existing `A` and optional `AAAA` records only when the detected IP changed for each configured target
- closes the provider session or equivalent auth flow at the end of the run
- stores last successful IP values in a persistent state directory
- supports `DRY_RUN=true` for safe local validation before any live update
- logs diagnostics without logging passwords or secret credentials

## Current Provider Implementation

The currently implemented provider/interface is InterNetX XML for InterNetX / AutoDNS / SchlundTech-related environments.

The default live XML API endpoint is `https://gateway.autodns.com`. Runtime API calls use the XML `auth_session` flow: create a session, reuse its hash for this run, then close it.

Provider-specific configuration, XML task details, assumptions, and limits are documented in [docs/providers/internetx-xml.md](docs/providers/internetx-xml.md). Future providers, or a future InterNetX interface such as JSON, should be added behind the provider layer described in [docs/development/adding-a-provider.md](docs/development/adding-a-provider.md).

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

For multi-target mode, use:

```env
TARGET_HOSTS=app1.example.com,app2.example.com,app3.example.com
```

For safe local validation, keep:

```env
DRY_RUN=true
FORCE_UPDATE_ON_NO_CHANGE=false
RUN_ONCE=true
DEBUG=true
```

For a real update run, set:

```env
DRY_RUN=false
FORCE_UPDATE_ON_NO_CHANGE=false
RUN_ONCE=false
DEBUG=false
```

Optional Pushover notifications:

```env
PUSHOVER_APP_KEY=your-pushover-app-token
PUSHOVER_USER_KEY=your-pushover-user-key
PUSHOVER_LOCATION_PREFIX=Home-Server
```

All three values are required to enable notifications. If any of them are missing, Pushover stays disabled.
`PUSHOVER_LOCATION_PREFIX` is placed at the beginning of the notification message. The legacy `PUSHOVER_LOCATION_NAME` variable is accepted temporarily as a deprecated fallback, but `PUSHOVER_LOCATION_PREFIX` wins if both are set.

Example:

```text
Zurich IPv4 Address: 109.40.176.130
```

Single-target mode stays simple:

- `TARGET_HOST=subleveldomain.domain.com` becomes zone `domain.com` and subdomain `subleveldomain`.
- `TARGET_HOST=vpn.office.example.com` becomes zone `example.com` and subdomain `vpn.office`.
- By default, the zone is inferred from the last two labels.
- Set `TARGET_ZONE=example.co.uk` if the zone cannot be inferred from the last two labels.

Multi-target mode uses a comma-separated `TARGET_HOSTS` list:

- `TARGET_HOSTS=app1.example.com,app2.example.com,app3.example.com`
- all configured hosts use the same detected public IPv4/IPv6 values in a cycle
- the worker authenticates once, validates all targets, updates only the targets that need changes, then closes the session
- `TARGET_ZONE` is only supported in single-target mode
- if a multi-target hostname needs an explicit zone hint, use `TARGET_HOST_ZONES=host=zone,host=zone`
- if both `TARGET_HOST` and `TARGET_HOSTS` are set, startup fails fast with a clear warning because that configuration is ambiguous

This is useful when one public host runs several services behind a reverse proxy such as Traefik and several DNS names should always resolve to that same host IP.

## DNS Target Records

Set `TARGET_HOST` to one full hostname, or `TARGET_HOSTS` to several full hostnames that should all follow the same current public IP address:

```env
TARGET_HOST=sublevel.domain.tld
```

```env
TARGET_HOSTS=app1.domain.tld,app2.domain.tld,app3.domain.tld
```

The worker interprets that value as:

- full host: `sublevel.domain.tld`
- zone/domain: `domain.tld`
- host/subdomain label: `sublevel`

If the zone cannot be inferred from the last two labels in single-target mode, set `TARGET_ZONE` explicitly:

```env
TARGET_HOST=home.example.co.uk
TARGET_ZONE=example.co.uk
```

If you use multi-target mode and one hostname is ambiguous, add an explicit per-host zone hint:

```env
TARGET_HOSTS=app.example.co.uk,service.example.com.au
TARGET_HOST_ZONES=app.example.co.uk=example.co.uk,service.example.com.au=example.com.au
```

Automatic last-two-label inference works well for normal names such as `app.example.com` and `vpn.office.example.org`, but it is intentionally rejected for common multi-label ccTLD patterns such as:

- `app.example.co.uk`
- `service.example.com.au`

In those cases the worker now fails early with a clear error instead of silently guessing the wrong zone.

DNS record types map directly to enabled protocols:

- IPv4 updates use the target host's `A` record.
- IPv6 updates use the target host's `AAAA` record.

Examples:

- `ENABLE_IPV4=true` requires an `A` record for `sublevel.domain.tld`.
- `ENABLE_IPV6=true` requires an `AAAA` record for `sublevel.domain.tld`.
- If both are enabled, both record types should exist.

This worker updates existing record values. It does not create missing DNS records.

TTL controls how long DNS resolvers may cache the old value before asking again. For DynDNS-style records with changing home or office IP addresses, a short TTL is usually best; `300` seconds is a common practical starting point. Very low TTLs can increase DNS query volume and may be constrained by your provider.

The worker does not manage TTL explicitly. It reads the existing zone record, replaces the `A` or `AAAA` value, and preserves the rest of the returned record data. If the existing record already has a TTL, that TTL should remain unchanged by this worker. Configure TTL in the InterNetX/AutoDNS zone before relying on the updater.

Before running live updates, check the zone for every configured target:

- `TARGET_HOST` or each `TARGET_HOSTS` entry is exactly the hostname you want to update.
- The inferred or configured zone is correct.
- The target `A` record exists if IPv4 updates are enabled.
- The target `AAAA` record exists if IPv6 updates are enabled.
- The TTL is suitable for a dynamic IP use case, for example around `300` seconds.

### Variable Reference

| Variable | Group | Required? | Meaning | Example |
| --- | --- | --- | --- | --- |
| `INTERNETX_HOST` | required | Yes | InterNetX/AutoDNS XML API endpoint. | `https://gateway.autodns.com` |
| `INTERNETX_USER` | required | Yes | XML API username used to create and delete the AuthSession. | `my-user` |
| `INTERNETX_PASSWORD` | required | Yes | XML API password used to create and delete the AuthSession. Never logged. | `secret` |
| `INTERNETX_CONTEXT` | required | Yes | XML API auth context. This setup uses `9`. | `9` |
| `TARGET_HOST` | required target selection | Yes, unless `TARGET_HOSTS` is used | One fully qualified host this worker may validate and update. | `subleveldomain.domain.com` |
| `TARGET_HOSTS` | optional multi-target | No | Comma-separated fully qualified hosts that should all use the same detected public IPs in one cycle. Use instead of `TARGET_HOST`. | `app1.example.com,app2.example.com` |
| `TARGET_HOST_ZONES` | optional multi-target | No | Comma-separated `host=zone` mappings for ambiguous multi-target hosts such as `app.example.co.uk`. | `app.example.co.uk=example.co.uk` |
| `ENABLE_IPV4` | core runtime | Yes | Enables IPv4 public IP detection and `A` record update decisions. At least one of IPv4/IPv6 must be enabled. | `true` |
| `ENABLE_IPV6` | core runtime | Yes | Enables IPv6 public IP detection and `AAAA` record update decisions. At least one of IPv4/IPv6 must be enabled. | `false` |
| `PUBLIC_IPV4_PROVIDERS` | core runtime | Yes | Comma-separated public IPv4 detection endpoints. Used only when `ENABLE_IPV4=true`. | `https://api.ipify.org` |
| `PUBLIC_IPV6_PROVIDERS` | core runtime | Yes | Comma-separated public IPv6 detection endpoints. Used only when `ENABLE_IPV6=true`. | `https://api64.ipify.org` |
| `DRY_RUN` | optional runtime/debug | No | Validates config, may run read-only zone preflight, detects public IP, compares state, and logs what would happen without performing a live DNS update. | `true` |
| `FORCE_UPDATE_ON_NO_CHANGE` | optional runtime/debug | No | When `false` (default), skip unnecessary live update requests if the detected public IP is unchanged and all targets are already in sync. When `true`, still allow live update requests in that no-change case. | `false` |
| `PUSHOVER_APP_KEY` | optional notifications | No | Pushover application API token. Required together with `PUSHOVER_USER_KEY` and `PUSHOVER_LOCATION_PREFIX` to enable notifications. | `abc123...` |
| `PUSHOVER_USER_KEY` | optional notifications | No | Pushover user or group key. Required together with `PUSHOVER_APP_KEY` and `PUSHOVER_LOCATION_PREFIX` to enable notifications. | `uQiR...` |
| `PUSHOVER_LOCATION_PREFIX` | optional notifications | No | Short prefix placed at the beginning of the notification message. Legacy `PUSHOVER_LOCATION_NAME` is accepted temporarily as a deprecated fallback. | `Home-Server` |
| `RUN_ONCE` | optional runtime/debug | No | Executes one check cycle and exits instead of looping. | `true` |
| `DEBUG` | optional runtime/debug | No | Prints detailed diagnostics without exposing secrets, including sanitized XML request/response payloads for provider calls. | `true` |
| `TARGET_ZONE` | optional runtime/debug | No | Overrides zone inference from `TARGET_HOST` in single-target mode only. | `example.co.uk` |
| `INTERNETX_SYSTEM_NS` | optional runtime/debug | No | Optional zone inquiry selector for the first system-managed nameserver. Usually unset. | `ns1.routing.net` |
| `STATE_DIR` | optional runtime/debug | No | Directory for persisted `last_ipv4` and `last_ipv6` values. | `/app/state` |
| `CHECK_INTERVAL_SECONDS` | optional runtime/debug | No | Sleep interval for continuous container mode. Ignored when `RUN_ONCE=true`. | `300` |
| `TZ` | optional operability | No | Runtime timezone for application logs, `ip-status.log`, and `health.json` timestamps. | `Europe/Zurich` |
| `IP_STATUS_LOG` | optional operability | No | Optional JSON-lines history file for detected IP status after successful cycles. Disabled when unset. | `/app/state/ip-status.log` |
| `HEALTH_STATUS_FILE` | optional operability | No | JSON status file used by the Docker healthcheck. Defaults to `STATE_DIR/health.json`. | `/app/state/health.json` |
| `HEALTH_MAX_AGE_SECONDS` | optional operability | No | Maximum age accepted by the healthcheck for the last successful cycle. Defaults to about three check intervals plus one minute. | `960` |
| `HTTP_CONNECT_TIMEOUT` | optional runtime/debug | No | HTTP connect timeout for IP and XML requests. | `10` |
| `HTTP_REQUEST_TIMEOUT` | optional runtime/debug | No | Total HTTP request timeout for IP and XML requests. | `20` |
| `LOG_TARGET` | optional runtime/debug | No | PHP stream or file path for logs. | `php://stdout` |

`INTERNETX_*` settings are provider-specific for the current InterNetX XML interface. See [docs/providers/internetx-xml.md](docs/providers/internetx-xml.md) for auth, context, XML templates, `INTERNETX_SYSTEM_NS`, and current provider limitations.

## Logs And Health

By default, runtime logs go to `php://stdout`, so Docker and Docker Compose collect them as normal container logs:

```bash
docker compose logs -f internetx-dyndns
```

Set `LOG_TARGET` only if you want logs written somewhere else, such as `/app/state/internetx-dyndns.log`.

Set `TZ`, for example `TZ=Europe/Zurich`, to use the same runtime timezone for application logs, `ip-status.log`, and `health.json`. Timestamps stay ISO 8601 with an offset.

The worker writes a health status file after each cycle, defaulting to `STATE_DIR/health.json`. Docker healthchecks use that file to verify that the last cycle succeeded and is recent. Set `IP_STATUS_LOG=/app/state/ip-status.log` if you also want an optional JSON-lines history of IPv4/IPv6 status values.

## Safe Local Validation

Use dry-run mode before allowing live DNS updates:

```bash
cp .env.example .env
# edit .env and set INTERNETX_USER, INTERNETX_PASSWORD, and TARGET_HOST or TARGET_HOSTS
docker compose build
docker compose run --rm internetx-dyndns
```

Use `docker compose run --rm internetx-dyndns` when `RUN_ONCE=true`. Do not use `docker compose up -d` for one-shot validation with the default restart policy, because Docker will restart the cleanly exited container and it can look like a failure loop.

The recommended validation flags are:

```env
DRY_RUN=true
RUN_ONCE=true
DEBUG=true
```

- `DRY_RUN=true` validates config, detects the public IP, creates and closes an InterNetX AuthSession, may run read-only InterNetX zone validation, compares state, and logs what would happen without sending a live DNS update.
- `FORCE_UPDATE_ON_NO_CHANGE=false` is the default and avoids unnecessary live update requests when the detected public IP is unchanged and every target already matches that IP.
- `RUN_ONCE=true` executes a single check cycle and exits instead of looping continuously. It is meant for `docker compose run --rm internetx-dyndns`.
- `DEBUG=true` prints detailed diagnostic logging without exposing passwords, session hashes, or sensitive credentials. Provider request/response payloads are sanitized and labeled as `auth_session_create`, `read_only_preflight`, `live_mutation`, or `session_cleanup`.
- With `DEBUG=true`, the worker logs each runtime stage as it moves through config validation, public IP detection, authentication/session preflight, target validation, update decision, live mutation, and session cleanup.
- If `PUSHOVER_APP_KEY`, `PUSHOVER_USER_KEY`, and `PUSHOVER_LOCATION_PREFIX` are all configured, the worker can send a Pushover notification when a real public IP change is detected.

Public IP detection runs before any InterNetX authentication request, so local network/IP behavior is visible even if API login fails later. IPv4 and IPv6 results are logged explicitly:

- If `ENABLE_IPV4=false`, the worker logs `IPv4 detection disabled by configuration`.
- If IPv4 is found, the worker logs `Detected IPv4 address: ...` and `SUCCESS Public IPv4 detection completed`.
- If IPv4 is missing but IPv6 is available, the worker logs `WARNING IPv4 detection failed but IPv6 is available`.
- If `ENABLE_IPV6=false`, the worker logs `IPv6 detection disabled by configuration`.
- If `ENABLE_IPV6=true` and an address is found, the worker logs `Detected IPv6 address: ...` and `SUCCESS Public IPv6 detection completed`.
- If `ENABLE_IPV6=true` but no IPv6 address is detected, the worker logs `WARNING IPv6 detection enabled but no IPv6 address found` and continues if IPv4 is available.
- If neither IPv4 nor IPv6 is detected, the worker logs `ERROR No usable public IP address detected; aborting before authentication` and stops immediately.

Terminal severity markers are colorized when logging to stdout or stderr: debug is dim gray, info is blue, success is green, warning is yellow, and error is red. Yellow means a partial detection problem that can still continue; red means no usable public IP was found and execution stops.

In continuous mode, unchanged cycles are intentionally concise. If a detected address matches the stored state, the worker logs `IPv4 unchanged` or `IPv6 unchanged` without repeating the full address in normal output. New or changed addresses are printed in full. Debug mode still includes the detected values for deeper diagnostics.

Unchanged public IP does not automatically mean every target is already synchronized. The worker still validates the configured targets against current DNS data and only skips live mutation by default when every target is already in sync. Newly added or out-of-sync targets may still require update even if the detected public IP itself is unchanged.

If you set `FORCE_UPDATE_ON_NO_CHANGE=true`, the worker is allowed to continue with a live update request even when the detected public IP itself did not change and all targets are already in sync. Most users should leave this disabled to save unnecessary API requests.

## Pushover Notifications

Pushover support is optional. It is enabled only when all three variables are configured:

```env
PUSHOVER_APP_KEY=your-pushover-app-token
PUSHOVER_USER_KEY=your-pushover-user-key
PUSHOVER_LOCATION_PREFIX=Home-Server
```

Notification behavior:

- notifications are sent only when the detected public IP actually changed
- no notification is sent on unchanged runs
- no notification is sent just because a target was added or was out of sync
- `DRY_RUN=true` suppresses Pushover delivery even when a real IP change was detected
- notification failure does not stop the DNS update workflow

Example message format:

```text
Zurich IPv4 Address: 109.40.176.130
```

```text
Home-Server IPv4 Address: 1.2.3.4
Home-Server IPv6 Address: 2001:db8::1234
```

If only one family changed, only that line is sent. The configured location prefix always appears at the beginning of the message text. For example, `PUSHOVER_LOCATION_PREFIX=Berlin` produces a line starting with `Berlin`.

## Runtime Modes

- Dry-run mode: validates configuration, detects public IPs, performs read-only provider checks, logs what would happen, and suppresses both live DNS mutation and Pushover delivery.
- Live mode: performs DNS updates when required and may send a Pushover notification when a real public IP change is detected.

Example dry-run startup:

```text
[2026-04-23T08:00:00+00:00] DEBUG Runtime stage entered stage=startup/config validation dry_run=true mutation_allowed=false
[2026-04-23T08:00:00+00:00] INFO Execution cycle started dry_run=true run_once=true
[2026-04-23T08:00:00+00:00] INFO Startup validation env_file=present config_source=.env/environment dry_run=true debug=true run_once=true ipv4_enabled=true ipv6_enabled=false
[2026-04-23T08:00:00+00:00] INFO XML authentication configuration api_host=present auth_flow=auth_session session_create_uses_credentials=true follow_up_auth=auth_session username=present password=present context=present
[2026-04-23T08:00:00+00:00] INFO Project DNS target configuration target_host=present system_ns_optional=not_set
[2026-04-23T08:00:00+00:00] INFO Optional runtime settings configured=DRY_RUN,DEBUG,RUN_ONCE
[2026-04-23T08:00:00+00:00] INFO Configuration format validated dry_run_config_sufficient=true
[2026-04-23T08:00:00+00:00] INFO Target host mapping full_host=subleveldomain.domain.com domain=domain.com zone=domain.com subdomain=subleveldomain target_zone_source=inferred_last_two_labels
[2026-04-23T08:00:01+00:00] DEBUG Runtime stage entered stage=public IP detection dry_run=true mutation_allowed=false live_mutation_attempted=false
[2026-04-23T08:00:01+00:00] INFO Starting public IP detection ipv6_enabled=false
[2026-04-23T08:00:01+00:00] INFO Detected IPv4 address: 203.0.113.10 ipv4=203.0.113.10
[2026-04-23T08:00:01+00:00] SUCCESS Public IPv4 detection completed ipv4=203.0.113.10
[2026-04-23T08:00:02+00:00] INFO IPv6 detection disabled by configuration attempted=false
[2026-04-23T08:00:02+00:00] SUCCESS Public IP detection completed with at least one usable address ipv4_detected=true ipv6_detected=false
[2026-04-23T08:00:03+00:00] DEBUG Runtime stage entered stage=authentication/session preflight dry_run=true mutation_allowed=false live_mutation_attempted=false
[2026-04-23T08:00:03+00:00] INFO Starting DNS provider authentication/session preflight provider=InterNetX provider_interface=XML auth_flow=auth_session mutation_allowed=false
[2026-04-23T08:00:03+00:00] DEBUG InterNetX XML request prepared operation=AuthSessionCreate task_code=1321001 api_call_type=auth_session_create stage=authentication/session preflight mutation=false dry_run=true auth_mode=session_create session_established=false payload=<request>...</request>
[2026-04-23T08:00:03+00:00] DEBUG InterNetX XML response received operation=AuthSessionCreate task_code=1321001 api_call_type=auth_session_create stage=authentication/session preflight mutation=false dry_run=true auth_mode=session_create session_established=false http_status=200 transport_success=true response_result_status_code=S1321001 response_result_status_type=success stid=20260423-app1 api_business_success=true payload=<response>...</response>
[2026-04-23T08:00:03+00:00] SUCCESS InterNetX session created auth_mode=auth_session session_hash=9b4b...73dd session_persisted=false
[2026-04-23T08:00:04+00:00] DEBUG Runtime stage entered stage=target/zone validation dry_run=true mutation_allowed=false live_mutation_attempted=false
[2026-04-23T08:00:04+00:00] INFO Validating read-only InterNetX zone access zone=domain.com subdomains=subleveldomain require_a_record=true require_aaaa_record=false mutation=false
[2026-04-23T08:00:04+00:00] DEBUG InterNetX XML request prepared operation=ZoneInfo task_code=0205 api_call_type=read_only_preflight stage=target/zone validation mutation=false dry_run=true auth_mode=auth_session session_established=true payload=<request>...</request>
[2026-04-23T08:00:04+00:00] DEBUG InterNetX XML response received operation=ZoneInfo task_code=0205 api_call_type=read_only_preflight stage=target/zone validation mutation=false dry_run=true auth_mode=auth_session session_established=true http_status=200 transport_success=true response_result_status_code=S0205 response_result_status_type=success stid=20260423-app1 api_business_success=true payload=<response>...</response>
[2026-04-23T08:00:04+00:00] INFO Read-only InterNetX zone access validation successful target_host=subleveldomain.domain.com
[2026-04-23T08:00:05+00:00] DEBUG Runtime stage entered stage=update decision dry_run=true mutation_allowed=false live_mutation_attempted=false
[2026-04-23T08:00:05+00:00] DEBUG Loaded previous state last_ipv4=none last_ipv6=none
[2026-04-23T08:00:05+00:00] DEBUG Update decision update_required=true ipv4_changed=true ipv6_changed=false dry_run=true mutation_allowed=false
[2026-04-23T08:00:05+00:00] INFO Dry-run would require update, but mutation is disabled ipv4=203.0.113.10 ipv4_changed=true update_required=true target_host=subleveldomain.domain.com target_zone=domain.com target_subdomain=subleveldomain intended_action=would_update_dns_records dry_run=true mutation_allowed=false
[2026-04-23T08:00:05+00:00] INFO Dry-run completed; no live mutation sent reason=dry_run_enabled mutation_allowed=false live_mutation_attempted=false
[2026-04-23T08:00:05+00:00] DEBUG Runtime stage entered stage=session cleanup dry_run=true mutation_allowed=false live_mutation_attempted=false
[2026-04-23T08:00:05+00:00] DEBUG InterNetX XML request prepared operation=AuthSessionDelete task_code=1321003 api_call_type=session_cleanup stage=session cleanup mutation=false dry_run=true auth_mode=session_delete session_established=true payload=<request>...</request>
[2026-04-23T08:00:05+00:00] SUCCESS InterNetX session closed auth_mode=session_delete session_hash=9b4b...73dd
```

The read-only access validation uses InterNetX ZoneInfo task `0205`. It can confirm that the credentials can read the configured zone and that the configured record exists. It cannot guarantee every future provider-side update policy will allow mutation, but it is the safest non-mutating preflight this XML workflow provides.

## InterNetX XML API

The current InterNetX XML provider uses:

- provider template `src/Provider/InterNetX/templates/zone-info.xml` with task code `0205` for zone inquiry
- provider template `src/Provider/InterNetX/templates/zone-update.xml` with task code `0202` for zone update
- `AuthSessionCreate` with task code `1321001` to create a session from `user`, `password`, and `context`
- `<auth_session><hash>...</hash></auth_session>` for follow-up zone inquiry and update calls
- `AuthSessionDelete` with task code `1321003` to close the session

The session hash is treated as a secret. It is kept only in memory, never persisted, and redacted in sanitized XML debug logs. Short masked display such as `9b4b...73dd` may appear in operational logs.

The updater does not create missing DNS records. If IPv4 is enabled and detected, the target `A` record must already exist. If IPv6 is enabled and detected, the target `AAAA` record must already exist too.

For the full provider-specific notes, see [docs/providers/internetx-xml.md](docs/providers/internetx-xml.md).

## Verification Direction

The current primary check is provider-side ZoneInfo before update decisions. A future verification pass should stay staged and optional: provider check first, configurable public resolver checks second, and local resolver checks only as a convenience. See [docs/design/verification.md](docs/design/verification.md).

## Docker-Compatible Runtime

Build and run continuously:

```bash
cp .env.example .env
# edit .env and set RUN_ONCE=false
docker compose build
docker compose up -d
docker compose logs -f
```

Use `docker compose up -d` for continuous mode with `RUN_ONCE=false`. The compose file has `restart: unless-stopped`, so `RUN_ONCE=true` will exit successfully and then be started again by Docker.

Run one safe validation cycle:

```bash
cp .env.example .env
# edit .env and keep DRY_RUN=true, RUN_ONCE=true, DEBUG=true
docker compose build
docker compose run --rm internetx-dyndns
```

The compose setup contains one service: `internetx-dyndns`.

The worker is outbound-only in normal operation. It does not run a web server, expose ports, or accept inbound connections. The container only needs outbound HTTPS access to the configured public IP detection providers and the InterNetX/AutoDNS XML API endpoint. The compose configuration intentionally has no `ports:` mapping, uses a read-only root filesystem, drops Linux capabilities, and keeps only `./state:/app/state` writable for persisted IP state.

Container layout:

- base image: `php:8.3-cli-alpine3.22`
- app image: `internetx-dyndns:local`
- release image: `worryboy/internetx-dyndns:0.5.4`
- worker entry point: [`docker/start.sh`](docker/start.sh)
- CLI entry point: [`bin/dyndns.php`](bin/dyndns.php)
- persistent state mount: `./state:/app/state`

Image hardening notes:

- the image keeps only runtime libraries needed for PHP `curl` and `dom`
- extension build dependencies are installed in a temporary `.phpize-deps` package group and removed after build
- the final image does not keep the shell `curl` tool because runtime HTTP requests are performed through the PHP `curl` extension
- some scanner findings may still come from the upstream official `php:8.3-cli-alpine3.22` base image and Alpine base packages such as `tar`, `libcurl`, or `nghttp2`
- those inherited findings are reduced by rebuilding on newer upstream PHP/Alpine base releases when they become available

Docker Desktop is not required. Any Docker-compatible backend that supports the Docker CLI and Compose can run the worker.

## macOS Without Docker Desktop

Colima is one valid option on macOS:
```bash
brew install colima docker docker-compose
colima start
docker version
docker compose version
```

## Linux (Debian/Ubuntu) Without Docker Desktop

Docker Desktop is not required on Linux. A standard Docker Engine + Docker Compose setup is sufficient.
Example on Debian/Ubuntu:

```bash
sudo apt update
sudo apt install -y docker.io docker-compose-plugin git
sudo systemctl enable --now docker
docker version
docker compose version
```

Optional: allow the current user to run Docker without sudo: (change $USER)
```bash
sudo usermod -aG docker "$USER"
newgrp docker
```

## Files Of Interest

- [`VERSION`](VERSION)
- [`CHANGELOG.md`](CHANGELOG.md)
- [`README.Docker.md`](README.Docker.md)
- [`src/Core/DynDnsService.php`](src/Core/DynDnsService.php)
- [`src/Core/PublicIpResolver.php`](src/Core/PublicIpResolver.php)
- [`src/Config/Config.php`](src/Config/Config.php)
- [`src/Provider/DnsProvider.php`](src/Provider/DnsProvider.php)
- [`src/Provider/InterNetX/InterNetXXmlProvider.php`](src/Provider/InterNetX/InterNetXXmlProvider.php)
- [`src/Provider/InterNetX/InterNetXXmlGatewayClient.php`](src/Provider/InterNetX/InterNetXXmlGatewayClient.php)
- [`docs/README.md`](docs/README.md)
- [`docs/providers/internetx-xml.md`](docs/providers/internetx-xml.md)
- [`docs/development/adding-a-provider.md`](docs/development/adding-a-provider.md)
- [`docs/design/verification.md`](docs/design/verification.md)
- [`docs/integrations/traefik-crowdsec.md`](docs/integrations/traefik-crowdsec.md)
- [`.env.example`](.env.example)
- [`docker-compose.yml`](docker-compose.yml)
- [`Dockerfile`](Dockerfile)

## Provenance

This repository descends from [`martinlowinski/php-dyndns`](https://github.com/martinlowinski/php-dyndns) through the small correction fork [`AndLindemann/php-dyndns`](https://github.com/AndLindemann/php-dyndns). The current repository has since been substantially extended and reworked into a container-oriented InterNetX XML DynDNS worker.

Modern additions in this repository include XML `auth_session` handling, dry-run validation, multi-target updates, no-change policy, Pushover notifications, provider/interface separation, Docker packaging, and Traefik/CrowdSec example documentation.

The original author has confirmed that MIT licensing is acceptable and has added MIT licensing to the original upstream. This repository includes an MIT [LICENSE](LICENSE) and a concise [ATTRIBUTION.md](ATTRIBUTION.md).
