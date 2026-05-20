<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/admin_helper.php';

$type = strtolower($argv[1] ?? 'user');
if (!in_array($type, ['user', 'driver'], true)) {
    fwrite(STDERR, "Unsupported view-as type\n");
    exit(1);
}

$adminResult = mysqli_query($conn, "SELECT id, name, role FROM admin_users WHERE status = 'active' ORDER BY FIELD(role, 'super_admin', 'moderator'), id LIMIT 1");
$admin = $adminResult ? mysqli_fetch_assoc($adminResult) : null;
if (!$admin) {
    fwrite(STDERR, "No active admin available for view-as smoke\n");
    exit(1);
}

$table = $type === 'user' ? 'users' : 'driver_accounts';
$idResult = mysqli_query($conn, "SELECT id FROM {$table} ORDER BY id LIMIT 1");
$target = $idResult ? mysqli_fetch_assoc($idResult) : null;
if (!$target) {
    fwrite(STDERR, "No {$type} record available for view-as smoke\n");
    exit(1);
}

$_SESSION['admin_id'] = (int) $admin['id'];
$_SESSION['admin_name'] = $admin['name'];
$_SESSION['admin_role'] = $admin['role'];
$_SESSION['csrf_token'] = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
$_SERVER['SCRIPT_NAME'] = '/ridesync/pages/admin_view_as.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = [
    'type' => $type,
    'id' => (int) $target['id'],
];

ob_start();
require __DIR__ . '/../pages/admin_view_as.php';
$html = ob_get_clean();

$ok = str_contains($html, 'Read-Only Panel Inspector')
    && str_contains($html, 'No session takeover')
    && str_contains($html, $type === 'user' ? 'Posted Rides' : 'Direct Requests');

echo $ok ? "[OK] admin view-as {$type} rendered\n" : "[FAIL] admin view-as {$type} render missing expected shell\n";
exit($ok ? 0 : 1);
?>
