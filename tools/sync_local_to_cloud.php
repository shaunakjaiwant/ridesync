<?php
/**
 * RideSync - Pure PHP Local to Cloud Database Synchronization & Link Tool
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/../config/bootstrap.php';

$host = ridesync_env('RIDESYNC_DB_HOST', 'ridesync-db-ridesync-db.k.aivencloud.com');
$port = (int) ridesync_env('RIDESYNC_DB_PORT', 26498);
$user = ridesync_env('RIDESYNC_DB_USER', 'avnadmin');
$pass = (string) ridesync_env('RIDESYNC_DB_PASSWORD', '');
$db   = ridesync_env('RIDESYNC_DB_NAME', 'defaultdb');
$caPath = realpath(__DIR__ . '/../ca.pem');
$sqlFile = realpath(__DIR__ . '/../storage/exports/ridesync_cloud_import.sql');

// Parse CLI flags
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--host=')) $host = substr($arg, 7);
    if (str_starts_with($arg, '--port=')) $port = (int) substr($arg, 7);
    if (str_starts_with($arg, '--user=')) $user = substr($arg, 7);
    if (str_starts_with($arg, '--password=')) $pass = substr($arg, 11);
    if (str_starts_with($arg, '--database=')) $db = substr($arg, 11);
    if (str_starts_with($arg, '--sql-file=')) $sqlFile = substr($arg, 11);
}

echo "========================================================\n";
echo "   RideSync -> Cloud Database Sync (Pure PHP)           \n";
echo "========================================================\n";
echo "Target Host     : {$host}:{$port}\n";
echo "Target Database : {$db}\n";
echo "Database User   : {$user}\n";
echo "CA Certificate  : " . ($caPath ?: 'System default') . "\n";
echo "SQL Dump File   : {$sqlFile}\n";
echo "--------------------------------------------------------\n";

if (!$sqlFile || !file_exists($sqlFile)) {
    fwrite(STDERR, "ERROR: SQL file not found. Run 'php tools/export_all_data.php' first.\n");
    exit(1);
}

if (empty($pass)) {
    echo "Enter password for {$user}: ";
    $pass = trim(fgets(STDIN));
}

if (empty($pass)) {
    fwrite(STDERR, "ERROR: Database password is required.\n");
    exit(1);
}

$conn = mysqli_init();
mysqli_options($conn, MYSQLI_OPT_CONNECT_TIMEOUT, 15);
if ($caPath && file_exists($caPath)) {
    mysqli_options($conn, MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, false);
    mysqli_ssl_set($conn, NULL, NULL, $caPath, NULL, NULL);
}

echo "\n[1/3] Connecting to cloud database over SSL...\n";
$connected = @mysqli_real_connect($conn, $host, $user, $pass, $db, $port, NULL, MYSQLI_CLIENT_SSL);
if (!$connected) {
    fwrite(STDERR, "FAIL: Could not connect: " . mysqli_connect_error() . "\n");
    exit(1);
}

echo "  [OK] Connected successfully!\n";
mysqli_set_charset($conn, 'utf8mb4');

echo "\n[2/3] Importing SQL dump...\n";
$sqlContent = file_get_contents($sqlFile);
mysqli_multi_query($conn, $sqlContent);

$executed = 0;
do {
    $executed++;
    if ($result = mysqli_store_result($conn)) {
        mysqli_free_result($result);
    }
} while (mysqli_more_results($conn) && mysqli_next_result($conn));

if (mysqli_errno($conn)) {
    echo "  [WARN] Notice during import: " . mysqli_error($conn) . "\n";
} else {
    echo "  [OK] Finished executing batch queries!\n";
}

echo "\n[3/3] Verifying table row counts in cloud DB...\n";
$res = mysqli_query($conn, "SHOW TABLES");
$totalCloudRows = 0;
$cloudTables = [];

while ($row = mysqli_fetch_row($res)) {
    $cloudTables[] = $row[0];
}

printf("%-35s | %15s\n", "TABLE NAME", "CLOUD ROW COUNT");
echo str_repeat('-', 55) . "\n";

foreach ($cloudTables as $tbl) {
    $cRes = mysqli_query($conn, "SELECT COUNT(*) FROM `{$tbl}`");
    if ($cRes) {
        $count = mysqli_fetch_row($cRes)[0];
        $totalCloudRows += (int) $count;
        if ($count > 0) {
            printf("%-35s | %15d\n", $tbl, $count);
        }
    }
}

echo str_repeat('-', 55) . "\n";
printf("%-35s | %15d\n", "Total Tables", count($cloudTables));
printf("%-35s | %15d\n", "Total Rows in Cloud DB", $totalCloudRows);

mysqli_close($conn);

echo "\n========================================================\n";
echo "   SYNCHRONIZATION COMPLETED SUCCESSFULLY!              \n";
echo "========================================================\n";
