<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/asset_helper.php';
require_once __DIR__ . '/view_helper.php';
require_once __DIR__ . '/db_helper.php';
$currentPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
$styleVersion = ridesync_stylesheet_version();
$unreadNotifications = 0;
$needsMapAssets = ridesync_page_needs_map_assets();

if (isset($_SESSION['user_id'])) {
    if (ridesync_is_user_suspended($conn, (int) $_SESSION['user_id'])) {
        unset($_SESSION['user_id'], $_SESSION['user_name'], $_SESSION['selected_role']);
        $_SESSION['login_error'] = "Your rider account has been suspended by RideSync administration. Please contact support.";
        header("Location: /ridesync/pages/login.php");
        exit();
    }
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
    <link rel="manifest" href="/ridesync/manifest.json">
    <meta name="theme-color" content="#0d1117">
    <link rel="apple-touch-icon" href="/ridesync/logo-mark.png">
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('/ridesync/sw.js')
                    .then(registration => {
                        console.log('SW registered: ', registration);
                    })
                    .catch(registrationError => {
                        console.log('SW registration failed: ', registrationError);
                    });
            });
        }
    </script>
    <?php if ($needsMapAssets): ?>
    <link rel="stylesheet" href="/ridesync/assets/vendor/leaflet/1.9.4/leaflet.css?v=<?php echo ridesync_script_version('assets/vendor/leaflet/1.9.4/leaflet.css'); ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="/ridesync/css/theme.css?v=<?php echo $styleVersion; ?>">
    <link rel="stylesheet" href="/ridesync/css/style.css?v=<?php echo $styleVersion; ?>">
</head>
<body class="rider-app app-shell">

<nav class="navbar rider-navbar-glass">
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
            <li>
                <a class="<?php echo $currentPage === 'dashboard.php' ? 'is-active' : ''; ?>" href="/ridesync/pages/dashboard.php">
                    <svg class="ui-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>
                    Dashboard
                </a>
            </li>
            <li>
                <a class="<?php echo $currentPage === 'post_ride.php' ? 'is-active' : ''; ?>" href="/ridesync/pages/post_ride.php">
                    <svg class="ui-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 8v8"/><path d="M8 12h8"/></svg>
                    Post Ride
                </a>
            </li>
            <li>
                <a class="<?php echo $currentPage === 'search_rides.php' ? 'is-active' : ''; ?>" href="/ridesync/pages/search_rides.php">
                    <svg class="ui-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                    Search
                </a>
            </li>
            <li>
                <a class="<?php echo $currentPage === 'my_rides.php' || $currentPage === 'my_matches.php' ? 'is-active' : ''; ?>" href="/ridesync/pages/my_rides.php">
                    <svg class="ui-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.5 2.8C2.05 10.9 2 11.2 2 11.5V16c0 .6.4 1 1 1h2"/><circle cx="7" cy="17" r="2"/><path d="M9 17h6"/><circle cx="17" cy="17" r="2"/></svg>
                    Trips
                </a>
            </li>
            <li>
                <a class="<?php echo $currentPage === 'notifications.php' ? 'is-active' : ''; ?>" href="/ridesync/pages/notifications.php?actor_type=user">
                    <svg class="ui-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
                    Alerts
                    <?php if ($unreadNotifications > 0): ?>
                        <span class="nav-badge nav-badge-pulse"><?php echo min(99, $unreadNotifications); ?></span>
                    <?php endif; ?>
                </a>
            </li>
        <?php endif; ?>
    </ul>

    <div class="nav-right">
        <?php if (isset($_SESSION['user_id'])): ?>
            <a href="/ridesync/pages/profile.php" class="btn btn-user btn-pill">
                <svg class="ui-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                Profile
            </a>
            <form action="/ridesync/actions/logout_action.php" method="POST" class="nav-inline-form">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <button type="submit" class="btn btn-logout btn-icon-only" title="Logout" aria-label="Logout">
                    <svg class="ui-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/></svg>
                </button>
            </form>
        <?php endif; ?>
    </div>
</nav>

<main class="main-content">
