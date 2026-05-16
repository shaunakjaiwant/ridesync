<?php
require_once __DIR__ . '/../config/db.php';
$currentDriverPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
$cssFiles = glob(__DIR__ . '/../css/*.css') ?: [__DIR__ . '/../css/style.css'];
$styleVersion = max(array_map('filemtime', $cssFiles));
$unreadNotifications = 0;

if (isset($_SESSION['driver_id'])) {
    $notificationsTable = mysqli_query($conn, "SHOW TABLES LIKE 'notifications'");
    if ($notificationsTable && mysqli_num_rows($notificationsTable) > 0) {
        $stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM notifications WHERE driver_id = ? AND is_read = 0");
        mysqli_stmt_bind_param($stmt, "i", $_SESSION['driver_id']);
        mysqli_stmt_execute($stmt);
        $unreadNotifications = (int) (mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['total'] ?? 0);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RideSync Driver</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" type="image/png" href="/ridesync/logo-mark.png">
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
