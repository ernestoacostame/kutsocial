#!/usr/bin/env bash
set -euo pipefail

mkdir -p /var/www/html/data/uploads /var/www/html/data/cache /var/www/html/data/logs
chown -R www-data:www-data /var/www/html/data

# Worker periódico para procesar cola ActivityPub (cron.php) cada 60s
(
    while true; do
        sleep 60
        php /var/www/html/cron.php >/dev/null 2>&1 || true
    done
) &

php-fpm -D
exec nginx -g "daemon off;"
