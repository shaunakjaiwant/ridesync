<?php
/**
 * Ensures the Python FastAPI AI Verification service is running on port 8011.
 */
function ridesync_ensure_ai_verification_service(): bool {
    $serviceUrl = trim((string) ridesync_env('RIDESYNC_VERIFICATION_SERVICE_URL', 'http://127.0.0.1:8011'));
    $parts = parse_url($serviceUrl);
    $port = isset($parts['port']) ? (int) $parts['port'] : 8011;
    $host = !empty($parts['host']) ? $parts['host'] : '127.0.0.1';

    // Test socket connection
    $fp = @fsockopen($host, $port, $errno, $errstr, 0.5);
    if ($fp) {
        fclose($fp);
        return true;
    }

    // Attempt to start Python uvicorn microservice process in background
    $appDir = realpath(__DIR__ . '/../apps/ai-verification');
    if (!$appDir || !is_dir($appDir)) {
        return false;
    }

    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        pclose(popen("cd /d " . escapeshellarg($appDir) . " && start /B python -m uvicorn app.main:app --host " . escapeshellarg($host) . " --port {$port} > NUL 2>&1", "r"));
    } else {
        exec("cd " . escapeshellarg($appDir) . " && nohup python3 -m uvicorn app.main:app --host " . escapeshellarg($host) . " --port {$port} > /dev/null 2>&1 &");
    }

    // Give it 500ms and re-check
    usleep(500000);
    $fp = @fsockopen($host, $port, $errno, $errstr, 0.5);
    if ($fp) {
        fclose($fp);
        return true;
    }

    return false;
}
