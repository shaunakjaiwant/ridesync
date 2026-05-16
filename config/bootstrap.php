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
        header('X-Permitted-Cross-Domain-Policies: none');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(self)');
        header('X-Request-Id: ' . ridesync_request_id());
        header('Cache-Control: no-store, private');
        header("Content-Security-Policy: base-uri 'self'; object-src 'none'; frame-ancestors 'none'; form-action 'self'");

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

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}
