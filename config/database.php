<?php

return [
    'default' => 'mysql',
    'connections' => [
        'mysql' => [
            'host' => (string) ridesync_env('RIDESYNC_DB_HOST', 'localhost'),
            'port' => (int) ridesync_env('RIDESYNC_DB_PORT', 3306),
            'database' => (string) ridesync_env('RIDESYNC_DB_NAME', 'ridesync_db'),
            'username' => (string) ridesync_env('RIDESYNC_DB_USER', 'root'),
            'password' => (string) ridesync_env('RIDESYNC_DB_PASSWORD', ''),
            'connect_timeout' => ridesync_env_int('RIDESYNC_DB_CONNECT_TIMEOUT', 5),
            'charset' => 'utf8mb4',
        ],
    ],
    'slow_query_ms' => ridesync_env_int('RIDESYNC_DB_SLOW_QUERY_MS', 250),
];
