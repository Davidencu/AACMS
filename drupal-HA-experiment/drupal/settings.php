<?php

/**
 * @file
 * Settings shared by every Drupal node. Mounted read-only; all values come
 * from environment variables so the three containers are identical.
 */

$databases['default']['default'] = [
  'driver' => 'pgsql',
  'namespace' => 'Drupal\\pgsql\\Driver\\Database\\pgsql',
  'autoload' => 'core/modules/pgsql/src/Driver/Database/pgsql/',
  'database' => getenv('DRUPAL_DB_NAME'),
  'username' => getenv('DRUPAL_DB_USER'),
  'password' => getenv('DRUPAL_DB_PASSWORD'),
  'host' => getenv('DRUPAL_DB_HOST'),
  'port' => '5432',
  'prefix' => '',
];

// Must be identical on every node (it signs sessions, form tokens, one-time links).
$settings['hash_salt'] = getenv('DRUPAL_HASH_SALT');

// Shared volume, not inside the web root.
$settings['config_sync_directory'] = '/opt/drupal/private/sync';
$settings['file_private_path'] = '/opt/drupal/private/files';

// Only answer for these Host headers. "localhost" is always allowed because
// the container and HAProxy health checks use it.
$settings['trusted_host_patterns'] = array_map(
  fn ($h) => '^' . preg_quote($h, '/') . '$',
  array_values(array_unique(array_merge(
    array_map('trim', explode(',', getenv('DRUPAL_TRUSTED_HOSTS') ?: '')),
    ['localhost']
  )))
);

// Behind HAProxy: take the client IP and scheme from X-Forwarded-*. The nodes
// publish no ports and sit on a private Docker network, so the only peer that
// can reach them is HAProxy and its address is trusted as the proxy.
if (PHP_SAPI !== 'cli' && !empty($_SERVER['REMOTE_ADDR'])) {
  $settings['reverse_proxy'] = TRUE;
  $settings['reverse_proxy_addresses'] = [$_SERVER['REMOTE_ADDR']];
}

$settings['update_free_access'] = FALSE;
$settings['entity_update_batch_size'] = 50;
