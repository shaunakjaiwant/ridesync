<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/admin_helper.php';

ridesync_require_admin_login();
header("Location: /ridesync/pages/admin_dashboard.php?section=reports", true, 302);
exit();
