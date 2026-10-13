#!/bin/sh
set -eu

mkdir -p \
    /var/cloud-drive/storage \
    /var/www/html/writable/cache \
    /var/www/html/writable/debugbar \
    /var/www/html/writable/logs \
    /var/www/html/writable/session \
    /var/www/html/writable/uploads

chown -R www-data:www-data /var/cloud-drive /var/www/html/writable 2>/dev/null || true

exec "$@"
