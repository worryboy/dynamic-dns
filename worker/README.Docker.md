# Dynamic DNS Worker

Provider-integrated Dynamic DNS worker for IPv4/IPv6 DNS updates.

This image is a provider-integrated Dynamic DNS worker. It detects public IPs itself, keeps state, validates configured targets against the DNS provider, and performs provider-side updates as needed. It is not a simple URL-calling DynDNS client.

Current release: `0.5.6`

Official image: `worryboy/dynamic-dns-worker`

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
TARGET_HOSTS=app.example.com,blog.example.com,vpn.example.com
```

Create the relevant `A` and/or `AAAA` records in InterNetX/AutoDNS before live updates. The worker updates existing records; it does not create missing records.

## Release Notes

### 0.5.6

Public documentation and product-structure cleanup release:

- shortened Docker Hub-facing documentation
- clarified worker vs. client vs. endpoint product models
- added the `dynamic-dns-client` placeholder concept
- refreshed worker release-facing examples for `0.5.6`

### 0.5.5

Repository rename and worker image transition release:

- confirmed `worryboy/dynamic-dns-worker` as the official worker Docker image
- marked `worryboy/internetx-dyndns` as a deprecated compatibility/provenance image name
