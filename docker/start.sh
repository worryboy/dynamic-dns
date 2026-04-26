#!/bin/sh
set -eu

mkdir -p "${STATE_DIR:-/app/state}"

if [ "${RUN_ONCE:-false}" = "true" ] || [ "${RUN_ONCE:-false}" = "1" ]; then
  echo "RUN_ONCE=true: executing one cycle and exiting. With Docker Compose, prefer 'docker compose run --rm internetx-dyndns'; 'docker compose up -d' with a restart policy will start it again." >&2
  exec php /app/bin/dyndns.php
fi

while true; do
  php /app/bin/dyndns.php || true
  sleep "${CHECK_INTERVAL_SECONDS:-300}"
done
