<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/view_helper.php';

$host     = ridesync_env('RIDESYNC_DB_HOST', 'localhost');
$username = ridesync_env('RIDESYNC_DB_USER', 'root');
$password = ridesync_env('RIDESYNC_DB_PASSWORD', '');
$database = ridesync_env('RIDESYNC_DB_NAME', 'ridesync_db');
$port     = (int) ridesync_env('RIDESYNC_DB_PORT', 3306);

$conn = mysqli_init();
mysqli_options($conn, MYSQLI_OPT_CONNECT_TIMEOUT, (int) ridesync_env('RIDESYNC_DB_CONNECT_TIMEOUT', 5));

$connected = @mysqli_real_connect($conn, $host, $username, $password, $database, $port);

if (!$connected) {
    $error = mysqli_connect_error() ?: mysqli_error($conn);
    ridesync_log('critical', 'Database connection failed', [
        'host' => $host,
        'database' => $database,
        'error' => $error,
    ]);

    if (defined('RIDESYNC_ALLOW_DB_FAILURE') && RIDESYNC_ALLOW_DB_FAILURE) {
        $conn = null;
        return;
    }

    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        http_response_code(500);
    }

    exit('Database connection failed. Please try again later.');
}

mysqli_set_charset($conn, "utf8mb4");
?>
