#!/bin/bash
# Wrapper around the official Drupal entrypoint, identical on all three nodes.
#
# On first start every node races for a lock on the shared volume. The winner
# installs Drupal into the shared database; the others wait for the lock, see
# that Drupal is installed and skip the install. Apache only starts after that,
# so a node never serves a half-installed site.
set -Eeuo pipefail

SITES=/opt/drupal/web/sites/default
PRIVATE=/opt/drupal/private

# Volumes are created root-owned; Apache and the installer run as www-data.
mkdir -p "$SITES/files" "$PRIVATE/files" "$PRIVATE/sync"
chown www-data:www-data "$SITES/files" "$PRIVATE" "$PRIVATE/files" "$PRIVATE/sync"

exec 9>"$PRIVATE/.install.lock"
flock 9
echo "[drupal] $(hostname): checking installation"
runuser -u www-data -- php -d memory_limit=512M /ha/install.php
# Release the lock and do not leak the descriptor into Apache.
exec 9>&-

exec docker-php-entrypoint "$@"
