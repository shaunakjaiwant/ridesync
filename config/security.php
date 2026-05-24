<?php

return [
    'cookie_secure' => ridesync_cookie_secure_required(),
    'trust_proxy' => ridesync_env_bool('RIDESYNC_TRUST_PROXY', false),
    'metrics_token' => (string) ridesync_env('RIDESYNC_METRICS_TOKEN', ''),
    'document_encryption_key' => (string) ridesync_env('RIDESYNC_DOCUMENT_ENCRYPTION_KEY', ''),
    'repair_log_key' => (string) ridesync_env('RIDESYNC_REPAIR_LOG_KEY', ''),
    'session' => [
        'idle_seconds' => ridesync_env_int('RIDESYNC_SESSION_IDLE_SECONDS', 30 * 60),
        'absolute_seconds' => ridesync_env_int('RIDESYNC_SESSION_ABSOLUTE_SECONDS', 8 * 60 * 60),
        'rotate_seconds' => ridesync_env_int('RIDESYNC_SESSION_ROTATE_SECONDS', 15 * 60),
    ],
];
