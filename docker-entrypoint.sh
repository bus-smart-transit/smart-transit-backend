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

# Cache config/routes for production
php artisan config:cache
php artisan route:cache
php artisan event:cache

# Run migrations automatically
php artisan migrate --force

# Prune expired Sanctum tokens (tokens older than sanctum.expiration)
php artisan sanctum:prune-expired --hours=24

echo "[entrypoint] Starting Laravel on 0.0.0.0:8000..."
exec php artisan serve --host=0.0.0.0 --port=8000
