# Changelog

## 0.5.0 - Provenance And MIT Alignment

- Clarified the origin chain from `martinlowinski/php-dyndns` through the small `AndLindemann/php-dyndns` correction fork to this repository.
- Added MIT licensing and lightweight attribution documentation.
- Documented that the original author has confirmed MIT licensing is acceptable and has added MIT licensing to the original upstream.
- Updated provenance wording to describe this project as a substantially extended InterNetX XML DynDNS worker rather than a direct copy of the earlier script.
- Updated release references to `0.5.0`.

## 0.4.0 - Provider Interface And Traefik/CrowdSec Example

- Separated generic worker flow from DNS provider/interface code.
- Made the current provider/interface explicit: InterNetX / AutoDNS / SchlundTech-related DNS via the InterNetX XML interface.
- Added InterNetX XML as the first concrete provider implementation behind the provider contract.
- Added provider documentation for InterNetX XML `auth_session` configuration and limits.
- Added a lightweight verification design note for provider checks, optional public resolver checks, and optional local resolver checks.
- Added a dedicated Traefik/CrowdSec DynDNS example for one-host / many-hostname deployments.
- Added `.env.dns.example` and wired the example compose file to `.env.dns` to keep DNS settings separate from the Traefik/CrowdSec stack `.env`.
- Documented how the example extends, but does not replace, the goNeuland Traefik/CrowdSec guide and migration note.
- Updated example documentation for outbound-only DynDNS operation, Compose startup ordering, DNS propagation limits, and `PUSHOVER_LOCATION_PREFIX=Berlin`.
- Clarified `RUN_ONCE=true` usage with Docker Compose and added a startup warning for restart-policy loops.
- Improved Dockerfile package/install hygiene and kept the expensive PHP extension build layer cacheable.
- Updated release references to `0.4.0`.

## 0.3.0 - Multi-Target And Notifications

- Multi-target DynDNS updates for one-host / many-hostname setups, with one session and one public IP detection pass per cycle.
- Configurable no-change update policy so unchanged runs skip unnecessary live update requests by default.
- Optional Pushover notifications for real public IP changes, with `PUSHOVER_LOCATION_PREFIX` naming and `DRY_RUN=true` continuing to suppress delivery.
- Clearer runtime logging around detected IP changes, target sync state, and end-of-run summaries.
- Documentation and release workflow updates for the container-first runtime.

## 0.1.0 - Initial Release

- Container-only InterNetX DynDNS worker.
- Session-based InterNetX XML authentication with explicit session cleanup.
- Safe `DRY_RUN`, `RUN_ONCE`, and `DEBUG` modes.
- Configurable IPv4/IPv6 public IP detection.
- Single explicit `TARGET_HOST` update target with `A` and `AAAA` record handling.
- Outbound-only container runtime with no exposed ports.
