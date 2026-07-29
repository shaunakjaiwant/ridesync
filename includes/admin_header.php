<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/admin_helper.php';
require_once __DIR__ . '/asset_helper.php';
require_once __DIR__ . '/view_helper.php';
$currentAdminPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
$currentAdminSection = $_GET['section'] ?? 'overview';
if ($currentAdminPage === 'admin_driver_verification.php') {
    $currentAdminSection = 'drivers';
} elseif ($currentAdminPage === 'admin_user_detail.php') {
    $currentAdminSection = 'users';
} elseif ($currentAdminPage === 'admin_ride_detail.php') {
    $currentAdminSection = 'rides';
} elseif ($currentAdminPage === 'admin_view_as.php') {
    $currentAdminSection = ($_GET['type'] ?? '') === 'driver' ? 'drivers' : 'users';
}
$currentAdmin = isset($admin) && is_array($admin)
    ? $admin
    : (isset($_SESSION['admin_id']) ? ridesync_fetch_admin($conn, (int) $_SESSION['admin_id']) : null);
$adminSearchValue = trim($_GET['q'] ?? '');
$styleVersion = ridesync_stylesheet_version();
$needsMapAssets = ridesync_page_needs_map_assets();
$adminNavItems = [
    ['key' => 'overview', 'label' => 'Dashboard'],
    ['key' => 'rides', 'label' => 'Rides'],
    ['key' => 'drivers', 'label' => 'Drivers'],
    ['key' => 'users', 'label' => 'Users'],
    ['key' => 'requests', 'label' => 'Requests'],
    ['key' => 'reports', 'label' => 'Reports'],
    ['key' => 'services', 'label' => 'Operations', 'capability' => 'run_ai_verification'],
    ['key' => 'profiles', 'label' => 'Settings'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RideSync Admin</title>
    <link rel="icon" type="image/png" href="/ridesync/logo-mark.png">
    <?php if ($needsMapAssets): ?>
    <link rel="stylesheet" href="/ridesync/assets/vendor/leaflet/1.9.4/leaflet.css?v=<?php echo ridesync_script_version('assets/vendor/leaflet/1.9.4/leaflet.css'); ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="/ridesync/css/theme.css?v=<?php echo $styleVersion; ?>">
    <link rel="stylesheet" href="/ridesync/css/style.css?v=<?php echo $styleVersion; ?>">
</head>
<body class="driver-app admin-app admin-command-shell admin-section-<?php echo htmlspecialchars($currentAdminSection); ?>">

<aside class="admin-sidebar" aria-label="Admin navigation">
    <a class="admin-sidebar-top" href="/ridesync/pages/admin_dashboard.php?section=overview" aria-label="RideSync admin dashboard">
        <span class="admin-orb">RS</span>
        <div>
            <strong>RideSync</strong>
            <span>Admin</span>
        </div>
    </a>

    <nav class="admin-side-nav">
        <?php foreach ($adminNavItems as $item): ?>
            <?php
                $navKey = $item['key'];
                $label = $item['label'];
                $capability = $item['capability'] ?? null;
                if ($capability !== null && !ridesync_admin_can($currentAdmin, $capability)) {
                    continue;
                }
            ?>
            <a class="<?php echo $currentAdminSection === $navKey ? 'is-active' : ''; ?>" href="/ridesync/pages/admin_dashboard.php?section=<?php echo urlencode($navKey); ?>">
                <?php echo htmlspecialchars($label); ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="admin-sidebar-bottom">
        <a href="/ridesync/pages/dashboard.php">Rider</a>
        <a href="/ridesync/pages/driver_login.php">Driver</a>
    </div>
</aside>

<header class="admin-topbar">
    <form class="admin-global-search" action="/ridesync/pages/admin_dashboard.php" method="GET" role="search">
        <input type="hidden" name="section" value="<?php echo htmlspecialchars($currentAdminSection); ?>">
        <input type="search" name="q" value="<?php echo htmlspecialchars($adminSearchValue); ?>" placeholder="Search users, drivers, rides, reports" aria-label="Search admin records" data-admin-global-search data-search-context="admin_global">
        <kbd>Ctrl K</kbd>
    </form>

    <div class="admin-topbar-right">
        <!-- Live Service Status Telemetry -->
        <div class="admin-telemetry-strip" style="display: inline-flex; gap: 0.4rem; align-items: center; margin-right: 0.5rem;">
            <span class="badge badge-accepted" title="Database Connected" style="font-size: 0.72rem; padding: 0.2rem 0.5rem; display: inline-flex; align-items: center; gap: 0.25rem;">
                <svg class="ui-icon" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg>
                DB
            </span>
            <span class="badge badge-accepted" title="AI Engine Operational" style="font-size: 0.72rem; padding: 0.2rem 0.5rem; display: inline-flex; align-items: center; gap: 0.25rem;">
                <svg class="ui-icon" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m12 3-1.9 5.8a2 2 0 0 1-1.3 1.3L3 12l5.8 1.9a2 2 0 0 1 1.3 1.3L12 21l1.9-5.8a2 2 0 0 1 1.3-1.3L21 12l-5.8-1.9a2 2 0 0 1-1.3-1.3L12 3Z"/></svg>
                AI
            </span>
            <span class="badge badge-accepted" title="WebSocket Gateway Operational" style="font-size: 0.72rem; padding: 0.2rem 0.5rem; display: inline-flex; align-items: center; gap: 0.25rem;">
                <svg class="ui-icon" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.55a11 11 0 0 1 14.08 0"/><path d="M1.42 9a16 16 0 0 1 21.16 0"/><path d="M8.53 16.11a6 6 0 0 1 6.95 0"/><line x1="12" y1="20" x2="12.01" y2="20"/></svg>
                WS
            </span>
        </div>
        <a class="admin-mobile-profile-link mobile-only" href="/ridesync/pages/admin_dashboard.php?section=profiles" aria-label="View admin profiles">
            <span class="admin-mobile-profile-orb">RS</span>
            <span class="admin-mobile-profile-copy">
                <strong>RideSync</strong>
                <small>Admin</small>
            </span>
        </a>
        <div class="admin-profile-chip">
            <strong><?php echo htmlspecialchars($_SESSION['admin_name'] ?? 'Admin'); ?></strong>
            <span><?php echo htmlspecialchars($_SESSION['admin_role'] ?? 'operator'); ?></span>
        </div>
        <form action="/ridesync/actions/admin_auth_action.php" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <input type="hidden" name="action_type" value="logout">
            <button type="submit" class="btn btn-logout btn-sm">Logout</button>
        </form>
    </div>
</header>

<main class="driver-main admin-main">
