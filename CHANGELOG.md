# Changelog

## 0.3.0 - Multi-Target And Notifications

- Multi-target DynDNS updates for one-host / many-hostname setups, with one session and one public IP detection pass per cycle.
- Configurable no-change update policy so unchanged runs skip unnecessary live update requests by default.
- Optional Pushover notifications for real public IP changes, with `DRY_RUN=true` continuing to suppress delivery.
- Clearer runtime logging around detected IP changes, target sync state, and end-of-run summaries.
- Documentation and release workflow updates for the container-first runtime.

## 0.1.0 - Initial Release

- Container-only InterNetX DynDNS worker.
- Session-based InterNetX XML authentication with explicit session cleanup.
- Safe `DRY_RUN`, `RUN_ONCE`, and `DEBUG` modes.
- Configurable IPv4/IPv6 public IP detection.
- Single explicit `TARGET_HOST` update target with `A` and `AAAA` record handling.
- Outbound-only container runtime with no exposed ports.
