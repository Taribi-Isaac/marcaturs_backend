#!/usr/bin/env sh
set -eu

cd /var/www/html

if [ ! -f .env ] && [ -n "${APP_KEY:-}" ]; then
  # ECS/Secrets Manager injects env vars; Laravel reads getenv — no .env file required.
  :
fi

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
  echo "Running database migrations (RUN_MIGRATIONS=true)…"
  php artisan migrate --force --no-interaction
fi

php artisan config:cache || true
php artisan route:cache || true
php artisan event:cache || true

exec "$@"
