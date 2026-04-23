# InterNetX DynDNS Docker Image

Container-only DynDNS worker for InterNetX/AutoDNS XML API accounts.

The container detects the current public IPv4 and/or IPv6 address, validates one explicitly configured DNS target, and updates existing `A` and `AAAA` records when the detected address changes.

Current release: `0.1.0`

## What The Container Does

- runs a PHP CLI worker, not a web server
- uses the InterNetX/AutoDNS XML gateway, default `https://gateway.autodns.com`
- creates an InterNetX XML `auth_session` at the beginning of each run
- uses the session hash for zone inquiry and update requests
- closes the session before exit or at the end of each cycle
- supports safe `DRY_RUN=true` validation before live DNS updates
- stores last detected IP state in `/app/state`

## Supported Runtime

This project is container-only.

The image requires outbound HTTPS access to:

- configured public IP detection providers
- the InterNetX/AutoDNS XML API endpoint

No inbound ports are required. The worker does not expose HTTP, does not listen for requests, and does not need a `ports:` mapping.

## Supported Tags

- `worryboy/internetx-dyndns:0.1.0` - initial release
- `worryboy/internetx-dyndns:latest` - latest published stable image

Use a versioned tag for repeatable deployments.

## Required Environment

```env
INTERNETX_HOST=https://gateway.autodns.com
INTERNETX_USER=your-api-user
INTERNETX_PASSWORD=your-api-password
INTERNETX_CONTEXT=9
TARGET_HOST=dyndns.example.com

ENABLE_IPV4=true
ENABLE_IPV6=false
PUBLIC_IPV4_PROVIDERS=https://api.ipify.org,https://ipv4.icanhazip.com,https://ifconfig.me/ip
PUBLIC_IPV6_PROVIDERS=https://api64.ipify.org,https://ipv6.icanhazip.com
```

`INTERNETX_CONTEXT=9` is the expected context for this account setup. Keep `INTERNETX_HOST=https://gateway.autodns.com` unless InterNetX provides a different XML gateway endpoint for your account.

`TARGET_HOST` must be the full hostname to update, for example `dyndns.example.com`. The worker infers:

- full host: `dyndns.example.com`
- zone/domain: `example.com`
- host label: `dyndns`

Set `TARGET_ZONE=example.co.uk` only when the zone cannot be inferred from the last two labels of `TARGET_HOST`.

## DNS Records

- IPv4 updates use the target host's `A` record.
- IPv6 updates use the target host's `AAAA` record.

The worker updates existing records. Create the relevant `A` and/or `AAAA` records in InterNetX before live use.

TTL is not configured by this worker. Set TTL on the DNS record in InterNetX/AutoDNS. A TTL around `300` seconds is a common starting point for DynDNS-style records.

## Dry-Run Validation

Use dry-run mode before enabling live updates:

```env
DRY_RUN=true
RUN_ONCE=true
DEBUG=true
```

Run one local validation cycle:

```bash
docker run --rm --env-file .env -v "$(pwd)/state:/app/state" worryboy/internetx-dyndns:0.1.0
```

In dry-run mode, the worker may:

- validate configuration
- detect public IPv4/IPv6
- create and close an InterNetX session
- run read-only zone validation
- compare detected IPs with stored state
- log what would happen

It never sends a live DNS mutation while `DRY_RUN=true`.

## Live Update Usage

After a successful dry-run, set:

```env
DRY_RUN=false
RUN_ONCE=false
DEBUG=false
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
  worryboy/internetx-dyndns:0.1.0
```

Compose usage from this repository:

```bash
cp .env.example .env
# edit .env
docker compose up -d --build
docker compose logs -f
```

## IPv4 And IPv6 Behavior

IPv4 and IPv6 detection are configured independently:

- `ENABLE_IPV4=true|false`
- `ENABLE_IPV6=true|false`

At least one address family must be enabled.

If an enabled address family cannot be detected, the worker logs a warning and continues only if another usable public IP address exists. If no usable IPv4 or IPv6 address is detected, execution stops before InterNetX authentication.

## Security Notes

- No inbound ports are exposed or required.
- Passwords and session hashes are never logged raw.
- Debug XML request/response logs are sanitized.
- The InterNetX session hash is kept only in memory.
- The container should only need a writable `/app/state` volume and temporary `/tmp`.
- The compose file uses a read-only root filesystem, drops Linux capabilities, and enables `no-new-privileges`.
- The image removes build-only packages after compiling PHP extensions and keeps only the required runtime libraries.
- Some vulnerability scanner findings may still be inherited from the upstream official `php:8.3-cli-alpine3.22` base image and Alpine runtime packages. Those are usually resolved by rebuilding on newer upstream base releases when fixes land there.


