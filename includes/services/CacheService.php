<?php

class RideSyncCacheService
{
    private static $redis = false;

    public static function remember(string $key, int $ttlSeconds, callable $resolver)
    {
        $cached = self::get($key);
        if ($cached['hit']) {
            return $cached['value'];
        }

        $value = $resolver();
        self::set($key, $value, $ttlSeconds);
        return $value;
    }

    public static function get(string $key): array
    {
        $key = self::normalizeKey($key);
        if ($key === '') {
            return ['hit' => false, 'value' => null];
        }

        $redis = self::redis();
        if ($redis) {
            $value = $redis->get(self::redisKey($key));
            if ($value !== false && $value !== null) {
                return ['hit' => true, 'value' => self::decode($value)];
            }
        }

        $path = self::filePath($key);
        if (!is_file($path)) {
            return ['hit' => false, 'value' => null];
        }

        $record = json_decode((string) @file_get_contents($path), true);
        if (!is_array($record) || (int) ($record['expires_at'] ?? 0) <= time()) {
            @unlink($path);
            return ['hit' => false, 'value' => null];
        }

        return ['hit' => true, 'value' => $record['value'] ?? null];
    }

    public static function set(string $key, $value, int $ttlSeconds): bool
    {
        $key = self::normalizeKey($key);
        $ttlSeconds = max(1, min(86400, $ttlSeconds));
        if ($key === '') {
            return false;
        }

        $redis = self::redis();
        if ($redis) {
            return (bool) $redis->setex(self::redisKey($key), $ttlSeconds, self::encode($value));
        }

        $dir = self::cacheDir();
        if (!ridesync_ensure_directory($dir)) {
            return false;
        }

        $record = self::encode([
            'expires_at' => time() + $ttlSeconds,
            'value' => $value,
        ]);

        return file_put_contents(self::filePath($key), $record, LOCK_EX) !== false;
    }

    public static function delete(string $key): bool
    {
        $key = self::normalizeKey($key);
        if ($key === '') {
            return false;
        }

        $redis = self::redis();
        if ($redis) {
            $redis->del(self::redisKey($key));
        }

        $path = self::filePath($key);
        return !is_file($path) || @unlink($path);
    }

    public static function pruneFiles(int $limit = 500): int
    {
        $dir = self::cacheDir();
        if (!is_dir($dir)) {
            return 0;
        }

        $limit = max(1, min(5000, $limit));
        $removed = 0;
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $path) {
            if ($removed >= $limit) {
                break;
            }

            $record = json_decode((string) @file_get_contents($path), true);
            if (!is_array($record) || (int) ($record['expires_at'] ?? 0) <= time()) {
                @unlink($path);
                $removed++;
            }
        }

        return $removed;
    }

    private static function redis()
    {
        if (self::$redis !== false) {
            return self::$redis;
        }

        self::$redis = null;
        if (!class_exists('Redis')) {
            return null;
        }

        $url = trim((string) ridesync_env('RIDESYNC_REDIS_URL', ''));
        if ($url === '') {
            return null;
        }

        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return null;
        }

        try {
            $redis = new Redis();
            $redis->connect($parts['host'], (int) ($parts['port'] ?? 6379), 0.5);
            if (!empty($parts['pass'])) {
                $redis->auth($parts['pass']);
            }
            if (isset($parts['path']) && trim($parts['path'], '/') !== '') {
                $redis->select((int) trim($parts['path'], '/'));
            }
            self::$redis = $redis;
        } catch (Throwable $exception) {
            self::$redis = null;
        }

        return self::$redis;
    }

    private static function cacheDir(): string
    {
        return ridesync_storage_path('cache');
    }

    private static function filePath(string $key): string
    {
        return self::cacheDir() . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.json';
    }

    private static function redisKey(string $key): string
    {
        return 'ridesync:' . $key;
    }

    private static function normalizeKey(string $key): string
    {
        $key = strtolower(trim($key));
        $key = preg_replace('/[^a-z0-9_.:-]/', ':', $key);
        return substr((string) $key, 0, 180);
    }

    private static function encode($value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function decode(string $value)
    {
        $decoded = json_decode($value, true);
        return $decoded;
    }
}

?>
