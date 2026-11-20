<?php

/**
 * @file
 * Unattended Drupal install (standard profile) against the shared PostgreSQL
 * database. Does nothing if the site is already installed. Always called under
 * a lock by entrypoint.sh, so only one node installs.
 */

function env(string $name): string {
  $value = getenv($name);
  if ($value === FALSE || $value === '') {
    fwrite(STDERR, "[drupal] $name is required\n");
    exit(1);
  }
  return $value;
}

// Installed means the admin account (uid 1) exists. A missing table
// (SQLSTATE 42P01) means "not installed yet"; any other error is fatal.
try {
  $pdo = new PDO(
    sprintf('pgsql:host=%s;port=5432;dbname=%s', env('DRUPAL_DB_HOST'), env('DRUPAL_DB_NAME')),
    env('DRUPAL_DB_USER'),
    env('DRUPAL_DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
  );
  if ($pdo->query('SELECT 1 FROM users_field_data WHERE uid = 1')->fetchColumn()) {
    echo "[drupal] already installed, skipping\n";
    exit(0);
  }
}
catch (PDOException $e) {
  if ($e->getCode() !== '42P01') {
    fwrite(STDERR, '[drupal] database error: ' . $e->getMessage() . "\n");
    exit(1);
  }
}

echo "[drupal] installing Drupal (this runs once)\n";

chdir('/opt/drupal/web');
$autoloader = require '/opt/drupal/vendor/autoload.php';
require_once 'core/includes/install.core.inc';

$password = env('DRUPAL_ADMIN_PASSWORD');
install_drupal($autoloader, [
  'interactive' => FALSE,
  'site_path' => 'sites/default',
  'parameters' => [
    'profile' => 'standard',
    'langcode' => 'en',
  ],
  'forms' => [
    'install_configure_form' => [
      'site_name' => env('DRUPAL_SITE_NAME'),
      'site_mail' => env('DRUPAL_ADMIN_EMAIL'),
      'account' => [
        'name' => env('DRUPAL_ADMIN_USER'),
        'mail' => env('DRUPAL_ADMIN_EMAIL'),
        'pass' => ['pass1' => $password, 'pass2' => $password],
      ],
      // NULL (not FALSE) unticks a checkbox in a programmatic form submission.
      'enable_update_status_module' => NULL,
      'enable_update_status_emails' => NULL,
    ],
  ],
]);

echo "[drupal] install finished\n";
