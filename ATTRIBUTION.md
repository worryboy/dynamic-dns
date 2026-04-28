# Attribution

This project descends from the PHP DynDNS script lineage:

1. Original upstream: [martinlowinski/php-dyndns](https://github.com/martinlowinski/php-dyndns)
2. Intermediary correction fork: [AndLindemann/php-dyndns](https://github.com/AndLindemann/php-dyndns)
3. Current project: this repository

The intermediary fork appears to contain a small correction on top of the original upstream. This repository has since been substantially extended and reworked into a container-oriented InterNetX XML DynDNS worker with `auth_session` handling, dry-run validation, multi-target updates, Pushover notifications, provider/interface separation, Docker packaging, and Traefik/CrowdSec example documentation.

The original author has confirmed that MIT licensing is acceptable and has added MIT licensing to the original upstream repository. This repository uses MIT; see [LICENSE](LICENSE).
