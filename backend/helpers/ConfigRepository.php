<?php

namespace RideSync\Backend\Helpers;

final class ConfigRepository
{
    private static ?array $config = null;

    public static function get(string $key, $default = null)
    {
        $config = self::all();
        if ($key === '') {
            return $config;
        }

        $cursor = $config;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return $default;
            }
            $cursor = $cursor[$segment];
        }

        return $cursor;
    }

    public static function all(): array
    {
        if (self::$config !== null) {
            return self::$config;
        }

        $root = defined('RIDESYNC_ROOT') ? RIDESYNC_ROOT : dirname(__DIR__, 2);
        $configDir = $root . DIRECTORY_SEPARATOR . 'config';
        $files = [
            'app',
            'database',
            'cache',
            'websocket',
            'ai',
            'kyc',
            'security',
            'queue',
            'storage',
        ];

        $loaded = [];
        foreach ($files as $name) {
            $path = $configDir . DIRECTORY_SEPARATOR . $name . '.php';
            $loaded[$name] = is_file($path) ? (require $path) : [];
        }

        self::$config = $loaded;
        return self::$config;
    }
}
