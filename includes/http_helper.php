<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/redirect_helper.php';

function ridesync_json_response(array $payload, $statusCode = 200, array $headers = []) {
    if (!headers_sent()) {
        http_response_code((int) $statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');

        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }
    }

    if (!array_key_exists('request_id', $payload)) {
        $payload['request_id'] = ridesync_request_id();
    }

    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function ridesync_error_response($message, $statusCode = 400, array $extra = []) {
    ridesync_json_response(array_merge([
        'ok' => false,
        'error' => (string) $message,
    ], $extra), $statusCode);
}

function ridesync_require_method($method, $redirect = null) {
    $method = strtoupper((string) $method);
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === $method) {
        return;
    }

    if ($redirect !== null) {
        header('Location: ' . ridesync_safe_redirect_target($redirect, '/ridesync/index.php'));
        exit;
    }

    ridesync_error_response('Method not allowed', 405, ['allowed_method' => $method]);
}

function ridesync_current_origin() {
    $scheme = ridesync_is_https_request() ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? '';

    return $host === '' ? null : $scheme . '://' . strtolower($host);
}

function ridesync_same_origin_request() {
    if (PHP_SAPI === 'cli') {
        return true;
    }

    $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($origin === '') {
        return true;
    }

    $current = ridesync_current_origin();
    if ($current !== null && strtolower($origin) === strtolower($current)) {
        return true;
    }

    $allowed = array_filter(array_map('trim', explode(',', (string) ridesync_env('RIDESYNC_ALLOWED_ORIGINS', ''))));
    foreach ($allowed as $allowedOrigin) {
        if (strtolower($origin) === strtolower($allowedOrigin)) {
            return true;
        }
    }

    return false;
}

function ridesync_csrf_is_valid($token = null) {
    $token = $token ?? ($_POST['csrf_token'] ?? '');
    return is_string($token)
        && $token !== ''
        && hash_equals($_SESSION['csrf_token'] ?? '', $token)
        && ridesync_same_origin_request();
}

function ridesync_require_csrf($redirect, $flashKey, $message = 'Invalid request. Please try again.') {
    if (ridesync_csrf_is_valid()) {
        return;
    }

    if ($flashKey !== '') {
        $_SESSION[$flashKey] = $message;
    }

    header('Location: ' . ridesync_safe_redirect_target($redirect, '/ridesync/index.php'));
    exit;
}

function ridesync_sse_headers() {
    if (headers_sent()) {
        return;
    }

    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
}

function ridesync_sse_event($event, array $payload) {
    $payload['request_id'] = $payload['request_id'] ?? ridesync_request_id();
    echo 'event: ' . preg_replace('/[^A-Za-z0-9_.-]/', '', (string) $event) . "\n";
    echo 'data: ' . json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
    @ob_flush();
    flush();
}

?>
