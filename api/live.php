<?php
require_once __DIR__ . '/../includes/http_helper.php';

ridesync_require_method('GET');

ridesync_json_response([
    'ok' => true,
    'status' => 'alive',
    'service' => 'ridesync-web',
    'environment' => ridesync_app_env(),
    'timestamp' => date('c'),
]);

?>
