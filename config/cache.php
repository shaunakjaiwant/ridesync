<?php

return [
    'default' => (string) ridesync_env('RIDESYNC_CACHE_DRIVER', 'file'),
    'redis_url' => (string) ridesync_env('RIDESYNC_REDIS_URL', ''),
    'prefix' => (string) ridesync_env('RIDESYNC_CACHE_PREFIX', 'ridesync'),
    'dashboard_ttl_seconds' => ridesync_env_int('RIDESYNC_DASHBOARD_CACHE_TTL', 30),
    'suggestions_ttl_seconds' => ridesync_env_int('RIDESYNC_SEARCH_CACHE_TTL', 60),
];
