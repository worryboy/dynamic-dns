# InterNetX XML Provider

Current provider: InterNetX / AutoDNS / SchlundTech-related DNS service.
Current interface: XML.

The worker uses the XML `auth_session` flow:

- create a session with username, password, and context
- use the session hash for read-only ZoneInfo and live ZoneUpdate requests
- close the session at the end of the cycle

## Required Env

```env
INTERNETX_HOST=https://gateway.autodns.com
INTERNETX_USER=your-api-user
INTERNETX_PASSWORD=your-api-password
INTERNETX_CONTEXT=9
```

Target selection still belongs to the generic worker config:

```env
TARGET_HOST=dyndns.example.com
```

or:

```env
TARGET_HOSTS=app1.example.com,app2.example.com
```

## Optional Provider Env

```env
INTERNETX_SYSTEM_NS=ns.example.com
```

`INTERNETX_SYSTEM_NS` maps to the XML Zone object field `<system_ns>` during read-only zone inquiry. Most DynDNS runs leave it unset.

## Context Handling

`INTERNETX_CONTEXT` is required for the current XML session login. This project defaults the example value to `9`, matching the existing working setup. If your InterNetX/AutoDNS account uses a different context, set it explicitly.

## Authentication And TLS Notes

The current InterNetX XML implementation uses username, password, and context only to create an `auth_session`. It does not implement a separate 2FA flow, and no optional advanced authentication variables are currently supported.

All built-in HTTPS requests keep TLS peer and host verification enabled. This applies to InterNetX XML API calls, public IP detection providers, and optional Pushover notifications.

## Assumptions And Limits

- The worker updates existing `A` and `AAAA` records; it does not create missing records.
- Runtime XML templates live with the provider implementation under `src/Provider/InterNetX/templates/`.
- `zone-info.xml` is the InterNetX XML ZoneInfo task `0205` template.
- `zone-update.xml` is the InterNetX XML ZoneUpdate task `0202` template.
- `DRY_RUN=true` permits read-only provider validation but blocks live mutation.
- The session hash is kept only in memory and is redacted in debug logs.
- `DEBUG=true` logs sanitized XML request and response payloads for provider calls; passwords, usernames, and session hashes are redacted.
- Future InterNetX interfaces, for example JSON, should be added as a separate provider interface implementation rather than changing the XML client in place.
