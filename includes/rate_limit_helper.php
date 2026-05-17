<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/redirect_helper.php';

if (!function_exists('ridesync_client_ip')) {
    function ridesync_client_ip() {
        return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'), 0, 64);
    }
}

function ridesync_rate_limit_dir() {
    $dir = ridesync_storage_path('rate_limits');
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    return $dir;
}

function ridesync_rate_limit_file($scope, $identity) {
    $hash = hash('sha256', (string) $scope . '|' . (string) $identity);
    return ridesync_rate_limit_dir() . DIRECTORY_SEPARATOR . $hash . '.json';
}

function ridesync_rate_limit_check($scope, $limit, $windowSeconds, $identity = null) {
    $limit = max(1, (int) $limit);
    $windowSeconds = max(1, (int) $windowSeconds);
    $identity = $identity ?? ridesync_client_ip();
    $now = time();
    $file = ridesync_rate_limit_file($scope, $identity);
    $state = ['count' => 0, 'reset_at' => $now + $windowSeconds];

    $handle = fopen($file, 'c+');
    if (!$handle) {
        ridesync_log('warning', 'Rate limit storage unavailable', ['scope' => $scope]);
        return [
            'allowed' => true,
            'limit' => $limit,
            'remaining' => $limit - 1,
            'retry_after' => 0,
            'reset_at' => $now + $windowSeconds,
        ];
    }

    flock($handle, LOCK_EX);
    $contents = stream_get_contents($handle);
    $decoded = $contents !== '' ? json_decode($contents, true) : null;
    if (is_array($decoded) && isset($decoded['count'], $decoded['reset_at'])) {
        $state = $decoded;
    }

    if ((int) $state['reset_at'] <= $now) {
        $state = ['count' => 0, 'reset_at' => $now + $windowSeconds];
    }

    $state['count'] = (int) $state['count'] + 1;
    $allowed = $state['count'] <= $limit;
    $remaining = max(0, $limit - $state['count']);
    $retryAfter = $allowed ? 0 : max(1, (int) $state['reset_at'] - $now);

    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode($state));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);

    return [
        'allowed' => $allowed,
        'limit' => $limit,
        'remaining' => $remaining,
        'retry_after' => $retryAfter,
        'reset_at' => (int) $state['reset_at'],
    ];
}

function ridesync_rate_limit_clear($scope, $identity = null) {
    $identity = $identity ?? ridesync_client_ip();
    $file = ridesync_rate_limit_file($scope, $identity);

    if (is_file($file)) {
        unlink($file);
    }
}

function ridesync_rate_limit_headers(array $result) {
    if (headers_sent()) {
        return;
    }

    header('X-RateLimit-Limit: ' . (int) ($result['limit'] ?? 0));
    header('X-RateLimit-Remaining: ' . (int) ($result['remaining'] ?? 0));
    header('X-RateLimit-Reset: ' . (int) ($result['reset_at'] ?? time()));

    if (!empty($result['retry_after'])) {
        header('Retry-After: ' . (int) $result['retry_after']);
    }
}

function ridesync_enforce_rate_limit($scope, $limit, $windowSeconds, $identity = null, array $options = []) {
    $result = ridesync_rate_limit_check($scope, $limit, $windowSeconds, $identity);
    ridesync_rate_limit_headers($result);

    if ($result['allowed']) {
        return $result;
    }

    ridesync_log('warning', 'Rate limit exceeded', [
        'scope' => $scope,
        'identity' => hash('sha256', (string) ($identity ?? ridesync_client_ip())),
        'retry_after' => $result['retry_after'],
    ]);

    $message = $options['message'] ?? 'Too many requests. Please try again shortly.';

    if (!empty($options['json'])) {
        if (function_exists('ridesync_json_response')) {
            ridesync_json_response([
                'ok' => false,
                'error' => $message,
                'retry_after' => $result['retry_after'],
            ], 429);
        }

        if (!headers_sent()) {
            http_response_code(429);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['ok' => false, 'error' => $message, 'retry_after' => $result['retry_after']]);
        exit;
    }

    if (!empty($options['redirect'])) {
        $flashKey = $options['flash_key'] ?? '';
        if ($flashKey !== '') {
            $_SESSION[$flashKey] = $message;
        }

        header('Location: ' . ridesync_safe_redirect_target($options['redirect'], '/ridesync/index.php'));
        exit;
    }

    if (!headers_sent()) {
        http_response_code(429);
    }
    exit($message);
}

?>
