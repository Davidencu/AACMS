#!/bin/bash
# Singleton scheduler. Normal WordPress nodes run with DISABLE_WP_CRON, so
# scheduled events run exactly once here instead of once per node.
set -u

export HOME=/tmp
INTERVAL="${WP_CRON_INTERVAL:-60}"

echo "[wp-cron] running due events every ${INTERVAL}s"
while true; do
    wp --path=/usr/src/wordpress cron event run --due-now 2>&1 || echo "[wp-cron] run failed, retrying next cycle"
    sleep "$INTERVAL"
done
