<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/driver_account_helper.php';

ridesync_require_driver_login();
$driverId = (int) $_SESSION['driver_id'];

$stmt = mysqli_prepare($conn,
    "SELECT *
     FROM driver_ride_history
     WHERE driver_id = ?
     ORDER BY completed_at DESC
     LIMIT 50"
);
mysqli_stmt_bind_param($stmt, "i", $driverId);
mysqli_stmt_execute($stmt);
$history = mysqli_stmt_get_result($stmt);

require_once __DIR__ . '/../includes/driver_header.php';
?>

<div class="driver-page-header">
    <div>
        <span class="driver-kicker">Ride History</span>
        <h1>Past trips</h1>
    </div>
</div>

<section class="driver-panel">
    <?php if (mysqli_num_rows($history) === 0): ?>
        <p class="driver-empty">No completed trips yet.</p>
    <?php else: ?>
        <div class="driver-list">
            <?php while ($trip = mysqli_fetch_assoc($history)): ?>
                <div class="driver-list-item">
                    <div>
                        <strong><?php echo htmlspecialchars($trip['pickup']); ?> <span class="route-arrow">&rarr;</span> <?php echo htmlspecialchars($trip['drop_location']); ?></strong>
                        <span><?php echo number_format((float) $trip['distance_km'], 1); ?> km &middot; <?php echo date('M j, g:i A', strtotime($trip['completed_at'])); ?></span>
                    </div>
                    <strong><?php echo ridesync_format_money($trip['fare']); ?></strong>
                </div>
            <?php endwhile; ?>
        </div>
    <?php endif; ?>
</section>

<?php require_once __DIR__ . '/../includes/driver_footer.php'; ?>
