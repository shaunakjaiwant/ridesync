<?php
require_once __DIR__ . '/../config/db.php';
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
}
$adminSearchValue = trim($_GET['q'] ?? '');
$styleVersion = ridesync_stylesheet_version();
$needsMapAssets = ridesync_page_needs_map_assets();
$adminNavItems = [
    'overview' => 'Overview',
    'users' => 'Users',
    'drivers' => 'Drivers',
    'rides' => 'Rides',
    'requests' => 'Requests',
    'reports' => 'Reports',
    'remove' => 'Remove',
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
    <a class="admin-sidebar-top" href="/ridesync/pages/admin_dashboard.php?section=profiles" aria-label="View admin profiles">
        <span class="admin-orb">RS</span>
        <div>
            <strong>Admin OS</strong>
            <span>Operations</span>
        </div>
    </a>

    <nav class="admin-side-nav">
        <?php foreach ($adminNavItems as $navKey => $label): ?>
            <a class="<?php echo $currentAdminSection === $navKey ? 'is-active' : ''; ?>" href="/ridesync/pages/admin_dashboard.php?section=<?php echo urlencode($navKey); ?>">
                <?php echo htmlspecialchars($label); ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="admin-sidebar-bottom">
        <a href="/ridesync/pages/dashboard.php">Rider App</a>
        <a href="/ridesync/pages/driver_login.php">Driver App</a>
    </div>
</aside>

<header class="admin-topbar">
    <form class="admin-global-search" action="/ridesync/pages/admin_dashboard.php" method="GET" role="search">
        <input type="hidden" name="section" value="<?php echo htmlspecialchars($currentAdminSection); ?>">
        <span>Search</span>
        <input type="search" name="q" value="<?php echo htmlspecialchars($adminSearchValue); ?>" placeholder="Search admin records" aria-label="Search admin records" data-admin-global-search data-search-context="admin_global">
        <kbd>Ctrl K</kbd>
    </form>

    <div class="admin-topbar-right">
        <a class="admin-mobile-profile-link mobile-only" href="/ridesync/pages/admin_dashboard.php?section=profiles" aria-label="View admin profiles">
            <span class="admin-mobile-profile-orb">RS</span>
            <span class="admin-mobile-profile-copy">
                <strong>Admin OS</strong>
                <small>Profiles</small>
            </span>
        </a>
        <span class="admin-system-pill"><span></span> System live</span>
        <div class="admin-profile-chip">
            <strong><?php echo htmlspecialchars($_SESSION['admin_name'] ?? 'Admin'); ?></strong>
            <span><?php echo htmlspecialchars($_SESSION['admin_role'] ?? 'operator'); ?></span>
        </div>
        <form action="/ridesync/actions/admin_auth_action.php" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <input type="hidden" name="action_type" value="logout">
            <button type="submit" class="btn btn-logout btn-sm">Logout</button>
        </form>
        <a class="admin-logo-link" href="/ridesync/pages/admin_dashboard.php?section=overview" aria-label="RideSync Admin home">
            <img src="/ridesync/logo-mark.png" alt="RideSync" class="logo-img">
        </a>
    </div>
</header>

<main class="driver-main admin-main">
