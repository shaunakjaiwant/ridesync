<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/asset_helper.php';
require_once __DIR__ . '/view_helper.php';
$currentDriverPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
$styleVersion = ridesync_stylesheet_version();
$unreadNotifications = 0;

if (isset($_SESSION['driver_id'])) {
    $unreadNotifications = ridesync_unread_notification_count($conn, 'driver_id', (int) $_SESSION['driver_id']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RideSync Driver</title>
    <link rel="icon" type="image/png" href="/ridesync/logo-mark.png">
    <link rel="stylesheet" href="/ridesync/css/theme.css?v=<?php echo $styleVersion; ?>">
    <link rel="stylesheet" href="/ridesync/css/style.css?v=<?php echo $styleVersion; ?>">
</head>
<body class="driver-app">

<nav class="driver-navbar driver-cockpit-navbar">
    <div class="driver-brand">
        <a href="/ridesync/pages/driver_dashboard.php" class="nav-logo" aria-label="RideSync Driver home">
            <img src="/ridesync/logo-mark.png" alt="RideSync" class="logo-img" />
            <span class="brand-copy">
                <strong>RideSync</strong>
                <span>Driver Cockpit</span>
            </span>
        </a>
    </div>

    <ul class="driver-nav-links">
        <li>
            <a class="<?php echo $currentDriverPage === 'driver_dashboard.php' ? 'is-active' : ''; ?>" href="/ridesync/pages/driver_dashboard.php">
                <svg class="ui-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>
                Dashboard
            </a>
        </li>
        <li>
            <a class="<?php echo $currentDriverPage === 'driver_requests.php' ? 'is-active' : ''; ?>" href="/ridesync/pages/driver_requests.php">
                <svg class="ui-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4"/><path d="M12 16h4"/><path d="M8 11h.01"/><path d="M8 16h.01"/></svg>
                Queue
            </a>
        </li>
        <li>
            <a class="<?php echo $currentDriverPage === 'driver_earnings.php' ? 'is-active' : ''; ?>" href="/ridesync/pages/driver_earnings.php">
                <svg class="ui-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                Earnings
            </a>
        </li>
        <li>
            <a class="<?php echo $currentDriverPage === 'notifications.php' ? 'is-active' : ''; ?>" href="/ridesync/pages/notifications.php?actor_type=driver">
                <svg class="ui-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
                Alerts
                <?php if ($unreadNotifications > 0): ?>
                    <span class="nav-badge nav-badge-pulse"><?php echo min(99, $unreadNotifications); ?></span>
                <?php endif; ?>
            </a>
        </li>
    </ul>

    <div class="driver-nav-actions">
        <a href="/ridesync/pages/driver_profile.php" class="btn btn-user btn-pill">
            <svg class="ui-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            Profile
        </a>
        <form action="/ridesync/actions/driver_auth_action.php" method="POST" class="nav-inline-form">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <input type="hidden" name="action_type" value="logout">
            <button type="submit" class="btn btn-logout btn-icon-only" title="Logout" aria-label="Logout">
                <svg class="ui-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/></svg>
            </button>
        </form>
    </div>
</nav>

<main class="driver-main">
