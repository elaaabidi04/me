#!/bin/sh
# Container entrypoint (see Dockerfile / docs/deploy.md).
set -e
cd /app

mkdir -p storage/framework/sessions storage/framework/views storage/framework/cache storage/logs bootstrap/cache

php artisan config:cache
php artisan migrate --force

# AI replies + extraction run on the queue; restart the worker every hour
# so it never runs stale or leaks memory
( while true; do php artisan queue:work --tries=3 --max-time=3600 --sleep=2; sleep 1; done ) &

# hourly demo:prune (sandboxes older than 24h)
php artisan schedule:work &

exec php artisan serve --host=0.0.0.0 --port="${PORT:-8080}"
