<?php

return [
    'default_queue' => (string) ridesync_env('RIDESYNC_QUEUE_DEFAULT', 'default'),
    'notification_queue' => (string) ridesync_env('RIDESYNC_QUEUE_NOTIFICATIONS', 'notifications'),
    'verification_queue' => (string) ridesync_env('RIDESYNC_QUEUE_VERIFICATION', 'verification'),
    'worker_sleep_seconds' => ridesync_env_int('RIDESYNC_QUEUE_SLEEP_SECONDS', 3),
    'stale_seconds' => ridesync_env_int('RIDESYNC_QUEUE_STALE_SECONDS', 600),
    'max_attempts' => ridesync_env_int('RIDESYNC_QUEUE_MAX_ATTEMPTS', 5),
];
