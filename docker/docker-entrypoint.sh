#!/bin/sh
set -eu

mkdir -p /var/www/html/storage/db /var/www/html/storage/uploads /var/www/html/storage/tmp
chown -R www-data:www-data /var/www/html/storage

exec "$@"
