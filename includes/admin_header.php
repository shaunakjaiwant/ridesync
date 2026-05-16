<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/asset_helper.php';
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
$cssFiles = glob(__DIR__ . '/../css/*.css') ?: [__DIR__ . '/../css/style.css'];
$styleVersion = max(array_map('filemtime', $cssFiles));
$needsMapAssets = ridesync_page_needs_map_assets();
$adminNavItems = [
    'overview' => ['label' => 'Overview', 'icon' => 'O'],
    'users' => ['label' => 'Users', 'icon' => 'U'],
    'drivers' => ['label' => 'Drivers', 'icon' => 'D'],
    'rides' => ['label' => 'Rides', 'icon' => 'R'],
    'requests' => ['label' => 'Requests', 'icon' => 'Q'],
    'reports' => ['label' => 'Reports', 'icon' => '!'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RideSync Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" type="image/png" href="/ridesync/logo-mark.png">
    <?php if ($needsMapAssets): ?>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <?php endif; ?>
    <link rel="stylesheet" href="/ridesync/css/style.css?v=<?php echo $styleVersion; ?>">
</head>
<body class="driver-app admin-app admin-command-shell">

<aside class="admin-sidebar" aria-label="Admin navigation">
    <div class="admin-sidebar-top">
        <span class="admin-orb">RS</span>
        <div>
            <strong>Admin OS</strong>
            <span>Operations</span>
        </div>
    </div>

    <nav class="admin-side-nav">
        <?php foreach ($adminNavItems as $navKey => $item): ?>
            <a class="<?php echo $currentAdminSection === $navKey ? 'is-active' : ''; ?>" href="/ridesync/pages/admin_dashboard.php?section=<?php echo urlencode($navKey); ?>">
                <span><?php echo htmlspecialchars($item['icon']); ?></span>
                <?php echo htmlspecialchars($item['label']); ?>
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
        <input type="search" name="q" value="<?php echo htmlspecialchars($adminSearchValue); ?>" placeholder="users, rides, drivers, emails, vehicle numbers" aria-label="Search admin records" data-admin-global-search>
        <kbd>Ctrl K</kbd>
    </form>

    <div class="admin-topbar-right">
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
