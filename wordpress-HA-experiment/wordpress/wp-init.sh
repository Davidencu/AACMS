#!/bin/bash
# One-shot job: wait for the database, install WordPress once, then exit.
# Safe to re-run: an existing installation is left untouched.
set -Eeuo pipefail

export HOME=/tmp
WP=(wp --path=/usr/src/wordpress)

: "${WORDPRESS_ADMIN_USER:?}" "${WORDPRESS_ADMIN_PASSWORD:?}" "${WORDPRESS_ADMIN_EMAIL:?}"

echo "[wp-init] waiting for the database via ${WORDPRESS_DB_HOST}"
for i in $(seq 1 60); do
    if php -r '
        mysqli_report(MYSQLI_REPORT_OFF);
        [$h, $p] = explode(":", getenv("WORDPRESS_DB_HOST")) + [1 => 3306];
        $m = mysqli_init(); $m->options(MYSQLI_OPT_CONNECT_TIMEOUT, 2);
        exit(@$m->real_connect($h, getenv("WORDPRESS_DB_USER"), getenv("WORDPRESS_DB_PASSWORD"), getenv("WORDPRESS_DB_NAME"), (int)$p) ? 0 : 1);
    '; then
        break
    fi
    [ "$i" = 60 ] && { echo "[wp-init] database never became reachable" >&2; exit 1; }
    sleep 2
done

if "${WP[@]}" core is-installed 2>/dev/null; then
    echo "[wp-init] WordPress is already installed, nothing to do"
else
    echo "[wp-init] installing WordPress at ${WORDPRESS_URL}"
    "${WP[@]}" core install \
        --url="${WORDPRESS_URL}" \
        --title="${WORDPRESS_TITLE:-WordPress HA}" \
        --admin_user="${WORDPRESS_ADMIN_USER}" \
        --admin_password="${WORDPRESS_ADMIN_PASSWORD}" \
        --admin_email="${WORDPRESS_ADMIN_EMAIL}" \
        --skip-email
fi

# Idempotent settings (stored in the database, so they apply to all nodes)
"${WP[@]}" option update permalink_structure '/%postname%/' >/dev/null
"${WP[@]}" plugin activate redis-cache 2>&1 || true

echo "[wp-init] done"
