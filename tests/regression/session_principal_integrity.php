<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../../config/bootstrap.php';

$root = realpath(__DIR__ . '/../..');
$failures = [];

function sp_note($status, $message) {
    echo '[' . $status . '] ' . $message . PHP_EOL;
}

function sp_expect($condition, $message, array &$failures) {
    if (!$condition) {
        $failures[] = $message;
    }
}

function sp_run_session_case($root, $sessionKey, $flashKey) {
    $dbFile = $root . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'db.php';
    $sessionId = 'sp' . preg_replace('/[^a-z0-9]/i', '', $sessionKey) . bin2hex(random_bytes(4));
    $missingId = 2147483000;
    $role = $sessionKey === 'admin_id' ? 'admin' : ($sessionKey === 'driver_id' ? 'driver' : 'rider');
    $nameKey = $sessionKey === 'admin_id' ? 'admin_name' : ($sessionKey === 'driver_id' ? 'driver_name' : 'user_name');

    $lines = [
        'session_id(' . var_export($sessionId, true) . ');',
        'session_start();',
        '$_SESSION[' . var_export($sessionKey, true) . '] = ' . $missingId . ';',
        '$_SESSION[' . var_export($nameKey, true) . '] = "Missing Principal";',
        '$_SESSION["_auth_role"] = ' . var_export($role, true) . ';',
        '$_SESSION["_auth_started_at"] = time();',
        '$_SESSION["_last_seen_at"] = time();',
        '$_SESSION["_last_rotated_at"] = time();',
        '$_SESSION["_created_at"] = time();',
        '$_SESSION["csrf_token"] = bin2hex(random_bytes(32));',
        'require ' . var_export($dbFile, true) . ';',
        'echo json_encode([',
        '    "has_principal" => isset($_SESSION[' . var_export($sessionKey, true) . ']),',
        '    "notice" => $_SESSION["_session_notice"] ?? null,',
        '    "flash" => $_SESSION[' . var_export($flashKey, true) . '] ?? null,',
        '], JSON_UNESCAPED_SLASHES);',
    ];

    $tempDir = ridesync_storage_path('cache' . DIRECTORY_SEPARATOR . 'regression');
    ridesync_ensure_directory($tempDir);
    $file = tempnam($tempDir, 'sp_');
    file_put_contents($file, "<?php\n" . implode("\n", $lines) . "\n");

    $output = [];
    $exitCode = 1;
    exec('php ' . escapeshellarg($file) . ' 2>&1', $output, $exitCode);
    unlink($file);

    return [
        'exit_code' => $exitCode,
        'decoded' => json_decode(implode("\n", $output), true),
        'output' => implode("\n", $output),
    ];
}

foreach ([
    'user_id' => 'login_error',
    'driver_id' => 'driver_auth_error',
    'admin_id' => 'admin_error',
] as $sessionKey => $flashKey) {
    $result = sp_run_session_case($root, $sessionKey, $flashKey);
    sp_expect($result['exit_code'] === 0, "{$sessionKey} stale session subprocess failed: " . $result['output'], $failures);
    sp_expect(is_array($result['decoded']), "{$sessionKey} stale session did not return JSON: " . $result['output'], $failures);
    sp_expect(($result['decoded']['has_principal'] ?? true) === false, "{$sessionKey} stale principal was not removed", $failures);
    sp_expect(($result['decoded']['notice'] ?? null) !== null, "{$sessionKey} stale session did not record an invalid-session notice", $failures);
    sp_expect(str_contains((string) ($result['decoded']['flash'] ?? ''), 'no longer valid'), "{$sessionKey} stale session did not set the expected flash message", $failures);
}

if (!empty($failures)) {
    echo PHP_EOL . 'Session principal integrity regression failures:' . PHP_EOL;
    foreach ($failures as $failure) {
        echo '- ' . $failure . PHP_EOL;
    }
    exit(1);
}

sp_note('OK', 'stale rider sessions are evicted before DB-dependent page work');
sp_note('OK', 'stale driver sessions are evicted before DB-dependent page work');
sp_note('OK', 'stale admin sessions are evicted before DB-dependent page work');
echo PHP_EOL . 'RideSync session principal integrity regressions passed.' . PHP_EOL;
?>
