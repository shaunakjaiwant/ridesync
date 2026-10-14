<?php
/**
 * RideSync Non-Destructive Exhibition Demonstration Seeder
 * Usage: php tools/seed_demo_data.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db.php';

echo "=== RideSync Exhibition Demo Data Seeder ===" . PHP_EOL . PHP_EOL;

if (!$conn instanceof mysqli) {
    fwrite(STDERR, "[FAIL] Database connection unavailable." . PHP_EOL);
    exit(1);
}

$sqlPath = __DIR__ . '/../database/seed_demo_data.sql';
if (!is_file($sqlPath)) {
    fwrite(STDERR, "[FAIL] Seed file not found: {$sqlPath}" . PHP_EOL);
    exit(1);
}

$rawSql = (string) file_get_contents($sqlPath);

// Strip comments and split by semicolon
$statements = [];
$lines = explode("\n", $rawSql);
$current = '';

foreach ($lines as $line) {
    $trimmed = trim($line);
    if ($trimmed === '' || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '/*')) {
        continue;
    }
    // Skip 'USE ...' statement if database is already selected
    if (stripos($trimmed, 'USE ') === 0) {
        continue;
    }

    $current .= $line . "\n";
    if (str_ends_with($trimmed, ';')) {
        $statements[] = trim($current);
        $current = '';
    }
}

if ($current !== '') {
    $statements[] = trim($current);
}

$executed = 0;
$errors = [];

foreach ($statements as $stmt) {
    $stmt = rtrim($stmt, ';');
    if ($stmt === '') {
        continue;
    }

    if (@mysqli_query($conn, $stmt)) {
        $executed++;
    } else {
        $errors[] = mysqli_error($conn) . " in:\n" . substr($stmt, 0, 100) . "...";
    }
}

if (!empty($errors)) {
    echo "[WARNING] Completed with " . count($errors) . " warning(s):" . PHP_EOL;
    foreach ($errors as $err) {
        echo "  - " . $err . PHP_EOL;
    }
} else {
    echo "[OK] Successfully executed {$executed} seed statements." . PHP_EOL;
}

echo PHP_EOL;
echo "Exhibition Demo Accounts Ready:" . PHP_EOL;
echo "  [Rider]  demo.rider@ridesync.test     / password123 (SDMIT Campus)" . PHP_EOL;
echo "  [Driver] demo.driver@ridesync.test    / password123 (Active, Verified, Car)" . PHP_EOL;
echo "  [Admin]  ridesync@admin.com           / 12345678    (Super Admin)" . PHP_EOL;
echo "  [Ride]   SDMIT Campus, Ujire -> Mangaluru Central (Open, Active)" . PHP_EOL . PHP_EOL;

echo "RideSync demo seeding complete." . PHP_EOL;
exit(0);
