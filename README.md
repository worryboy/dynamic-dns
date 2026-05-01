# Dynamic DNS

This repository is being prepared for a provider-neutral Dynamic DNS layout.

It is split into three product/concept areas:

| Area | Purpose | Status |
| --- | --- | --- |
| [`spec/`](spec/) | Shared Dynamic DNS behavior, scenarios, contracts, and test-oriented notes. | Starter structure |
| [`worker/`](worker/) | Outbound Dynamic DNS worker runtime. This contains the current working implementation. | Active |
| [`endpoint/`](endpoint/) | Future inbound Dynamic DNS endpoint product area. | Placeholder |

The current working product is the worker. It currently implements InterNetX XML for InterNetX / AutoDNS / SchlundTech-related environments, but the repository direction is provider-oriented and provider-neutral.

## Product Names

Recommended naming direction:

- Repository family: `dynamic-dns`
- Worker product: `dynamic-dns-worker`
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

## Endpoint

The endpoint area is intentionally incomplete. It is reserved for a future inbound HTTP-style Dynamic DNS endpoint, for example a service that receives update requests from routers or clients and then applies provider updates.

See [endpoint/README.md](endpoint/README.md).

## Spec

The spec area is for behavior that should be shared across worker and endpoint models: target semantics, update decisions, verification stages, fixtures, and future contract tests.

See [spec/README.md](spec/README.md).

## Repository Metadata Suggestions

Recommended repository name: `dynamic-dns`

Recommended GitHub description:

```text
Provider-oriented Dynamic DNS toolkit with an outbound worker, future endpoint area, and shared behavior specs.
```

Recommended topics:

```text
dynamic-dns, dyndns, dns, dns-updater, docker, php, worker, provider-interface, autodns, internetx
```

Image naming:

- Official worker image: `worryboy/dynamic-dns-worker`
- Future endpoint image: `dynamic-dns-endpoint`
- Existing image names such as `worryboy/internetx-dyndns` are deprecated compatibility references. New deployments should use `worryboy/dynamic-dns-worker`.

## Versioning

Each product area has its own version line:

- Worker: [worker/VERSION](worker/VERSION)
- Endpoint: [endpoint/VERSION](endpoint/VERSION)
- Spec: [spec/VERSION](spec/VERSION)

Recommended release tag prefixes:

- `worker-v0.5.5`
- `endpoint-v0.1.0`
- `spec-v0.1.0`

Do not assume a worker release implies an endpoint or spec release.

Worker release:

```bash
git tag worker-v0.5.5
git push origin worker-v0.5.5
```

## License And Attribution

This repository uses MIT; see [LICENSE](LICENSE).

Origin and provenance are documented in [ATTRIBUTION.md](ATTRIBUTION.md).
