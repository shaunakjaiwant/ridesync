<?php

namespace RideSync\Realtime\PubSub;

final class RedisPubSubPublisher
{
    public static function publish(string $channel, array $message): bool
    {
        $url = trim((string) ridesync_config('cache.redis_url', ridesync_env('RIDESYNC_REDIS_URL', '')));
        if ($url === '') {
            return false;
        }

        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return false;
        }

        $host = (string) $parts['host'];
        $port = (int) ($parts['port'] ?? 6379);
        $database = isset($parts['path']) ? (int) trim((string) $parts['path'], '/') : null;
        $password = isset($parts['pass']) ? urldecode((string) $parts['pass']) : null;
        $payload = json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            return false;
        }

        $socket = @fsockopen($host, $port, $errno, $errstr, 0.15);
        if (!$socket) {
            return false;
        }

        stream_set_timeout($socket, 1);
        try {
            if ($password !== null && $password !== '') {
                self::command($socket, ['AUTH', $password]);
            }
            if ($database !== null && $database >= 0) {
                self::command($socket, ['SELECT', (string) $database]);
            }

            self::command($socket, ['PUBLISH', $channel, $payload]);
            fclose($socket);
            return true;
        } catch (\Throwable $exception) {
            if (is_resource($socket)) {
                fclose($socket);
            }
            return false;
        }
    }

    private static function command($socket, array $arguments): string
    {
        fwrite($socket, self::encode($arguments));
        return (string) fgets($socket);
    }

    private static function encode(array $arguments): string
    {
        $command = '*' . count($arguments) . "\r\n";
        foreach ($arguments as $argument) {
            $argument = (string) $argument;
            $command .= '$' . strlen($argument) . "\r\n" . $argument . "\r\n";
        }

        return $command;
    }
}
