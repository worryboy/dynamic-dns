#!/bin/sh
set -eu

mkdir -p "${STATE_DIR:-/app/state}"

while true; do
  php /app/bin/dyndns.php || true
  sleep "${CHECK_INTERVAL_SECONDS:-300}"
done

