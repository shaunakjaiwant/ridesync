<?php
/**
 * Ensures the Node.js WebSocket Gateway server is running.
 */
function ridesync_ensure_websocket_gateway(): bool {
    $wsUrl = trim((string) ridesync_env('RIDESYNC_WEBSOCKET_URL', 'ws://127.0.0.1:8081/ridesync/ws'));
    $parts = parse_url($wsUrl);
    $port = isset($parts['port']) ? (int) $parts['port'] : 8081;
    $host = !empty($parts['host']) ? $parts['host'] : '127.0.0.1';

    // Test socket connection
    $fp = @fsockopen($host, $port, $errno, $errstr, 0.5);
    if ($fp) {
        fclose($fp);
        return true;
    }

    // Attempt to start Node.js server process in background
    $serverPath = realpath(__DIR__ . '/../realtime/websocket-gateway/server.js');
    if (!$serverPath || !is_file($serverPath)) {
        return false;
    }

    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        pclose(popen("start /B node " . escapeshellarg($serverPath) . " > NUL 2>&1", "r"));
    } else {
        exec("nohup node " . escapeshellarg($serverPath) . " > /dev/null 2>&1 &");
    }

    // Give it 300ms and re-check
    usleep(300000);
    $fp = @fsockopen($host, $port, $errno, $errstr, 0.5);
    if ($fp) {
        fclose($fp);
        return true;
    }

    return false;
}
