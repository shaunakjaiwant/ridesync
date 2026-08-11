<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

header('Content-Type: text/plain');

echo "Testing config/db.php directly...\n";

try {
    require_once __DIR__ . '/config/db.php';
    global $conn;
    if ($conn instanceof mysqli && @mysqli_ping($conn)) {
        echo "SUCCESS: config/db.php connected to MySQL successfully!\n";
    } else {
        echo "FAIL: \$conn is not connected\n";
    }
} catch (Throwable $t) {
    echo "EXCEPTION: " . $t->getMessage() . "\nTrace:\n" . $t->getTraceAsString() . "\n";
}
