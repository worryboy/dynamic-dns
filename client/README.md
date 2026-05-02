# Dynamic DNS Client

This is the placeholder area for a future `dynamic-dns-client` product.

The client is not implemented yet. This directory exists to keep URL-calling client concepts separate from the active provider-integrated worker and the future inbound endpoint.

## Intended Shape

The client would be a URL-based update client / callback-style updater. It would call an update URL exposed by an endpoint or compatible Dynamic DNS service.

Possible future concerns:

- public IP detection or user-supplied address handling
- update URL construction
- HTTP authentication or tokens
- router/script-friendly execution
- retry and response handling
- compatibility with classic Dynamic DNS update URL flows

## Boundary

Client-style assumptions should live here, not in the worker runtime:

- calling an update URL
- router-like callback workflows
- query parameters such as `hostname`, `myip`, `ipaddr`, `ip6addr`, or `token`
- no provider-side zone inspection
- no provider-side record mutation

The current worker remains a provider-integrated runtime. It detects public IPs itself, keeps state, validates configured targets against the DNS provider, and performs provider-side updates as needed.

## Versioning

The client has its own version line in [VERSION](VERSION).

It starts at `0.1.0` as a placeholder structure only. Future implementation work should bump client versions independently from worker, endpoint, and spec versions.
