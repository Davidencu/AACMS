#!/bin/bash
# /var/www/html is a tmpfs, so the WordPress code is copied fresh from the image
# on every start. Only wp-content/uploads is a shared volume; make it writable.
set -Eeuo pipefail

if [ "$(id -u)" = 0 ] && [[ "${1-}" == apache2* ]]; then
    mkdir -p /var/www/html/wp-content/uploads
    chown www-data:www-data /var/www/html/wp-content/uploads
fi

exec docker-entrypoint.sh "$@"
