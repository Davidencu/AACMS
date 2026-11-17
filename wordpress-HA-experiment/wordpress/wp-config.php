<?php
/**
 * Shared wp-config.php. Every node gets exactly the same file; all settings
 * come from environment variables so the nodes stay interchangeable.
 */

function ha_env(string $name, ?string $default = null): ?string
{
    $v = getenv($name);
    return ($v === false || $v === '') ? $default : $v;
}

// --- Database (through ProxySQL) -------------------------------------------
define('DB_NAME',     ha_env('WORDPRESS_DB_NAME', 'wordpress'));
define('DB_USER',     ha_env('WORDPRESS_DB_USER', 'wordpress'));
define('DB_PASSWORD', ha_env('WORDPRESS_DB_PASSWORD', ''));
define('DB_HOST',     ha_env('WORDPRESS_DB_HOST', 'proxysql:6033'));
define('DB_CHARSET',  'utf8mb4');
define('DB_COLLATE',  '');
$table_prefix = ha_env('WORDPRESS_TABLE_PREFIX', 'wp_');

// --- Keys and salts: must be identical on all nodes ------------------------
foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY',
          'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'] as $k) {
    $val = ha_env('WORDPRESS_' . $k);
    if ($val === null) {
        http_response_code(500);
        die("Missing WORDPRESS_$k");
    }
    define($k, $val);
}

// --- Site URL --------------------------------------------------------------
$url = rtrim(ha_env('WORDPRESS_URL', 'https://localhost:8443'), '/');
define('WP_HOME', $url);
define('WP_SITEURL', $url);

// TLS is terminated by HAProxy; trust its header (Apache is only reachable from it).
if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
    $_SERVER['HTTPS'] = 'on';
}

// --- Immutable, stateless nodes --------------------------------------------
define('DISABLE_WP_CRON', true);          // the wp-cron service runs scheduled events once
define('DISALLOW_FILE_MODS', true);       // no plugin/theme/core changes from the admin UI
define('AUTOMATIC_UPDATER_DISABLED', true);
define('WP_AUTO_UPDATE_CORE', false);
define('FORCE_SSL_ADMIN', true);

// --- Redis object cache ----------------------------------------------------
define('WP_REDIS_CLIENT', 'phpredis');
define('WP_REDIS_HOST', ha_env('WORDPRESS_REDIS_HOST', 'redis'));
define('WP_REDIS_PORT', (int) ha_env('WORDPRESS_REDIS_PORT', '6379'));
define('WP_REDIS_DATABASE', 0);
define('WP_REDIS_TIMEOUT', 1);
define('WP_REDIS_READ_TIMEOUT', 1);
define('WP_REDIS_PREFIX', 'wp:');
if (ha_env('WORDPRESS_REDIS_PASSWORD') !== null) {
    define('WP_REDIS_PASSWORD', ha_env('WORDPRESS_REDIS_PASSWORD'));
}

// --- PHP sessions in Redis (shared across nodes) ---------------------------
if (PHP_SAPI !== 'cli' && extension_loaded('redis')) {
    $auth = ha_env('WORDPRESS_REDIS_PASSWORD');
    ini_set('session.save_handler', 'redis');
    ini_set('session.save_path', sprintf(
        'tcp://%s:%d?database=1&prefix=sess:%s',
        WP_REDIS_HOST,
        WP_REDIS_PORT,
        $auth !== null ? '&auth=' . rawurlencode($auth) : ''
    ));
}

define('WP_DEBUG', filter_var(ha_env('WORDPRESS_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN));

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
require_once ABSPATH . 'wp-settings.php';
