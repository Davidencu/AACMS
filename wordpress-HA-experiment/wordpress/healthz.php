<?php
// Load balancer health check: 200 only if this node can reach the database
// through ProxySQL. A node that has lost its DB path is taken out of rotation.
header('Content-Type: text/plain');
header('Cache-Control: no-store');
mysqli_report(MYSQLI_REPORT_OFF);

$hostport = explode(':', getenv('WORDPRESS_DB_HOST') ?: 'proxysql:6033');
$db = mysqli_init();
$db->options(MYSQLI_OPT_CONNECT_TIMEOUT, 2);

$ok = @$db->real_connect(
    $hostport[0],
    getenv('WORDPRESS_DB_USER') ?: 'wordpress',
    getenv('WORDPRESS_DB_PASSWORD') ?: '',
    getenv('WORDPRESS_DB_NAME') ?: 'wordpress',
    (int) ($hostport[1] ?? 3306)
) && $db->query('SELECT 1') !== false;

if ($ok) {
    echo "ok\n";
} else {
    http_response_code(503);
    echo "database unreachable\n";
}
