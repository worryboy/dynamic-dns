# InterNetX DynDNS Docker Image

Containerized InterNetX DynDNS worker for IPv4/IPv6-aware DNS updates.

This image runs as a long-lived outbound-only worker. It detects the current public IPv4 and/or IPv6 address once per cycle, validates one configured `TARGET_HOST` or multiple `TARGET_HOSTS`, and updates existing `A` and `AAAA` records through the InterNetX/AutoDNS XML API when the address changes.

Current release: `0.4.0`

Source code is available on GitHub: [worryboy/internetx-dyndns](https://github.com/worryboy/internetx-dyndns)
Container image is available on Docker Hub: [worryboy/internetx-dyndns](https://hub.docker.com/r/worryboy/internetx-dyndns)

## What The Container Does

- runs a PHP CLI worker, not a web server
- uses the InterNetX/AutoDNS XML gateway, default `https://gateway.autodns.com`
- creates an InterNetX XML `auth_session` at the beginning of each run
- uses the session hash for read and update requests in that run
- closes the session before exit or at the end of each cycle
- can update multiple DNS hostnames to the same detected host IP in one run
- supports safe `DRY_RUN=true` validation before live DNS updates
- stores last detected IP state in `/app/state`

## Runtime Model

This project is container-only. The image only needs outbound HTTPS access to:

- configured public IP detection providers
- the InterNetX/AutoDNS XML API endpoint

No inbound ports are required. The worker does not listen on any port, and no `ports:` mapping is needed.

## Image Tags

- `worryboy/internetx-dyndns:0.4.0` - versioned release
- `worryboy/internetx-dyndns:latest` - latest published stable image

Use a versioned tag for repeatable deployments. Use `latest` if you want the newest published stable image.

## Create `.env` Manually

Create a local `.env` file before starting the container. You can copy `.env.example` as a template:

```bash
cp .env.example .env
```

## Env Example Files

| File | Purpose |
| --- | --- |
| `.env.example` | Default example config for the normal DynDNS worker. |
| `.env.dns.example` | DNS-only example config for the Traefik/CrowdSec integration example. |

The separate DNS env file avoids collisions with an existing Traefik/CrowdSec stack `.env`, such as reverse-proxy, CrowdSec, certificate, or dashboard settings.

Fill in these values manually:

```env
INTERNETX_HOST=https://gateway.autodns.com
INTERNETX_USER=your-api-user
INTERNETX_PASSWORD=your-api-password
INTERNETX_CONTEXT=9
TARGET_HOST=dyndns.example.com
ENABLE_IPV4=true
ENABLE_IPV6=false
```

For multi-target mode, use:

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
docker pull worryboy/internetx-dyndns:latest
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
  worryboy/internetx-dyndns:0.4.0
```

Run continuously:

```bash
docker run -d \
  --name internetx-dyndns \
  --restart unless-stopped \
  --read-only \
  --cap-drop ALL \
  --security-opt no-new-privileges:true \
  --env-file .env \
  --tmpfs /tmp \
  -v "$(pwd)/state:/app/state" \
  worryboy/internetx-dyndns:0.4.0
```

Start with the Docker-Hub-oriented Compose file:

```bash
docker compose -f docker-compose.hub.yml up -d
```

For a special one-host / many-hostname reverse-proxy scenario with Traefik and CrowdSec, see [README.traefik-crowdsec-example.md](README.traefik-crowdsec-example.md). That example is intentionally separate from the standard deployment model and uses `.env.dns` so it does not collide with an existing Traefik/CrowdSec stack `.env`.

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

### 0.4.0

Example and documentation release:

- dedicated Traefik/CrowdSec DynDNS example
- DNS-specific `.env.dns` separation for the example
- reference alignment with the goNeuland Traefik/CrowdSec guide
- `PUSHOVER_LOCATION_PREFIX` examples for IP change notifications

### 0.3.0

Feature release:

- container-only InterNetX DynDNS worker
- session-based InterNetX XML authentication
- explicit session cleanup
- safe dry-run mode
- configurable IPv4/IPv6 public IP detection
- single-target and multi-target update model
- optional Pushover notifications for actual public IP changes
- `A` record updates for IPv4
- `AAAA` record updates for IPv6
- outbound-only runtime with no exposed ports

## Provenance

This repository originated from the earlier PHP DynDNS project [`AndLindemann/php-dyndns`](https://github.com/AndLindemann/php-dyndns) and has since been significantly adapted, refactored, and extended for the current container-oriented InterNetX worker model.

The current repository is not just a direct copy of that earlier upstream; it has been substantially reworked compared to the original starting point.
