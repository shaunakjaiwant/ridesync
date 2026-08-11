<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

header('Content-Type: text/plain');

echo "Debugging DB connection on Render...\n";

require_once __DIR__ . '/config/bootstrap.php';

$mysqlConfig = ridesync_config('database.connections.mysql', []);
echo "Host: " . ($mysqlConfig['host'] ?? 'NONE') . "\n";
echo "Port: " . ($mysqlConfig['port'] ?? 'NONE') . "\n";
echo "User: " . ($mysqlConfig['username'] ?? 'NONE') . "\n";
echo "DB:   " . ($mysqlConfig['database'] ?? 'NONE') . "\n";

$conn = mysqli_init();
mysqli_options($conn, MYSQLI_OPT_CONNECT_TIMEOUT, 5);
mysqli_options($conn, MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, false);
mysqli_ssl_set($conn, NULL, NULL, NULL, NULL, NULL);

try {
    $ok = mysqli_real_connect(
        $conn,
        $mysqlConfig['host'],
        $mysqlConfig['username'],
        $mysqlConfig['password'],
        $mysqlConfig['database'],
        (int)$mysqlConfig['port'],
        NULL,
        MYSQLI_CLIENT_SSL
    );
    if ($ok) {
        echo "SUCCESS: Connected to MySQL via SSL!\n";
    } else {
        echo "FAIL: " . mysqli_connect_error() . "\n";
    }
} catch (Throwable $t) {
    echo "EXCEPTION: " . $t->getMessage() . "\n" . $t->getTraceAsString() . "\n";
}
