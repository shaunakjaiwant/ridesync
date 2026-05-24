<?php

return [
    'name' => 'RideSync',
    'environment' => ridesync_app_env(),
    'debug' => ridesync_is_debug(),
    'timezone' => (string) ridesync_env('RIDESYNC_TIMEZONE', 'Asia/Kolkata'),
    'base_url' => (string) ridesync_env('RIDESYNC_BASE_URL', '/ridesync'),
];
