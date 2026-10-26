<?php
/**
 * RideSync - Database Target Switcher
 * 
 * Allows switching local RideSync between:
 * 1. 'local'  -> Local XAMPP MySQL (localhost:3306 / ridesync_db)
 * 2. 'cloud'  -> Deployed Aiven MySQL (ridesync-db-ridesync-db.k.aivencloud.com:26498 / defaultdb)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$envFile = realpath(__DIR__ . '/../.env');
if (!$envFile || !file_exists($envFile)) {
    $envFile = __DIR__ . '/../.env';
}

$mode = strtolower($argv[1] ?? '');

if (!in_array($mode, ['local', 'cloud', 'status'], true)) {
    echo "Usage:\n";
    echo "  php tools/switch_db_target.php local                  # Connect to local XAMPP MySQL\n";
    echo "  php tools/switch_db_target.php cloud [password]       # Connect to deployed Aiven MySQL\n";
    echo "  php tools/switch_db_target.php status                 # Display current target\n";
    exit(1);
}

$content = file_exists($envFile) ? file_get_contents($envFile) : '';

function get_env_val(string $content, string $key, string $default = ''): string {
    if (preg_match('/^' . preg_quote($key, '/') . '=(.*)$/m', $content, $m)) {
        return trim($m[1], " \t\n\r\0\x0B\"'");
    }
    return $default;
}

function set_env_val(string &$content, string $key, string $val): void {
    if (preg_match('/^' . preg_quote($key, '/') . '=.*$/m', $content)) {
        $content = preg_replace('/^' . preg_quote($key, '/') . '=.*$/m', "{$key}={$val}", $content);
    } else {
        $content .= "\n{$key}={$val}";
    }
}

if ($mode === 'status') {
    $host = get_env_val($content, 'RIDESYNC_DB_HOST', 'localhost');
    $user = get_env_val($content, 'RIDESYNC_DB_USER', 'root');
    $db   = get_env_val($content, 'RIDESYNC_DB_NAME', 'ridesync_db');
    $port = get_env_val($content, 'RIDESYNC_DB_PORT', '3306');

    echo "========================================================\n";
    echo "   RideSync Database Target Status                      \n";
    echo "========================================================\n";
    echo "Target Mode : " . (str_contains($host, 'aivencloud') ? "CLOUD (Live Deployed DB)" : "LOCAL (XAMPP MySQL)") . "\n";
    echo "Host        : {$host}:{$port}\n";
    echo "Database    : {$db}\n";
    echo "Username    : {$user}\n";
    echo "========================================================\n";
    exit(0);
}

if ($mode === 'local') {
    set_env_val($content, 'RIDESYNC_DB_HOST', 'localhost');
    set_env_val($content, 'RIDESYNC_DB_PORT', '3306');
    set_env_val($content, 'RIDESYNC_DB_NAME', 'ridesync_db');
    set_env_val($content, 'RIDESYNC_DB_USER', 'root');
    set_env_val($content, 'RIDESYNC_DB_PASSWORD', '');
    file_put_contents($envFile, $content);

    echo "[OK] Switched RideSync to LOCAL database (localhost:3306 / ridesync_db).\n";
    exit(0);
}

if ($mode === 'cloud') {
    $pass = $argv[2] ?? '';
    if (empty($pass)) {
        echo "Enter Aiven Cloud MySQL password for 'avnadmin': ";
        $pass = trim(fgets(STDIN));
    }

    if (empty($pass)) {
        fwrite(STDERR, "[ERROR] Password is required to switch to cloud database.\n");
        exit(1);
    }

    set_env_val($content, 'RIDESYNC_DB_HOST', 'ridesync-db-ridesync-db.k.aivencloud.com');
    set_env_val($content, 'RIDESYNC_DB_PORT', '26498');
    set_env_val($content, 'RIDESYNC_DB_NAME', 'defaultdb');
    set_env_val($content, 'RIDESYNC_DB_USER', 'avnadmin');
    set_env_val($content, 'RIDESYNC_DB_PASSWORD', $pass);
    file_put_contents($envFile, $content);

    echo "[OK] Switched RideSync to CLOUD database (ridesync-db-ridesync-db.k.aivencloud.com:26498 / defaultdb).\n";
    echo "Local RideSync will now read and write directly to the live deployed database!\n";
    exit(0);
}
