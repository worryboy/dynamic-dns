# Dynamic DNS

This repository is being prepared as a provider-neutral Dynamic DNS product family.

It is split into four product/concept areas:

| Area | Purpose | Status |
| --- | --- | --- |
| [`worker/`](worker/) | Provider-integrated Dynamic DNS worker runtime. This contains the current working implementation. | Active |
| [`client/`](client/) | Future URL-based update client / callback-style updater. | Placeholder |
| [`endpoint/`](endpoint/) | Future inbound Dynamic DNS endpoint product area. | Placeholder |
| [`spec/`](spec/) | Shared Dynamic DNS behavior, scenarios, contracts, and test-oriented notes. | Starter structure |

The current working product is the worker. It currently implements InterNetX XML for InterNetX / AutoDNS / SchlundTech-related environments, but the repository direction is provider-oriented and provider-neutral.

## Runtime Models

This image is a provider-integrated Dynamic DNS worker. It detects public IPs itself, keeps state, validates configured targets against the DNS provider, and performs provider-side updates as needed. It is not a simple URL-calling DynDNS client.

- `dynamic-dns-worker`: outbound provider-integrated runtime that detects public IPs, stores state, validates DNS provider state, and updates provider-side records.
- `dynamic-dns-client`: future URL-based update client or callback-style updater that calls an update URL. It is not implemented yet.
- `dynamic-dns-endpoint`: future inbound server-side endpoint that receives update requests from clients and maps them to provider updates. It is not implemented yet.
- `dynamic-dns-spec`: shared behavior, contracts, scenarios, and verification notes for the family.

## Product Names

Recommended naming direction:

- Repository family: `dynamic-dns`
- Worker product: `dynamic-dns-worker`
- Client product: `dynamic-dns-client`
- Endpoint product: `dynamic-dns-endpoint`
- Behavior/spec space: `dynamic-dns-spec`

The older `internetx-dyndns` naming is deprecated. It may remain only in compatibility references, historical release notes, and attribution/provenance history.

## Current Worker

Build and run the worker from [`worker/`](worker/):

```bash
cd worker
cp .env.example .env
# edit .env
docker compose build
docker compose run --rm dynamic-dns-worker
```

For continuous mode:

```bash
cd worker
# set RUN_ONCE=false in .env first
docker compose up -d
docker compose logs -f dynamic-dns-worker
```

Worker docs:

- [Worker README](worker/README.md)
- [Worker Docker README](worker/README.Docker.md)
- [Current provider: InterNetX XML](worker/docs/providers/internetx-xml.md)
- [Adding a provider](worker/docs/development/adding-a-provider.md)
- [Traefik/CrowdSec integration](worker/docs/integrations/traefik-crowdsec.md)

## Client

The client area is intentionally incomplete. It is reserved for a future URL-based update client / callback-style updater that calls an update URL.

See [client/README.md](client/README.md).

## Endpoint

The endpoint area is intentionally incomplete. It is reserved for a future inbound HTTP-style Dynamic DNS endpoint, for example a service that receives update requests from routers, scripts, or the future client and then applies provider updates.

See [endpoint/README.md](endpoint/README.md).

## Spec

The spec area is for behavior that should be shared across worker, client, and endpoint models: target semantics, update decisions, verification stages, fixtures, and future contract tests.

See [spec/README.md](spec/README.md).

## Repository Metadata Suggestions

Recommended repository name: `dynamic-dns`

Recommended GitHub description:

```text
Provider-neutral Dynamic DNS toolkit with an active provider-integrated worker, future client and endpoint areas, and shared specs.
```

Recommended topics:

```text
dynamic-dns, dyndns, dns, dns-updater, docker, php, worker, client, endpoint, provider-interface, autodns, internetx
```

Recommended Docker Hub short description for `worryboy/dynamic-dns-worker`:

```text
Provider-integrated Dynamic DNS worker for IPv4/IPv6 DNS updates.
```

Recommended deprecation text for the old `worryboy/internetx-dyndns` image:

```text
Deprecated: this image has moved to worryboy/dynamic-dns-worker. Please update new deployments to use worryboy/dynamic-dns-worker:<version> or worryboy/dynamic-dns-worker:latest.
```

Image naming:

- Official worker image: `worryboy/dynamic-dns-worker`
- Future client image: `dynamic-dns-client`
- Future endpoint image: `dynamic-dns-endpoint`
- Existing image names such as `worryboy/internetx-dyndns` are deprecated compatibility references. New deployments should use `worryboy/dynamic-dns-worker`.

## Versioning

Each product area has its own version line:

- Worker: [worker/VERSION](worker/VERSION)
- Client: [client/VERSION](client/VERSION)
- Endpoint: [endpoint/VERSION](endpoint/VERSION)
- Spec: [spec/VERSION](spec/VERSION)

Recommended release tag prefixes:

- `worker-v0.5.6`
- `client-v0.1.0`
- `endpoint-v0.1.0`
- `spec-v0.1.0`

Do not assume a worker release implies an endpoint or spec release.

Worker release:

```bash
git tag worker-v0.5.6
git push origin worker-v0.5.6
```

## License And Attribution

This repository uses MIT; see [LICENSE](LICENSE).

Origin and provenance are documented in [ATTRIBUTION.md](ATTRIBUTION.md).
