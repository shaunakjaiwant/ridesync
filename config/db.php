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

$useSsl = ridesync_env('RIDESYNC_DB_SSL', true);
$isLocalhost = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
$flags = ($useSsl && !$isLocalhost) ? MYSQLI_CLIENT_SSL : 0;

if ($flags & MYSQLI_CLIENT_SSL) {
    mysqli_options($conn, MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, false);
    mysqli_ssl_set($conn, NULL, NULL, NULL, NULL, NULL);
}

$connectExceptionMessage = null;
try {
    $connected = @mysqli_real_connect($conn, $host, $username, $password, $database, $port, NULL, $flags);
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
        header('Content-Type: text/html; charset=utf-8');
    }

    if (PHP_SAPI === 'cli') {
        exit("Database connection failed: {$error}\n");
    }

    $styleVersion = function_exists('ridesync_stylesheet_version') ? ridesync_stylesheet_version() : '1.0';
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>System Maintenance - RideSync</title>
        <link rel="icon" type="image/png" href="/ridesync/logo-mark.png">
        <link rel="stylesheet" href="/ridesync/css/theme.css?v=<?php echo $styleVersion; ?>">
        <link rel="stylesheet" href="/ridesync/css/style.css?v=<?php echo $styleVersion; ?>">
    </head>
    <body class="public-app app-shell" style="min-height: 100vh; display: flex; align-items: center; justify-content: center; background: #0b0f17; color: #f8fafc; padding: 20px;">
        <main style="max-width: 520px; width: 100%; background: #161b26; border: 1px solid rgba(255,255,255,0.1); border-radius: 16px; padding: 32px; box-shadow: 0 20px 50px rgba(0,0,0,0.5); text-align: center;">
            <img src="/ridesync/logo-mark.png" alt="RideSync Logo" style="width: 56px; height: 56px; margin-bottom: 16px;">
            <h1 style="font-size: 1.5rem; font-weight: 800; margin-bottom: 8px; color: #ffffff;">Database Service Unavailable</h1>
            <p style="color: #94a3b8; font-size: 0.95rem; line-height: 1.5; margin-bottom: 24px;">
                RideSync is currently unable to connect to the database service. Please check your cloud database host and credentials in your deployment configuration.
            </p>
            <div style="background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.2); border-radius: 8px; padding: 12px 16px; text-align: left; font-family: monospace; font-size: 0.82rem; color: #fca5a5; overflow-x: auto; margin-bottom: 24px;">
                <strong>Host:</strong> <?php echo htmlspecialchars($host); ?><br>
                <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
            </div>
            <a href="javascript:location.reload()" class="btn btn-primary" style="display: inline-block; padding: 10px 24px; border-radius: 8px; background: #2563eb; color: #ffffff; text-decoration: none; font-weight: 600;">Retry Connection</a>
        </main>
    </body>
    </html>
    <?php
    exit();
}

mysqli_set_charset($conn, "utf8mb4");

function ridesync_validate_session_principal($conn) {
    if (!$conn instanceof mysqli || session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    $now = time();
    if (isset($_SESSION['_principal_valid_until']) && (int) $_SESSION['_principal_valid_until'] > $now) {
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

    $_SESSION['_principal_valid_until'] = $now + 60;
}

ridesync_validate_session_principal($conn);
?>
