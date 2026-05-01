# Adding A Provider

This project is meant to keep the Dynamic DNS worker core separate from concrete DNS provider APIs.

The current implementation is:

- Provider: InterNetX
- Interface: XML
- Implementation path: `src/Provider/InterNetX/`

Future work can add another provider, or another interface for the same provider, without rewriting the worker flow.

## Provider Versus Interface

A provider is the DNS service or platform, for example `InterNetX`.

An interface is the API shape used for that provider, for example `XML`. One provider may eventually have more than one interface:

- `InterNetX XML`
- future `InterNetX JSON`
- another provider with its own REST API

Name implementations so both parts are visible. For example:

```text
src/Provider/InterNetX/InterNetXXmlProvider.php
src/Provider/InterNetX/InterNetXXmlGatewayClient.php
```

## What Stays Generic

Keep these concerns in the worker core:

- public IP detection
- single-target and multi-target parsing
- state loading and saving
- no-change update decisions
- dry-run behavior
- optional notifications
- runtime logging and health status

Provider implementations should not decide how the main cycle works. They should only expose provider-specific operations to the worker.

## Provider Contract

New providers should implement `DnsProvider`.

The provider layer needs to expose:

- provider name
- interface/protocol name
- auth model name
- open/close session or equivalent setup and cleanup
- target inspection / current DNS state lookup
- live update execution

If an API has no explicit session, `open()` and `close()` can still be lightweight no-ops, but keep the contract shape so the worker does not need provider-specific branches.

## Suggested File Layout

Use one directory per provider:

```text
src/Provider/ExampleDns/
```

For an API-specific implementation, use explicit class names:

```text
src/Provider/ExampleDns/ExampleDnsRestProvider.php
src/Provider/ExampleDns/ExampleDnsRestClient.php
```

If the provider needs request templates or schema files, keep them inside the provider directory:

```text
src/Provider/ExampleDns/templates/
```

Do not put provider runtime templates in the repository root.

## Configuration

Keep environment-based configuration as the primary model.

Provider-specific variables should be clearly prefixed, for example:

```env
EXAMPLEDNS_API_URL=
EXAMPLEDNS_API_TOKEN=
```

Generic worker variables should remain provider-neutral:

```env
TARGET_HOST=
TARGET_HOSTS=
ENABLE_IPV4=
ENABLE_IPV6=
DRY_RUN=
RUN_ONCE=
STATE_DIR=
```

Add provider-specific validation close to config loading, but avoid pushing provider-specific decisions into the generic worker loop.

## Lookup And Update Flow

A provider implementation should support this sequence:

1. Authenticate or prepare the API client.
2. Inspect the configured targets without mutation.
3. Return current `A` and `AAAA` values for each target.
4. Execute live updates only when the worker asks for them.
5. Clean up sessions or temporary auth state.

Dry-run mode may still perform read-only provider validation, but it must not send a live mutation.

## Documentation To Add

For a new provider or interface, add:

- `docs/providers/<provider>-<interface>.md`
- required provider env variables
- auth model and assumptions
- record types supported
- dry-run behavior
- limitations and known provider-specific quirks

If there is an integration example, place it under:

```text
docs/integrations/
```

Update `docs/README.md` and the root `README.md` with short links. Keep detailed provider behavior in provider docs rather than duplicating it in the main README.
