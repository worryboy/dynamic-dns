# Dynamic DNS Worker

Provider-integrated Dynamic DNS worker for IPv4/IPv6 DNS updates.

This image is a provider-integrated Dynamic DNS worker. It detects public IPs itself, keeps state, validates configured targets against the DNS provider, and performs provider-side updates as needed. It is not a simple URL-calling DynDNS client.

Current release: `0.5.5`

Current provider/interface implementation:

- InterNetX / AutoDNS / SchlundTech-related DNS
- InterNetX XML
- XML `auth_session`

Full documentation:

- GitHub repository: https://github.com/worryboy/dynamic-dns
- Worker README: https://github.com/worryboy/dynamic-dns/blob/main/worker/README.md
- InterNetX XML provider notes: https://github.com/worryboy/dynamic-dns/blob/main/worker/docs/providers/internetx-xml.md
- Traefik/CrowdSec example: https://github.com/worryboy/dynamic-dns/blob/main/worker/docs/integrations/traefik-crowdsec.md

## What It Does

- runs as an outbound-only PHP CLI worker
- detects public IPv4 and optional IPv6 addresses
- validates configured DNS targets against the provider before update decisions
- updates existing `A` and `AAAA` records when needed
- stores last successful IP state in `/app/state`
- supports `DRY_RUN=true` for safe validation before live DNS updates
- exposes no inbound ports

## Product Model

- `dynamic-dns-worker`: provider-integrated runtime that detects, validates, stores state, and updates DNS provider records.
- `dynamic-dns-client`: future URL-based update client / callback-style updater. Not implemented yet.
- `dynamic-dns-endpoint`: future inbound server-side update endpoint. Not implemented yet.
- `dynamic-dns-spec`: shared behavior, contracts, scenarios, and verification notes.

## Tags

- `worryboy/dynamic-dns-worker:0.5.6` - versioned release
- `worryboy/dynamic-dns-worker:latest` - latest published stable image

Use a versioned tag for repeatable deployments.

Deprecated image name: `worryboy/internetx-dyndns`.

The old provider-specific image name is retained only as a deprecated compatibility/provenance reference. New deployments should use `worryboy/dynamic-dns-worker`.

Recommended deprecation text for the old Docker Hub repository:

```text
Deprecated: this image has moved to worryboy/dynamic-dns-worker. Please update new deployments to use worryboy/dynamic-dns-worker:<version> or worryboy/dynamic-dns-worker:latest. The old worryboy/internetx-dyndns name is retained only for compatibility/provenance.
```

## Light Compose Example

Create `.env` from the GitHub example, fill in credentials and targets, then run:

```yaml
services:
  dynamic-dns-worker:
    image: worryboy/dynamic-dns-worker:0.5.6
    container_name: dynamic-dns-worker
    restart: unless-stopped
    read_only: true
    cap_drop:
      - ALL
    security_opt:
      - no-new-privileges:true
    env_file:
      - .env
    tmpfs:
      - /tmp
    volumes:
      - ./state:/app/state
```

For one safe validation cycle, set these values in `.env`:

```env
DRY_RUN=true
RUN_ONCE=true
DEBUG=true
```

Then run:

```bash
docker compose run --rm dynamic-dns-worker
```

For continuous updates, set:

```env
DRY_RUN=false
RUN_ONCE=false
DEBUG=false
```

Then run:

```bash
docker compose up -d
docker compose logs -f dynamic-dns-worker
```

## Minimal Required Configuration

```env
INTERNETX_HOST=https://gateway.autodns.com
INTERNETX_USER=your-api-user
INTERNETX_PASSWORD=your-api-password
INTERNETX_CONTEXT=9
TARGET_HOST=dyndns.example.com
ENABLE_IPV4=true
ENABLE_IPV6=false
```

For multiple names on the same host:

```env
TARGET_HOSTS=app1.example.com,app2.example.com,app3.example.com
```

Optional Pushover notifications:

```env
PUSHOVER_APP_KEY=your-pushover-app-token
PUSHOVER_USER_KEY=your-pushover-user-key
PUSHOVER_LOCATION_PREFIX=Home-Server
```

All three values are required to enable notifications. If any of them are missing, Pushover remains disabled.
`PUSHOVER_LOCATION_PREFIX` is placed at the beginning of the notification message. The legacy `PUSHOVER_LOCATION_NAME` variable is accepted temporarily as a deprecated fallback, but `PUSHOVER_LOCATION_PREFIX` wins if both are set.

Example:

```text
Zurich IPv4 Address: 109.40.176.130
```

Common runtime flags:

