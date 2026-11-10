#!/bin/sh
# Container entrypoint (see Dockerfile / docs/deploy.md).
set -e
cd /app

mkdir -p storage/framework/sessions storage/framework/views storage/framework/cache storage/logs bootstrap/cache

php artisan config:cache
php artisan route:cache
php artisan event:cache
php artisan view:cache
php artisan migrate --force

# the /admin account, from ADMIN_EMAIL + ADMIN_PASSWORD (Render has no shell
# on the free plan). Skipped when ADMIN_PASSWORD isn't set; never blocks boot.
# diagnostic only: says whether the vars exist, never their values
if [ -n "$ADMIN_EMAIL" ]; then ae="set"; else ae="MISSING"; fi
if [ -n "$ADMIN_PASSWORD" ]; then ap="set, ${#ADMIN_PASSWORD} chars"; else ap="MISSING"; fi
echo "admin check: ADMIN_EMAIL $ae · ADMIN_PASSWORD $ap"
if [ -n "$ADMIN_PASSWORD" ]; then
  php artisan admin:create --from-env || echo "admin:create skipped"
fi

# AI replies + extraction run on the queue; restart the worker every hour
# so it never runs stale or leaks memory
( while true; do php artisan queue:work --tries=3 --max-time=3600 --sleep=2; sleep 1; done ) &

# hourly demo:prune (sandboxes older than 24h) + every minute demo:fill-pool
# (ready-made demo copies, so a click logs in instantly). Lower priority
# than the web server: building copies must never slow a visitor's click.
nice -n 10 php artisan schedule:work &
# fill the pool right away instead of waiting for the first minute tick
nice -n 10 php artisan demo:fill-pool >/dev/null 2>&1 &

# PHP's built-in server straight (not `artisan serve`, which hides
# Laravel's errors from the Render log), 4 parallel workers. It must run
# from public/ -- Laravel's router script uses the current directory.
cd /app/public
exec php -S 0.0.0.0:"${PORT:-8080}" /app/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
