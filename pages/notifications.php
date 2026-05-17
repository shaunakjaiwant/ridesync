<?php
require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['user_id']) && !isset($_SESSION['driver_id'])) {
    header("Location: /ridesync/index.php");
    exit();
}

$requestedActor = $_GET['actor_type'] ?? '';
$isDriver = false;
if ($requestedActor === 'driver' && isset($_SESSION['driver_id'])) {
    $isDriver = true;
} elseif ($requestedActor === 'user' && isset($_SESSION['user_id'])) {
    $isDriver = false;
} elseif (isset($_SESSION['driver_id']) && !isset($_SESSION['user_id'])) {
    $isDriver = true;
}
$actorType = $isDriver ? 'driver' : 'user';
$actorColumn = $isDriver ? 'driver_id' : 'user_id';
$actorId = $isDriver ? (int) $_SESSION['driver_id'] : (int) $_SESSION['user_id'];
$notifications = [];
$unreadCount = 0;
$readCount = 0;

$notificationsTable = mysqli_query($conn, "SHOW TABLES LIKE 'notifications'");
if ($notificationsTable && mysqli_num_rows($notificationsTable) > 0) {
    $sql = "SELECT * FROM notifications WHERE {$actorColumn} = ? ORDER BY created_at DESC LIMIT 60";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $actorId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) {
        $notifications[] = $row;
        if ((int) $row['is_read'] === 0) {
            $unreadCount++;
        } else {
            $readCount++;
        }
    }
}

if ($isDriver) {
    require_once __DIR__ . '/../includes/driver_header.php';
} else {
    require_once __DIR__ . '/../includes/header.php';
}
?>

<div class="page-header">
    <h1>Notifications</h1>
    <p>Ride requests, match decisions, driver updates, and safety-related alerts appear here.</p>
</div>

<?php ridesync_flash('notification_success', 'alert-success'); ?>
<?php ridesync_flash('notification_error', 'alert-error'); ?>

<section class="notification-panel">
    <div class="notification-toolbar">
        <div>
            <span class="fare-kicker">Inbox</span>
            <h2><?php echo (int) $unreadCount; ?> unread</h2>
            <p><?php echo count($notifications); ?> total notification<?php echo count($notifications) === 1 ? '' : 's'; ?></p>
        </div>
        <?php if (count($notifications) > 0): ?>
            <div class="notification-toolbar-actions">
                <?php if ($unreadCount > 0): ?>
                    <form action="/ridesync/actions/notification_action.php" method="POST" class="notification-toolbar-form">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="actor_type" value="<?php echo htmlspecialchars($actorType); ?>">
                        <input type="hidden" name="action_type" value="mark_all_read">
                        <button type="submit" class="btn btn-secondary btn-sm">Mark All as Read</button>
                    </form>
                <?php endif; ?>
                <?php if ($readCount > 0): ?>
                    <form action="/ridesync/actions/notification_action.php" method="POST" class="notification-toolbar-form" data-confirm-message="Clear all read notifications?">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="actor_type" value="<?php echo htmlspecialchars($actorType); ?>">
                        <input type="hidden" name="action_type" value="clear_read">
                        <button type="submit" class="btn btn-secondary btn-sm">Clear Read</button>
                    </form>
                <?php endif; ?>
                <form action="/ridesync/actions/notification_action.php" method="POST" class="notification-toolbar-form" data-confirm-message="Clear every notification in this inbox?">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="actor_type" value="<?php echo htmlspecialchars($actorType); ?>">
                    <input type="hidden" name="action_type" value="clear_all">
                    <button type="submit" class="btn btn-warning btn-sm">Clear All</button>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <?php if (count($notifications) === 0): ?>
        <div class="empty-state">
            <p>No notifications yet.</p>
        </div>
    <?php else: ?>
        <div class="notification-list">
            <?php foreach ($notifications as $notification): ?>
                <article class="notification-item <?php echo (int) $notification['is_read'] === 0 ? 'is-unread' : ''; ?>">
                    <div class="notification-dot"></div>
                    <div>
                        <h3><?php echo htmlspecialchars($notification['title']); ?></h3>
                        <p><?php echo htmlspecialchars($notification['message']); ?></p>
                        <span><?php echo date('M j, g:i A', strtotime($notification['created_at'])); ?></span>
                    </div>
                    <div class="notification-item-actions">
                    <?php if ((int) $notification['is_read'] === 0): ?>
                        <form action="/ridesync/actions/notification_action.php" method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="actor_type" value="<?php echo htmlspecialchars($actorType); ?>">
                            <input type="hidden" name="action_type" value="mark_one_read">
                            <input type="hidden" name="notification_id" value="<?php echo (int) $notification['id']; ?>">
                            <button type="submit" class="btn btn-secondary btn-sm">Read</button>
                        </form>
                    <?php endif; ?>
                        <form action="/ridesync/actions/notification_action.php" method="POST" data-confirm-message="Clear this notification?">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="actor_type" value="<?php echo htmlspecialchars($actorType); ?>">
                            <input type="hidden" name="action_type" value="clear_one">
                            <input type="hidden" name="notification_id" value="<?php echo (int) $notification['id']; ?>">
                            <button type="submit" class="btn btn-secondary btn-sm">Clear</button>
                        </form>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php
if ($isDriver) {
    require_once __DIR__ . '/../includes/driver_footer.php';
} else {
    require_once __DIR__ . '/../includes/footer.php';
}
?>
