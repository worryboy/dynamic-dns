#!/bin/sh
set -eu

mkdir -p "${STATE_DIR:-/app/state}"

if [ "${RUN_ONCE:-false}" = "true" ] || [ "${RUN_ONCE:-false}" = "1" ]; then
  exec php /app/bin/dyndns.php
fi

while true; do
  php /app/bin/dyndns.php || true
  sleep "${CHECK_INTERVAL_SECONDS:-300}"
done
