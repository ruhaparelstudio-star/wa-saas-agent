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

exec php-fpm
