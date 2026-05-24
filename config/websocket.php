<?php

return [
    'url' => (string) ridesync_env('RIDESYNC_WEBSOCKET_URL', ''),
    'shared_token' => (string) ridesync_env('RIDESYNC_WS_SHARED_TOKEN', ''),
    'poll_ms' => ridesync_env_int('RIDESYNC_WS_POLL_MS', 1500),
    'db_pool' => ridesync_env_int('RIDESYNC_WS_DB_POOL', 5),
    'heartbeat_seconds' => ridesync_env_int('RIDESYNC_WS_HEARTBEAT_SECONDS', 25),
];
