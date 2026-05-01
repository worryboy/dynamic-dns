# Changelog

## 0.5.4 - Repository Split And Documentation Boundary Cleanup

- Split the repository into `worker/`, `endpoint/`, and `spec/` product/concept areas.
- Moved the active outbound Dynamic DNS worker runtime, Docker files, examples, and worker docs into `worker/`.
- Added a deliberate placeholder `endpoint/` area for future inbound Dynamic DNS endpoint work without implementing endpoint runtime behavior.
- Added a provider-neutral `spec/` area for shared Dynamic DNS behavior notes and verification design material.
- Prepared separate version lines for worker, endpoint, and spec.
- Updated worker docs, compose service names, workflow paths, and image naming guidance toward `dynamic-dns-worker`.
- Moved InterNetX XML runtime templates from the repository root into `src/Provider/InterNetX/templates/`.
- Renamed the XML templates to `zone-info.xml` and `zone-update.xml` to match their provider tasks.
- Moved the Traefik/CrowdSec integration README to `docs/integrations/traefik-crowdsec.md`.
- Removed unused root `robots.txt`.
- Refactored docs to present the generic Dynamic DNS worker separately from the current InterNetX XML provider implementation.
- Added provider-extension documentation for future provider/interface implementations.
- Updated documentation links and release references to `0.5.4`.

## 0.5.2 - Operability Logging And Healthcheck

- Documented default container logging to stdout/stderr and how to view logs with Docker and Docker Compose.
- Added optional `IP_STATUS_LOG` JSON-lines history for detected IP status after successful cycles.
- Added a runtime health status file and Docker/Compose healthchecks that verify the last successful cycle is recent.
- Made application logs, `ip-status.log`, and `health.json` use the runtime `TZ` timezone consistently.
- Updated release references to `0.5.2`.

## 0.5.1 - State Persistence Bugfix

- Persist detected public IP state after successful target validation even when all targets are already in sync and no live DNS update is needed.
- Prevent repeated `first_seen` status and repeated Pushover notifications on unchanged follow-up runs.
- Added clearer state directory, state file, and state save logging.
- Updated release references to `0.5.1`.

## 0.5.0 - Provenance And MIT Alignment

- Clarified the origin chain from `martinlowinski/php-dyndns` through the small `AndLindemann/php-dyndns` correction fork to this repository.
- Added MIT licensing and lightweight attribution documentation.
- Documented that the original author has confirmed MIT licensing is acceptable and has added MIT licensing to the original upstream.
- Updated provenance wording to describe this project as a substantially extended Dynamic DNS worker with InterNetX XML as the current provider/interface implementation rather than a direct copy of the earlier script.
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

- Container-only Dynamic DNS worker for InterNetX XML.
- Session-based InterNetX XML authentication with explicit session cleanup.
- Safe `DRY_RUN`, `RUN_ONCE`, and `DEBUG` modes.
- Configurable IPv4/IPv6 public IP detection.
- Single explicit `TARGET_HOST` update target with `A` and `AAAA` record handling.
- Outbound-only container runtime with no exposed ports.
