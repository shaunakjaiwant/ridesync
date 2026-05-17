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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" type="image/png" href="/ridesync/logo-mark.png">
    <link rel="stylesheet" href="/ridesync/css/theme.css?v=<?php echo $styleVersion; ?>">
    <link rel="stylesheet" href="/ridesync/css/style.css?v=<?php echo $styleVersion; ?>">
</head>
<body class="driver-app">

<nav class="driver-navbar">
    <div class="driver-brand">
        <a href="/ridesync/pages/driver_dashboard.php" class="nav-logo" aria-label="RideSync Driver home">
            <img src="/ridesync/logo-mark.png" alt="RideSync" class="logo-img" />
            <span class="brand-copy">
                <strong>RideSync</strong>
                <span>Driver Workspace</span>
            </span>
        </a>
    </div>

    <ul class="driver-nav-links">
        <li><a class="<?php echo $currentDriverPage === 'driver_dashboard.php' ? 'is-active' : ''; ?>" href="/ridesync/pages/driver_dashboard.php">Home</a></li>
        <li><a class="<?php echo $currentDriverPage === 'driver_requests.php' ? 'is-active' : ''; ?>" href="/ridesync/pages/driver_requests.php">Requests & Rides</a></li>
        <li><a class="<?php echo $currentDriverPage === 'driver_profile.php' ? 'is-active' : ''; ?>" href="/ridesync/pages/driver_profile.php">Profile</a></li>
        <li>
            <a class="<?php echo $currentDriverPage === 'notifications.php' ? 'is-active' : ''; ?>" href="/ridesync/pages/notifications.php?actor_type=driver">
                Alerts
                <?php if ($unreadNotifications > 0): ?>
                    <span class="nav-badge"><?php echo min(99, $unreadNotifications); ?></span>
                <?php endif; ?>
            </a>
        </li>
    </ul>

    <div class="driver-nav-actions">
        <form action="/ridesync/actions/driver_auth_action.php" method="POST" class="nav-inline-form">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <input type="hidden" name="action_type" value="logout">
            <button type="submit" class="btn btn-logout">Logout</button>
        </form>
    </div>
</nav>

<main class="driver-main">
