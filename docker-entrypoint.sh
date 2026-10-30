#!/bin/bash
set -e

echo "[entrypoint] Waiting for database..."
until php -r "
  \$dsn = 'pgsql:host=' . getenv('DB_HOST') . ';port=' . (getenv('DB_PORT') ?: 5432) . ';dbname=' . getenv('DB_DATABASE');
  new PDO(\$dsn, getenv('DB_USERNAME'), getenv('DB_PASSWORD'));
  echo 'ok';
" 2>/dev/null | grep -q ok; do
  sleep 2
done
echo "[entrypoint] Database is ready."

# Generate APP_KEY if not provided
if [ -z "$APP_KEY" ]; then
  echo "[entrypoint] Generating APP_KEY..."
  php artisan key:generate --force
fi

# Ensure writable framework directories exist (excluded from Docker build by .dockerignore)
# Must run BEFORE config:cache so realpath() in config/view.php resolves correctly.
mkdir -p storage/framework/views storage/framework/sessions storage/framework/cache/data
chown -R www-data:www-data storage/framework/views storage/framework/sessions storage/framework/cache
chmod -R 775 storage/framework/views storage/framework/sessions storage/framework/cache

# Cache config/routes for production
php artisan config:cache
php artisan route:cache
php artisan event:cache

# Run migrations automatically
php artisan migrate --force

# Prune expired Sanctum tokens (tokens older than sanctum.expiration)
php artisan sanctum:prune-expired --hours=24

echo "[entrypoint] Starting queue worker (route geometry regeneration)..."
# queue:work exits on purpose after --max-time (and on a fatal error); without this loop nothing queued would ever run
# again, and a route redraw would wait forever.
(
  while true; do
    php artisan queue:work --sleep=3 --tries=1 --max-time=3600
    echo "[entrypoint] Queue worker stopped (exit $?), restarting in 3 seconds..."
    sleep 3
  done
) &

# The scheduled jobs in routes/console.php (delayed-trip push, release of expired payment holds, deferred refunds,
# nightly reports) only run if something calls the scheduler every minute. Without this they never ran in the container.
echo "[entrypoint] Starting scheduler (routes/console.php)..."
(
  while true; do
    php artisan schedule:work
    echo "[entrypoint] Scheduler stopped (exit $?), restarting in 3 seconds..."
    sleep 3
  done
) &

echo "[entrypoint] Starting Laravel on 0.0.0.0:8000..."
# --no-reload lets PHP_CLI_SERVER_WORKERS serve several requests at once (Laravel ignores it otherwise).
exec php artisan serve --host=0.0.0.0 --port=8000 --no-reload
