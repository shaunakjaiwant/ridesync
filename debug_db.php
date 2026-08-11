<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

header('Content-Type: text/plain');

echo "Testing Storage directory permissions on Render...\n";

require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/includes/rate_limit_helper.php';

$storageDir = ridesync_storage_path();
echo "Storage Root: " . $storageDir . "\n";
echo "Storage Exists: " . (is_dir($storageDir) ? "YES" : "NO") . "\n";
echo "Storage Writable: " . (is_writable($storageDir) ? "YES" : "NO") . "\n";

try {
    $rateDir = ridesync_rate_limit_dir();
    echo "Rate Limit Dir: " . $rateDir . "\n";
    echo "Rate Limit Writable: " . (is_writable($rateDir) ? "YES" : "NO") . "\n";
    
    $testFile = $rateDir . '/test_write.json';
    $res = @file_put_contents($testFile, 'test');
    if ($res !== false) {
        echo "SUCCESS: Wrote test file to rate limit dir!\n";
        @unlink($testFile);
    } else {
        echo "FAIL: Could not write to rate limit dir!\n";
    }
} catch (Throwable $t) {
    echo "EXCEPTION: " . $t->getMessage() . "\nTrace:\n" . $t->getTraceAsString() . "\n";
}
