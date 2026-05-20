<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/admin_helper.php';

$section = $argv[1] ?? 'overview';
$query = $argv[2] ?? '';
$sectionAliases = [
    'analytics' => 'overview',
    'system' => 'services',
];
$expectedSection = $sectionAliases[$section] ?? $section;
$allowed = ['overview', 'profiles', 'drivers', 'users', 'rides', 'requests', 'reports', 'remove', 'services', 'audit', 'bulk', 'analytics', 'system'];
if (!in_array($section, $allowed, true)) {
    fwrite(STDERR, "Unsupported section\n");
    exit(1);
}

$result = mysqli_query($conn, "SELECT id, name, role FROM admin_users WHERE status = 'active' ORDER BY FIELD(role, 'super_admin', 'moderator'), id LIMIT 1");
$admin = $result ? mysqli_fetch_assoc($result) : null;
if (!$admin) {
    fwrite(STDERR, "No active admin available for render smoke\n");
    exit(1);
}
if ($expectedSection === 'remove' && !ridesync_admin_can($admin, 'remove_accounts')) {
    $expectedSection = 'overview';
}
if ($expectedSection === 'services' && !ridesync_admin_can($admin, 'run_ai_verification')) {
    $expectedSection = 'overview';
}
if ($expectedSection === 'audit' && !ridesync_admin_can($admin, 'view_audit_logs')) {
    $expectedSection = 'overview';
}
if ($expectedSection === 'bulk' && !ridesync_admin_can($admin, 'run_bulk_operations')) {
    $expectedSection = 'overview';
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

$ok = str_contains($html, 'admin-command-center')
    && str_contains($html, 'admin-sidebar')
    && !str_contains($html, 'admin-top-nav');

$sectionMarkers = [
    'overview' => 'Operational Inbox',
    'profiles' => 'admin-profile-showcase',
    'drivers' => '<h2>Drivers</h2>',
    'users' => '<h2>Users</h2>',
    'rides' => '<h2>Rides</h2>',
    'requests' => '<h2>Direct Driver Requests</h2>',
    'reports' => '<h2>User Reports</h2>',
    'remove' => '<h2>Remove Accounts</h2>',
    'services' => '<h2>AI Operations Monitor</h2>',
    'audit' => '<h2>Administrative Activity</h2>',
    'bulk' => '<h2>Safeguarded System Cleanup</h2>',
];

if ($ok) {
    $ok = str_contains($html, $sectionMarkers[$expectedSection] ?? 'RideSync Command Center');
    if ($expectedSection !== 'profiles') {
        $ok = $ok
            && !str_contains($html, 'Admin Profiles')
            && !str_contains($html, 'Leadership Command Team');
    }
}

echo $ok ? "[OK] admin {$section} rendered\n" : "[FAIL] admin {$section} render missing expected shell\n";
exit($ok ? 0 : 1);
?>
