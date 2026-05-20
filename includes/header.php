<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/asset_helper.php';
require_once __DIR__ . '/view_helper.php';
$currentPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
$styleVersion = ridesync_stylesheet_version();
$unreadNotifications = 0;
$needsMapAssets = ridesync_page_needs_map_assets();

if (isset($_SESSION['user_id'])) {
    $unreadNotifications = ridesync_unread_notification_count($conn, 'user_id', (int) $_SESSION['user_id']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RideSync</title>
    <link rel="icon" type="image/png" href="/ridesync/logo-mark.png">
    <?php if ($needsMapAssets): ?>
    <link rel="stylesheet" href="/ridesync/assets/vendor/leaflet/1.9.4/leaflet.css?v=<?php echo ridesync_script_version('assets/vendor/leaflet/1.9.4/leaflet.css'); ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="/ridesync/css/theme.css?v=<?php echo $styleVersion; ?>">
    <link rel="stylesheet" href="/ridesync/css/style.css?v=<?php echo $styleVersion; ?>">
</head>
<body class="rider-app app-shell">

<nav class="navbar">
    <div class="nav-left">
        <a href="<?php echo isset($_SESSION['user_id']) ? '/ridesync/pages/dashboard.php' : '/ridesync/index.php'; ?>" class="nav-logo" aria-label="RideSync home">
            <img src="/ridesync/logo-mark.png" alt="RideSync" class="logo-img" />
            <span class="brand-copy">
                <strong>RideSync</strong>
                <span><?php echo isset($_SESSION['user_id']) ? 'Rider Workspace' : 'Campus mobility'; ?></span>
            </span>
        </a>
    </div>

    <ul class="nav-links">
        <?php if (isset($_SESSION['user_id'])): ?>
            <li><a class="<?php echo $currentPage === 'dashboard.php' ? 'is-active' : ''; ?>" href="/ridesync/pages/dashboard.php">Overview</a></li>
            <li><a class="<?php echo $currentPage === 'post_ride.php' ? 'is-active' : ''; ?>" href="/ridesync/pages/post_ride.php">Post Ride</a></li>
            <li><a class="<?php echo $currentPage === 'search_rides.php' ? 'is-active' : ''; ?>" href="/ridesync/pages/search_rides.php">Find Ride</a></li>
            <li><a class="<?php echo $currentPage === 'my_rides.php' || $currentPage === 'my_matches.php' ? 'is-active' : ''; ?>" href="/ridesync/pages/my_rides.php">My Trips</a></li>
            <li>
                <a class="<?php echo $currentPage === 'notifications.php' ? 'is-active' : ''; ?>" href="/ridesync/pages/notifications.php?actor_type=user">
                    Notifications
                    <?php if ($unreadNotifications > 0): ?>
                        <span class="nav-badge"><?php echo min(99, $unreadNotifications); ?></span>
                    <?php endif; ?>
                </a>
            </li>
        <?php endif; ?>
    </ul>

    <div class="nav-right">
        <?php if (isset($_SESSION['user_id'])): ?>
            <a href="/ridesync/pages/profile.php" class="btn btn-user">Profile</a>
            <form action="/ridesync/actions/logout_action.php" method="POST" class="nav-inline-form">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <button type="submit" class="btn btn-logout">Logout</button>
            </form>
        <?php endif; ?>
    </div>
</nav>

<main class="main-content">
