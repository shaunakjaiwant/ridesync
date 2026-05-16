<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../config/db.php';

$section = $argv[1] ?? 'overview';
$query = $argv[2] ?? '';
$allowed = ['overview', 'drivers', 'users', 'rides', 'requests', 'reports', 'analytics', 'system'];
if (!in_array($section, $allowed, true)) {
    fwrite(STDERR, "Unsupported section\n");
    exit(1);
}

$result = mysqli_query($conn, "SELECT id, name, role FROM admin_users WHERE status = 'active' ORDER BY id LIMIT 1");
$admin = $result ? mysqli_fetch_assoc($result) : null;
if (!$admin) {
    fwrite(STDERR, "No active admin available for render smoke\n");
    exit(1);
}

$_SESSION['admin_id'] = (int) $admin['id'];
$_SESSION['admin_name'] = $admin['name'];
$_SESSION['admin_role'] = $admin['role'];
$_SESSION['csrf_token'] = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
$_SERVER['SCRIPT_NAME'] = '/ridesync/pages/admin_dashboard.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = ['section' => $section];
if ($query !== '') {
    $_GET['q'] = $query;
}

ob_start();
require __DIR__ . '/../pages/admin_dashboard.php';
$html = ob_get_clean();

$ok = str_contains($html, 'RideSync Command Center')
    && str_contains($html, 'admin-command-center')
    && str_contains($html, 'admin-sidebar');

echo $ok ? "[OK] admin {$section} rendered\n" : "[FAIL] admin {$section} render missing expected shell\n";
exit($ok ? 0 : 1);
?>
