<?php

if (!defined('RIDESYNC_ROOT')) {
    define('RIDESYNC_ROOT', dirname(__DIR__));
}

if (!defined('RIDESYNC_BOOTSTRAPPED')) {
    define('RIDESYNC_BOOTSTRAPPED', true);

    function ridesync_env($key, $default = null) {
        $value = getenv($key);
        return ($value === false || $value === '') ? $default : $value;
    }

    function ridesync_env_bool($key, $default = false) {
        $value = ridesync_env($key, null);
        if ($value === null) {
            return (bool) $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $default;
    }

    function ridesync_env_int($key, $default) {
        $value = ridesync_env($key, null);
        if ($value === null || !is_numeric($value)) {
            return (int) $default;
        }

        return (int) $value;
    }

    function ridesync_secret_is_configured($value, int $minLength = 32): bool {
        $value = trim((string) $value);
        if ($value === '' || strlen($value) < $minLength) {
            return false;
        }

        return stripos($value, 'replace-with') !== 0;
    }

    function ridesync_env_secret_is_configured(string $key, int $minLength = 32): bool {
        return ridesync_secret_is_configured((string) ridesync_env($key, ''), $minLength);
    }

    function ridesync_base64_key_is_configured($value, int $minBytes = 32): bool {
        $value = trim((string) $value);
        if ($value === '' || stripos($value, 'replace-with') === 0) {
            return false;
        }

        $decoded = base64_decode($value, true);
        return $decoded !== false && strlen($decoded) >= $minBytes;
    }

    function ridesync_app_env() {
        return strtolower((string) ridesync_env('RIDESYNC_ENV', 'local'));
    }

    function ridesync_is_debug() {
        return ridesync_env_bool('RIDESYNC_DEBUG', ridesync_app_env() !== 'production');
    }

    function ridesync_storage_path($relative = '') {
        $base = ridesync_env('RIDESYNC_STORAGE_DIR', RIDESYNC_ROOT . DIRECTORY_SEPARATOR . 'storage');
        $base = rtrim((string) $base, DIRECTORY_SEPARATOR . '/');
        $relative = trim((string) $relative, DIRECTORY_SEPARATOR . '/');

        return $relative === '' ? $base : $base . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
    }

    function ridesync_ensure_directory($path) {
        return is_dir($path) || mkdir($path, 0755, true);
    }

    function ridesync_request_id() {
        static $requestId = null;

        if ($requestId !== null) {
            return $requestId;
        }

        $incoming = trim((string) ($_SERVER['HTTP_X_REQUEST_ID'] ?? ''));
        if ($incoming !== '' && preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $incoming)) {
            $requestId = $incoming;
        } else {
            $requestId = bin2hex(random_bytes(16));
        }

        return $requestId;
    }

    function ridesync_csp_nonce() {
        static $nonce = null;

        if ($nonce === null) {
            $nonce = base64_encode(random_bytes(16));
        }

        return $nonce;
    }

    function ridesync_client_ip() {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        if (ridesync_env_bool('RIDESYNC_TRUST_PROXY', false)) {
            $forwardedFor = trim((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
            if ($forwardedFor !== '') {
                foreach (array_map('trim', explode(',', $forwardedFor)) as $candidate) {
                    if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                        $ip = $candidate;
                        break;
                    }
                }
            }
        }

        return substr((string) $ip, 0, 64);
    }

    function ridesync_user_agent() {
        return substr(trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 255);
    }

    function ridesync_csp_extra_sources($envKey) {
        $sources = array_filter(array_map('trim', explode(',', (string) ridesync_env($envKey, ''))));
        return array_values(array_filter($sources, static function ($source) {
            return preg_match('/^(https?:|wss?:|data:|blob:|\'self\')/', $source) === 1;
        }));
    }

    function ridesync_configure_runtime() {
        date_default_timezone_set((string) ridesync_env('RIDESYNC_TIMEZONE', 'Asia/Kolkata'));

        ini_set('display_errors', ridesync_is_debug() ? '1' : '0');
        ini_set('log_errors', '1');
        error_reporting(E_ALL);
    }

    function ridesync_is_https_request() {
        if (PHP_SAPI === 'cli') {
            return false;
        }

        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
            || (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }

    function ridesync_send_security_headers() {
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }

        header_remove('X-Powered-By');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('X-Download-Options: noopen');
        header('X-Permitted-Cross-Domain-Policies: none');
        header('Origin-Agent-Cluster: ?1');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cross-Origin-Resource-Policy: same-site');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(self)');
        header('X-Request-Id: ' . ridesync_request_id());
        $isCacheableLocalPage = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
            && !ridesync_request_prefers_json()
            && ridesync_app_env() !== 'production';
        header($isCacheableLocalPage
            ? 'Cache-Control: private, max-age=15, stale-while-revalidate=30'
            : 'Cache-Control: no-store, private');

        if (ridesync_env_bool('RIDESYNC_ENABLE_CSP', true)) {
            $scriptSources = array_merge([
                "'self'",
                "'nonce-" . ridesync_csp_nonce() . "'",
                'https://unpkg.com',
            ], ridesync_csp_extra_sources('RIDESYNC_CSP_SCRIPT_SRC'));
            $styleSources = array_merge([
                "'self'",
                "'unsafe-inline'",
                'https://fonts.googleapis.com',
                'https://unpkg.com',
            ], ridesync_csp_extra_sources('RIDESYNC_CSP_STYLE_SRC'));
            $imgSources = array_merge([
                "'self'",
                'data:',
                'blob:',
                'https://unpkg.com',
                'https://*.tile.openstreetmap.org',
            ], ridesync_csp_extra_sources('RIDESYNC_CSP_IMG_SRC'));
            $connectSources = array_merge([
                "'self'",
                'https://nominatim.openstreetmap.org',
                'https://router.project-osrm.org',
            ], ridesync_csp_extra_sources('RIDESYNC_CSP_CONNECT_SRC'));

            header("Content-Security-Policy: default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none'; form-action 'self'; script-src " . implode(' ', array_unique($scriptSources)) . '; style-src ' . implode(' ', array_unique($styleSources)) . "; font-src 'self' https://fonts.gstatic.com data:; img-src " . implode(' ', array_unique($imgSources)) . '; connect-src ' . implode(' ', array_unique($connectSources)) . "; media-src 'self'; worker-src 'self' blob:");
        }

        if (ridesync_is_https_request()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    function ridesync_start_session() {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }

        ini_set('session.use_strict_mode', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Lax');
        session_cache_limiter('');

        $secureCookie = ridesync_env_bool('RIDESYNC_COOKIE_SECURE', ridesync_is_https_request());
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $secureCookie,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();
    }

    function ridesync_issue_csrf_token() {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        return $_SESSION['csrf_token'];
    }

    function ridesync_authenticated_role() {
        if (isset($_SESSION['admin_id'])) {
            return 'admin';
        }
        if (isset($_SESSION['driver_id'])) {
            return 'driver';
        }
        if (isset($_SESSION['user_id'])) {
            return 'rider';
        }

        return null;
    }

    function ridesync_forget_authenticated_session($reason = 'ended') {
        $role = ridesync_authenticated_role();
        unset(
            $_SESSION['user_id'],
            $_SESSION['user_name'],
            $_SESSION['driver_id'],
            $_SESSION['driver_name'],
            $_SESSION['admin_id'],
            $_SESSION['admin_name'],
            $_SESSION['admin_role'],
            $_SESSION['selected_role'],
            $_SESSION['_auth_role'],
            $_SESSION['_auth_started_at'],
            $_SESSION['_last_seen_at'],
            $_SESSION['_last_rotated_at']
        );

        $_SESSION['_session_notice'] = $reason;
        $_SESSION['_created_at'] = time();
        if ($reason === 'expired') {
            $message = 'Your session expired. Please login again.';
            if ($role === 'admin') {
                $_SESSION['admin_error'] = $message;
            } elseif ($role === 'driver') {
                $_SESSION['driver_auth_error'] = $message;
            } elseif ($role === 'rider') {
                $_SESSION['login_error'] = $message;
            }
        }

        if (!headers_sent()) {
            session_regenerate_id(true);
        }

        ridesync_issue_csrf_token();
    }

    function ridesync_mark_authenticated_session($role) {
        $now = time();
        $_SESSION['_auth_role'] = (string) $role;
        $_SESSION['_auth_started_at'] = $now;
        $_SESSION['_last_seen_at'] = $now;
        $_SESSION['_last_rotated_at'] = $now;
        $_SESSION['_created_at'] = $_SESSION['_created_at'] ?? $now;
        ridesync_issue_csrf_token();
    }

    function ridesync_refresh_anonymous_session() {
        $_SESSION['_created_at'] = $_SESSION['_created_at'] ?? time();
        unset($_SESSION['_auth_role'], $_SESSION['_auth_started_at'], $_SESSION['_last_seen_at'], $_SESSION['_last_rotated_at']);
    }

    function ridesync_enforce_session_limits() {
        $now = time();
        $_SESSION['_created_at'] = $_SESSION['_created_at'] ?? $now;

        $role = ridesync_authenticated_role();
        if ($role === null) {
            ridesync_refresh_anonymous_session();
            return;
        }

        $startedAt = (int) ($_SESSION['_auth_started_at'] ?? $_SESSION['_created_at'] ?? $now);
        $lastSeenAt = (int) ($_SESSION['_last_seen_at'] ?? $now);
        $lastRotatedAt = (int) ($_SESSION['_last_rotated_at'] ?? $startedAt);
        $idleSeconds = max(60, ridesync_env_int('RIDESYNC_SESSION_IDLE_SECONDS', 30 * 60));
        $absoluteSeconds = max($idleSeconds, ridesync_env_int('RIDESYNC_SESSION_ABSOLUTE_SECONDS', 8 * 60 * 60));
        $rotateSeconds = max(60, ridesync_env_int('RIDESYNC_SESSION_ROTATE_SECONDS', 15 * 60));

        if (($now - $lastSeenAt) > $idleSeconds || ($now - $startedAt) > $absoluteSeconds) {
            ridesync_forget_authenticated_session('expired');
            return;
        }

        if (($now - $lastRotatedAt) >= $rotateSeconds && !headers_sent()) {
            session_regenerate_id(true);
            $_SESSION['_last_rotated_at'] = $now;
        }

        $_SESSION['_auth_role'] = $role;
        $_SESSION['_auth_started_at'] = $startedAt;
        $_SESSION['_last_seen_at'] = $now;
        $_SESSION['_last_rotated_at'] = $_SESSION['_last_rotated_at'] ?? $now;
    }

    function ridesync_destroy_session() {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'] ?? '/',
                'domain' => $params['domain'] ?? '',
                'secure' => (bool) ($params['secure'] ?? false),
                'httponly' => (bool) ($params['httponly'] ?? true),
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }

        session_destroy();
    }

    function ridesync_request_prefers_json() {
        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        $script = strtolower((string) ($_SERVER['SCRIPT_NAME'] ?? ''));

        return str_contains($accept, 'application/json') || str_contains($script, '/api/');
    }

    function ridesync_register_error_handlers() {
        if (PHP_SAPI === 'cli') {
            return;
        }

        set_error_handler(static function ($severity, $message, $file, $line) {
            if (!(error_reporting() & $severity)) {
                return false;
            }

            if (function_exists('ridesync_log')) {
                ridesync_log('warning', 'PHP runtime warning', [
                    'severity' => $severity,
                    'message' => $message,
                    'file' => $file,
                    'line' => $line,
                ]);
            }

            return false;
        });

        set_exception_handler(static function (Throwable $exception) {
            if (function_exists('ridesync_log_exception')) {
                ridesync_log_exception($exception);
            } else {
                error_log((string) $exception);
            }

            if (!headers_sent()) {
                http_response_code(500);
            }

            if (ridesync_request_prefers_json()) {
                if (!headers_sent()) {
                    header('Content-Type: application/json; charset=utf-8');
                }
                echo json_encode([
                    'ok' => false,
                    'error' => ridesync_is_debug() ? $exception->getMessage() : 'Internal server error',
                    'request_id' => ridesync_request_id(),
                ]);
                exit;
            }

            echo ridesync_is_debug()
                ? 'Internal server error: ' . htmlspecialchars($exception->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                : 'Something went wrong. Please try again later.';
            exit;
        });
    }

    ridesync_configure_runtime();
    require_once RIDESYNC_ROOT . '/includes/logger.php';
    ridesync_register_error_handlers();
    ridesync_send_security_headers();
    ridesync_start_session();
    ridesync_enforce_session_limits();

    if (empty($_SESSION['csrf_token'])) {
        ridesync_issue_csrf_token();
    }
}
