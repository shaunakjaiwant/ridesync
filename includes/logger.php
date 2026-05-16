<?php

if (!function_exists('ridesync_log')) {
    function ridesync_log_redact($value) {
        if (!is_array($value)) {
            return $value;
        }

        $redacted = [];
        foreach ($value as $key => $item) {
            $keyString = strtolower((string) $key);
            if (preg_match('/password|token|secret|cookie|authorization|csrf/', $keyString)) {
                $redacted[$key] = '[redacted]';
                continue;
            }

            $redacted[$key] = ridesync_log_redact($item);
        }

        return $redacted;
    }

    function ridesync_log($level, $message, array $context = []) {
        $level = strtolower((string) $level);
        $allowed = ['debug', 'info', 'notice', 'warning', 'error', 'critical'];
        if (!in_array($level, $allowed, true)) {
            $level = 'info';
        }

        $entry = [
            'timestamp' => date('c'),
            'level' => $level,
            'message' => (string) $message,
            'request_id' => function_exists('ridesync_request_id') ? ridesync_request_id() : null,
            'context' => ridesync_log_redact($context),
        ];

        $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        $logDir = function_exists('ridesync_storage_path')
            ? ridesync_storage_path('logs')
            : dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';

        if (!is_dir($logDir) && !mkdir($logDir, 0755, true)) {
            error_log($line);
            return false;
        }

        $file = $logDir . DIRECTORY_SEPARATOR . 'app-' . date('Y-m-d') . '.log';
        return file_put_contents($file, $line, FILE_APPEND | LOCK_EX) !== false;
    }

    function ridesync_log_exception(Throwable $exception, array $context = []) {
        $context['exception'] = [
            'class' => get_class($exception),
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
        ];

        return ridesync_log('error', 'Unhandled exception', $context);
    }
}

?>
