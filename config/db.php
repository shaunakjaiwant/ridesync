<?php
require_once __DIR__ . '/bootstrap.php';

$mysqlConfig = ridesync_config('database.connections.mysql', []);
$host     = $mysqlConfig['host'] ?? 'localhost';
$username = $mysqlConfig['username'] ?? 'root';
$password = $mysqlConfig['password'] ?? '';
$database = $mysqlConfig['database'] ?? 'ridesync_db';
$port     = (int) ($mysqlConfig['port'] ?? 3306);

$conn = mysqli_init();
mysqli_options($conn, MYSQLI_OPT_CONNECT_TIMEOUT, (int) ($mysqlConfig['connect_timeout'] ?? 5));

$connectExceptionMessage = null;
try {
    $connected = @mysqli_real_connect($conn, $host, $username, $password, $database, $port);
} catch (mysqli_sql_exception $exception) {
    $connected = false;
    $connectExceptionMessage = $exception->getMessage();
}

if (!$connected) {
    $error = $connectExceptionMessage ?: (mysqli_connect_error() ?: mysqli_error($conn));
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

function ridesync_validate_session_principal($conn) {
    if (!$conn instanceof mysqli || session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    $checks = [
        'user_id' => [
            'table' => 'users',
            'status_column' => null,
            'allowed_statuses' => null,
            'reason' => 'invalid',
        ],
        'driver_id' => [
            'table' => 'driver_accounts',
            'status_column' => 'status',
            'allowed_statuses' => ['active'],
            'reason' => 'revoked',
        ],
        'admin_id' => [
            'table' => 'admin_users',
            'status_column' => 'status',
            'allowed_statuses' => ['active'],
            'reason' => 'revoked',
        ],
    ];

    foreach ($checks as $sessionKey => $check) {
        if (!isset($_SESSION[$sessionKey])) {
            continue;
        }

        $principalId = (int) $_SESSION[$sessionKey];
        if ($principalId <= 0) {
            ridesync_forget_authenticated_session('invalid');
            return;
        }

        $table = $check['table'];
        $statusColumn = $check['status_column'];
        $columns = $statusColumn ? "id, {$statusColumn}" : 'id';

        try {
            $stmt = mysqli_prepare($conn, "SELECT {$columns} FROM {$table} WHERE id = ? LIMIT 1");
            if (!$stmt) {
                return;
            }
            mysqli_stmt_bind_param($stmt, "i", $principalId);
            mysqli_stmt_execute($stmt);
            $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        } catch (Throwable $exception) {
            ridesync_log_exception($exception);
            return;
        }

        if (!$row) {
            ridesync_forget_authenticated_session($check['reason']);
            return;
        }

        if ($statusColumn && is_array($check['allowed_statuses']) && !in_array((string) $row[$statusColumn], $check['allowed_statuses'], true)) {
            ridesync_forget_authenticated_session($check['reason']);
            return;
        }
    }
}

ridesync_validate_session_principal($conn);
?>
