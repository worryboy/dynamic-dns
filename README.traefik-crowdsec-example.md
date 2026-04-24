# Traefik + CrowdSec Example

This is a special example deployment pattern, not the default way to run `internetx-dyndns`.

It is useful when one public host runs several services behind Traefik and several DNS names should always point to that same host IP. In that setup, the DynDNS worker keeps the DNS side aligned so Traefik can serve the expected hostnames and certificate flows can start from a sensible DNS baseline.

Source code is available on GitHub: [worryboy/internetx-dyndns](https://github.com/worryboy/internetx-dyndns)
Container image is available on Docker Hub: [worryboy/internetx-dyndns](https://hub.docker.com/r/worryboy/internetx-dyndns)

## What This Example Includes

- `internetx-dyndns`
- `traefik`
- `crowdsec`

It does not include an extra demo app such as `whoami`. The goal is to show the operational pattern, not to build a full demo stack.

## Why DynDNS Helps Here

In a one-host / many-hostname reverse proxy setup, several public DNS names may need to follow the same host IP:

```env
TARGET_HOSTS=app1.example.com,app2.example.com
```

That lets one host front multiple names through Traefik while CrowdSec protects the edge.

## Important Boundaries

- `internetx-dyndns` stays outbound-only.
- It is not exposed through Traefik.
- It has no Traefik routing labels.
- It opens no inbound listener.

The DynDNS worker updates DNS. Traefik handles HTTP/TLS ingress. CrowdSec handles security decisions. They are related operationally, but the DynDNS worker itself is not a routed web service.

## Startup Order

The example Compose file starts `internetx-dyndns` before Traefik and CrowdSec by using `depends_on` on those services.

That reflects the intended operational order, but it is only container startup ordering. It does **not** guarantee external DNS propagation. DNS propagation remains external to Docker Compose and may still take time after the DynDNS worker starts.

## Prepare `.env`

Copy the project template and fill in your real values:

```bash
cp .env.example .env
```

For this example, a typical `.env` shape is:

```env
INTERNETX_HOST=https://gateway.autodns.com
INTERNETX_USER=your-api-user
INTERNETX_PASSWORD=your-api-password
INTERNETX_CONTEXT=9

TARGET_HOSTS=app1.example.com,app2.example.com

ENABLE_IPV4=true
ENABLE_IPV6=false

DRY_RUN=false
FORCE_UPDATE_ON_NO_CHANGE=false
RUN_ONCE=false
DEBUG=false

PUSHOVER_APP_KEY=your-pushover-app-token
PUSHOVER_USER_KEY=your-pushover-user-key
PUSHOVER_LOCATION_NAME=Berlin
```

Notes:

- `TARGET_HOSTS` lists several DNS names that should all point to the same host IP.
- `PUSHOVER_LOCATION_NAME=Berlin` is used at the beginning of the notification message.
- Do not commit `.env`.

## Run The Example

Create the required local directories:

```bash
mkdir -p state letsencrypt crowdsec/config crowdsec/data
touch letsencrypt/acme.json
chmod 600 letsencrypt/acme.json
```

Start the stack:

```bash
docker compose -f docker-compose.traefik-crowdsec-example.yml up -d
```

Follow the logs:

```bash
docker compose -f docker-compose.traefik-crowdsec-example.yml logs -f
```

## How This Differs From Standard Docker Deployment

Standard Docker usage is documented in [README.Docker.md](/Users/worker/DEV/internetx-dyndns/README.Docker.md) and focuses only on the DynDNS worker.

This example adds:

- Traefik for hostname-based routing and TLS
- CrowdSec as a colocated edge-security component
- a multi-target DynDNS pattern where several DNS names are expected to resolve to the same host

Use this example only if that is actually your deployment shape.
