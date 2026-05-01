# Dynamic DNS Endpoint

This is the placeholder area for a future `dynamic-dns-endpoint` product.

The endpoint is not implemented yet. This directory exists to keep endpoint concepts separate from the working outbound worker.

## Intended Shape

The endpoint would be an inbound service that receives Dynamic DNS update requests from clients such as routers, scripts, or other automation.

Possible future concerns:

- HTTP request authentication
- request parsing and validation
- query/body semantics for IPv4, IPv6, hostnames, and credentials
- abuse/rate limiting
- mapping endpoint requests to provider updates
- provider-neutral response contracts

No endpoint behavior is currently promised by the worker.

## Boundary

Endpoint-style assumptions should live here, not in the worker docs or runtime:

- router-calls-update-URL workflows
- `update.php`-style scripts
- query parameters such as `ipaddr`, `ip6addr`, `domain`, or `pass`
- inbound HTTP routing or exposed ports
- classic DynDNS endpoint compatibility notes

The current worker remains outbound-only and does not expose HTTP ports.

## Versioning

The endpoint has its own version line in [VERSION](VERSION).

It starts at `0.1.0` as a placeholder structure only. Future implementation work should bump endpoint versions independently from worker and spec versions.
