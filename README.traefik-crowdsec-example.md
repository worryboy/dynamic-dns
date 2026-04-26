# Traefik + CrowdSec DynDNS Example

This is a special `internetx-dyndns` deployment pattern for one-host / many-hostname reverse-proxy setups. It is an extension for DNS automation, not a replacement for a full Traefik/CrowdSec setup guide.

Use the goNeuland Traefik/CrowdSec guide as the baseline for Traefik, CrowdSec, middleware, dashboard, certificate, and AppSec configuration:

- Main guide: [Traefik ab v3.6 mit CrowdSec installieren und konfigurieren](https://goneuland.de/traefik-ab-v3-6-mit-crowdsec-installieren-und-konfigurieren/)
- Migration/update note: [Migration 001: Traefik ab v3.6 mit CrowdSec installieren und konfigurieren](https://goneuland.de/migration-001-traefik-ab-v3-6-mit-crowdsec-installieren-und-konfigurieren/)

This repository adds the DNS side: `internetx-dyndns` keeps several public DNS names aligned with the current public IP before those names are expected to work through Traefik.

## What This Adds

- an `internetx-dyndns` helper service
- a dedicated DNS env file, `.env.dns`
- multi-target DNS updates through `TARGET_HOSTS`
- optional Pushover notification prefix `PUSHOVER_LOCATION_PREFIX=Berlin`
- startup ordering that starts the DNS worker before Traefik and CrowdSec

The DynDNS container remains outbound-only. It has no published ports, no Traefik routing labels, and no inbound listener.

## Differences From The Referenced Traefik/CrowdSec Guide

- The goNeuland guide owns the Traefik/CrowdSec stack design; this example only adds a DynDNS helper service.
- DNS credentials and target names live in `.env.dns`, not in the Traefik/CrowdSec `.env`.
- `internetx-dyndns` is not exposed through Traefik and does not join HTTP routing.
- Multiple DNS names can point to the same host IP with `TARGET_HOSTS`.
- Pushover can report real public IP changes if `PUSHOVER_APP_KEY`, `PUSHOVER_USER_KEY`, and `PUSHOVER_LOCATION_PREFIX` are configured.

## Why A Separate DNS Env File

| File | Purpose |
| --- | --- |
| `.env.example` | Default example config for the normal DynDNS worker. |
| `.env.dns.example` | DNS-only example config for this Traefik/CrowdSec integration example. |

Traefik/CrowdSec stacks commonly already have their own `.env` for reverse-proxy, CrowdSec, certificate, and dashboard settings. Keeping DynDNS settings in `.env.dns` avoids collisions and makes the DNS secrets easier to spot.

## Prepare `.env.dns`

Create the DNS-specific env file:

```bash
cp .env.dns.example .env.dns
```

Then edit `.env.dns`:

```env
INTERNETX_HOST=https://gateway.autodns.com
INTERNETX_USER=your-api-user
INTERNETX_PASSWORD=your-api-password
INTERNETX_CONTEXT=9

TARGET_HOSTS=app1.example.com,app2.example.com,traefik.example.com

ENABLE_IPV4=true
ENABLE_IPV6=false

DRY_RUN=true
FORCE_UPDATE_ON_NO_CHANGE=false
RUN_ONCE=true
DEBUG=true

PUSHOVER_APP_KEY=your-pushover-app-token
PUSHOVER_USER_KEY=your-pushover-user-key
PUSHOVER_LOCATION_PREFIX=Berlin
```

Start with `DRY_RUN=true` and `RUN_ONCE=true`. After validation, switch to:

```env
DRY_RUN=false
RUN_ONCE=false
DEBUG=false
```

`TARGET_HOSTS` should list the public hostnames Traefik will serve from the same machine. If a hostname has an ambiguous public suffix such as `example.co.uk`, add `TARGET_HOST_ZONES=host=zone,host=zone`.

## Run The Example

Create local state and Traefik/CrowdSec directories if you are using this example compose file directly:

```bash
mkdir -p state letsencrypt crowdsec/config crowdsec/data
touch letsencrypt/acme.json
chmod 600 letsencrypt/acme.json
```

Validate the DNS worker first:

```bash
docker compose -f docker-compose.traefik-crowdsec-example.yml run --rm internetx-dyndns
```

Start the stack:

```bash
docker compose -f docker-compose.traefik-crowdsec-example.yml up -d
```

Follow logs:

```bash
docker compose -f docker-compose.traefik-crowdsec-example.yml logs -f
```

## Operational Ordering

The compose file declares Traefik and CrowdSec after `internetx-dyndns` with `depends_on`. This expresses the intended order: DNS should be checked before Traefik-dependent hostnames and certificate flows are expected to work.

Docker Compose startup order is not DNS propagation. It does not guarantee that public resolvers, browser clients, or certificate authorities can already see the new records. DNS propagation remains external to Compose and depends on authoritative DNS behavior, resolver caching, and record TTLs.

## Why DynDNS Is Not Routed Through Traefik

`internetx-dyndns` is a CLI worker. It talks outbound to public IP providers and the InterNetX/AutoDNS XML API. It does not serve HTTP traffic, so exposing it through Traefik would add attack surface without adding functionality.

Keep it as an internal helper service:

- no `ports:` mapping
- no `traefik.enable=true`
- no Traefik router labels
- persistent state mounted only at `/app/state`

## Compose File Scope

`docker-compose.traefik-crowdsec-example.yml` is intentionally small. It shows where the DynDNS worker fits and keeps the Traefik/CrowdSec details minimal. For a production Traefik/CrowdSec stack, follow the referenced guide and migration note, then add the `internetx-dyndns` service and `.env.dns` pattern from this repository.
