#!/usr/bin/env sh
# Render container startup: migrate, seed once, then serve.
set -e

echo "[entrypoint] clearing stale config cache"
php artisan config:clear || true

echo "[entrypoint] running migrations"
php artisan migrate --force

# Seed only on a fresh database (idempotent guard on the puzzles table).
PUZZLES=$(php artisan tinker --execute="echo DB::table('puzzles')->count();" 2>/dev/null | tr -cd '0-9')
echo "[entrypoint] puzzles currently in db: ${PUZZLES:-0}"
if [ -z "$PUZZLES" ] || [ "$PUZZLES" = "0" ]; then
  echo "[entrypoint] empty database -> seeding"
  php artisan db:seed --force || echo "[entrypoint] WARNING: seeding failed, continuing"
fi

echo "[entrypoint] caching config"
php artisan config:cache || true

echo "[entrypoint] starting server on 0.0.0.0:${PORT:-10000}"
exec php artisan serve --host 0.0.0.0 --port "${PORT:-10000}"