```env
DRY_RUN=true
FORCE_UPDATE_ON_NO_CHANGE=false
RUN_ONCE=true
DEBUG=true
```

Do not commit `.env`. It contains credentials and runtime secrets.

`TARGET_HOST` must be the full hostname to update in single-target mode, for example `dyndns.example.com`. The worker derives:

- full host: `dyndns.example.com`
- zone/domain: `example.com`
- host label: `dyndns`

Set `TARGET_ZONE=example.co.uk` only when the zone cannot be inferred from the last two labels in single-target mode.

For multi-target mode:

- use `TARGET_HOSTS` with a comma-separated list of full hostnames
- all configured hosts receive the same detected public IPv4/IPv6 values in the same cycle
- the worker authenticates once, reuses one session, validates all targets, and updates only the targets that need changes
- this is useful when one host serves several public names behind a reverse proxy such as Traefik
- add `TARGET_HOST_ZONES=host=zone,host=zone` for ambiguous multi-target names such as `app.example.co.uk`

Examples:

```env
TARGET_HOST=home.example.co.uk
TARGET_ZONE=example.co.uk
```

```env
TARGET_HOSTS=app.example.co.uk,service.example.com.au
TARGET_HOST_ZONES=app.example.co.uk=example.co.uk,service.example.com.au=example.com.au
```

Automatic last-two-label inference still works for normal names such as `app.example.com`, but the worker now fails early for common ambiguous ccTLD-style suffix patterns instead of guessing the wrong zone.

## DNS Records

- IPv4 updates use each target host's `A` record.
- IPv6 updates use each target host's `AAAA` record.

The worker updates existing records only. Create the relevant `A` and/or `AAAA` records in InterNetX before enabling live updates for every configured target.

TTL is not managed by this worker. Set TTL on the DNS record in InterNetX/AutoDNS. A TTL around `300` seconds is a common starting point for DynDNS-style records.

## Persistent State Directory

Mount a persistent host directory or Docker volume to `/app/state`.

The worker stores the last detected IPv4 and IPv6 values there. That persisted state prevents unnecessary update attempts on every cycle when the public IP has not changed across all configured targets.

Without a persistent state directory, the worker starts each run without prior IP state and may perform extra comparison or update work that would otherwise be skipped.

## Quick Start With Docker Hub

Pull the image:

```bash
docker pull worryboy/dynamic-dns-worker:latest
```

Create a local `.env` file:

```bash
cp .env.example .env
# edit .env
```

Create a persistent state directory:

```bash
mkdir -p state
```

Run one safe dry-run validation:

```bash
docker run --rm \
  --env-file .env \
  --read-only \
  --cap-drop ALL \
  --security-opt no-new-privileges:true \
  --tmpfs /tmp \
  -v "$(pwd)/state:/app/state" \
  worryboy/dynamic-dns-worker:0.5.5
```

For Compose validation with `RUN_ONCE=true`, use:

```bash
docker compose run --rm dynamic-dns-worker
```

Do not use `docker compose up -d` for `RUN_ONCE=true` validation with the default restart policy. The worker exits cleanly after one cycle, then Docker starts it again because `restart: unless-stopped` is intended for continuous mode.

Run continuously:

```bash
docker run -d \
  --name dynamic-dns-worker \
  --restart unless-stopped \
  --read-only \
  --cap-drop ALL \
  --security-opt no-new-privileges:true \
  --env-file .env \
  --tmpfs /tmp \
  -v "$(pwd)/state:/app/state" \
  worryboy/dynamic-dns-worker:0.5.5
```

Start with the Docker-Hub-oriented Compose file:

```bash
# set RUN_ONCE=false in .env first
docker compose -f docker-compose.hub.yml up -d
```

For a special one-host / many-hostname reverse-proxy scenario with Traefik and CrowdSec, see [docs/integrations/traefik-crowdsec.md](docs/integrations/traefik-crowdsec.md). That example is intentionally separate from the standard deployment model and uses `.env.dns` so it does not collide with an existing Traefik/CrowdSec stack `.env`.

## Logs And Health

By default, application logs go to `php://stdout`, so Docker captures them as normal container logs. View them with:

```bash
docker logs -f dynamic-dns-worker
```

or with Compose:

```bash
docker compose logs -f dynamic-dns-worker
```

Set `LOG_TARGET` only if you want the PHP logger to write somewhere else, for example a file inside the writable state mount:

```env
LOG_TARGET=/app/state/dynamic-dns-worker.log
```

Set `TZ` to make runtime timestamps match your local operations timezone:

```env
TZ=Europe/Zurich
```

Application logs, `ip-status.log`, and `health.json` all use the same ISO 8601 timestamp format with offset.

