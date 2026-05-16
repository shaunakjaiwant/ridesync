<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../includes/http_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: /ridesync/index.php");
    exit;
}

ridesync_require_csrf('/ridesync/index.php', '');
ridesync_destroy_session();

header("Location: /ridesync/index.php");
exit;
