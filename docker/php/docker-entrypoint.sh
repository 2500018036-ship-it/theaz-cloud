#!/bin/sh
set -eu

mkdir -p \
    /var/cloud-drive/storage \
    /var/www/html/writable/cache \
    /var/www/html/writable/debugbar \
    /var/www/html/writable/logs \
    /var/www/html/writable/session \
    /var/www/html/writable/uploads

chown www-data:www-data /var/cloud-drive/storage /var/www/html/writable \
    /var/www/html/writable/cache /var/www/html/writable/debugbar \
    /var/www/html/writable/logs /var/www/html/writable/session /var/www/html/writable/uploads

exec "$@"
