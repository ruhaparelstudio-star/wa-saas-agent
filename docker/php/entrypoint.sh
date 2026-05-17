#!/bin/sh
set -e

cd /var/www/html

if [ ! -f "vendor/autoload.php" ]; then
    composer install --no-dev --optimize-autoloader
fi

if [ -z "$APP_KEY" ] || [ "$APP_KEY" = "base64:" ]; then
    php artisan key:generate
fi

php artisan migrate --force --no-interaction

php artisan storage:link --no-interaction 2>/dev/null || true

# Ensure storage and cache dirs are writable by www-data (FPM process user).
# Volume mounts from the host preserve host ownership (UID 1000), so we chmod
# instead of chown to avoid altering host filesystem ownership.
chmod -R 777 storage/ bootstrap/cache/

exec "${@:-php-fpm}"
