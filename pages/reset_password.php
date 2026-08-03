<?php
require_once __DIR__ . '/../config/db.php';
$accountType = trim((string) ($_GET['role'] ?? 'rider'));
$accountType = in_array($accountType, ['rider', 'driver'], true) ? $accountType : 'rider';

header("Location: /ridesync/pages/forgot_password.php?role=" . urlencode($accountType));
exit();

