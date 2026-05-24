<?php

return [
    'root' => ridesync_storage_path(),
    'logs' => ridesync_storage_path('logs'),
    'cache' => ridesync_storage_path('cache'),
    'rate_limits' => ridesync_storage_path('rate_limits'),
    'uploads' => RIDESYNC_ROOT . DIRECTORY_SEPARATOR . 'uploads',
    'exports' => ridesync_storage_path('exports'),
];
