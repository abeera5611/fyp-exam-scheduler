#!/bin/sh
set -e
cd /var/www/html

# With the bind mount the host folder replaces /var/www/html, so make sure
# dependencies and .env exist.
composer config --global audit.block-insecure false || true
composer config --global policy.advisories.block false || true
[ -d vendor ] || composer install --no-interaction --prefer-dist --no-security-blocking --no-scripts
[ -f artisan ] && php artisan package:discover --ansi || echo 'WARNING: artisan file not found in project root'
if [ ! -f .env ]; then
  [ -f .env.example ] && cp .env.example .env || touch .env
  php artisan key:generate --force || true
fi
mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

exec docker-php-entrypoint "$@"
