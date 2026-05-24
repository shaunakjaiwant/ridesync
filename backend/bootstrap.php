<?php

spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'RideSync\\Backend\\' => __DIR__,
        'RideSync\\Realtime\\' => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'realtime',
    ];

    $baseDir = null;
    $relative = null;
    foreach ($prefixes as $prefix => $candidateBaseDir) {
        if (strncmp($class, $prefix, strlen($prefix)) === 0) {
            $baseDir = $candidateBaseDir;
            $relative = substr($class, strlen($prefix));
            break;
        }
    }

    if ($baseDir === null || $relative === null) {
        return;
    }

    $path = $baseDir . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';
    if (!is_file($path)) {
        $segments = explode('\\', $relative);
        if (isset($segments[0])) {
            $segments[0] = strtolower($segments[0]);
            $path = $baseDir . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $segments) . '.php';
        }
    }
    if (is_file($path)) {
        require_once $path;
    }
});

if (!function_exists('ridesync_config')) {
    function ridesync_config(string $key, $default = null) {
        return \RideSync\Backend\Helpers\ConfigRepository::get($key, $default);
    }
}