The container healthcheck reads a small JSON status file. By default, the worker writes it to `/app/state/health.json` after each cycle. A healthy result means the last cycle succeeded and is recent enough for the configured check interval. A failing result means the last cycle failed, the status file is missing, or the last success is stale.

The image includes this healthcheck:

```dockerfile
HEALTHCHECK --interval=60s --timeout=10s --start-period=120s --retries=3 CMD php /app/bin/healthcheck.php
```

Compose files in this repository use the same check:

```yaml
healthcheck:
  test: ["CMD", "php", "/app/bin/healthcheck.php"]
  interval: 60s
  timeout: 10s
  start_period: 120s
  retries: 3
```

Optional IP status history can be enabled with:

```env
IP_STATUS_LOG=/app/state/ip-status.log
```

That file is JSON lines, one entry per successful cycle, with timestamp, IPv4/IPv6 values, and status values such as `first_seen`, `changed`, or `unchanged`.

## Dry-Run Support

Use dry-run mode before enabling live updates:

```env
DRY_RUN=true
RUN_ONCE=true
DEBUG=true
```

In dry-run mode, the worker may:

- validate configuration
- detect public IPv4/IPv6
- create and close an InterNetX session
- run read-only zone validation
- compare detected IPs with stored state
- log what would happen

It never sends a live DNS mutation while `DRY_RUN=true`.
With `RUN_ONCE=true`, prefer `docker compose run --rm dynamic-dns-worker`.

For normal long-running updates, switch to:

```env
DRY_RUN=false
FORCE_UPDATE_ON_NO_CHANGE=false
RUN_ONCE=false
DEBUG=false
```

`FORCE_UPDATE_ON_NO_CHANGE=false` is the default and avoids unnecessary live update requests when the detected public IP is unchanged and all configured targets already match that IP. Leave it disabled unless you explicitly want the worker to continue with a live update request even in that no-change case.

## Pushover Notifications

Pushover support is optional and enabled only when all three values are configured:

```env
PUSHOVER_APP_KEY=your-pushover-app-token
PUSHOVER_USER_KEY=your-pushover-user-key
PUSHOVER_LOCATION_PREFIX=Home-Server
```

Behavior:

- notifications are sent only when the detected public IP actually changed
- no notification is sent on unchanged runs
- no notification is sent just because a target was added or was out of sync
- `DRY_RUN=true` suppresses notification delivery even when a real IP change was detected
- notification delivery failure is logged, but it does not block the DNS update workflow

Example message:

```text
Zurich IPv4 Address: 109.40.176.130
```

```text
Home-Server IPv4 Address: 1.2.3.4
Home-Server IPv6 Address: 2001:db8::1234
```

If only one IP family changed, only that line is included.
The configured `PUSHOVER_LOCATION_PREFIX`, for example `Berlin`, always appears at the beginning of each notification line.

## Runtime Modes

- Dry-run mode validates and simulates. It does not send live DNS updates and it suppresses Pushover delivery.
- Live mode performs DNS updates when required and may send Pushover notifications on real public IP change.

## IPv4 And IPv6 Behavior

IPv4 and IPv6 detection are configured independently:

- `ENABLE_IPV4=true|false`
- `ENABLE_IPV6=true|false`

At least one address family must be enabled.

If an enabled address family cannot be detected, the worker logs a warning and continues only if another usable public IP address exists. If no usable IPv4 or IPv6 address is detected, execution stops before InterNetX authentication.

An unchanged detected public IP does not automatically mean nothing needs to happen. The worker still validates every configured target against current DNS values. Newly added or out-of-sync targets may still require update even when the detected public IP itself is unchanged.

## Security Notes

- No inbound ports are exposed or required.
- Passwords and session hashes are never logged raw.
- Debug XML request/response logs are sanitized.
- The InterNetX session hash is kept only in memory.
- The container only needs a writable `/app/state` volume and temporary `/tmp`.
- The hardened Compose setup uses a read-only root filesystem, drops Linux capabilities, and enables `no-new-privileges`.
- The image removes build-only packages after compiling PHP extensions and keeps only the required runtime libraries.
- Some vulnerability scanner findings may still be inherited from the upstream official `php:8.3-cli-alpine3.22` base image and Alpine runtime packages. Those are usually resolved by rebuilding on newer upstream base releases when fixes land there.

## Release Notes

### 0.5.5

Repository rename and worker image transition release:

- confirmed `worryboy/dynamic-dns-worker` as the official worker Docker image
- marked `worryboy/internetx-dyndns` as a deprecated compatibility/provenance image name
