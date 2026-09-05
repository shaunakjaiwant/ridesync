<?php

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db.php';

if (isset($conn) && $conn instanceof mysqli) {
    $GLOBALS['conn'] = $conn;
}
