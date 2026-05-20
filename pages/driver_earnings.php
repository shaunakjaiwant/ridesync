<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/driver_account_helper.php';

ridesync_require_driver_login();
$driverId = (int) $_SESSION['driver_id'];
$state = ridesync_fetch_driver_state($conn, $driverId, ['documents' => false]);

$stmt = mysqli_prepare($conn,
    "SELECT DATE(completed_at) AS ride_day, COUNT(*) AS trips, COALESCE(SUM(fare), 0) AS earnings
     FROM driver_ride_history
     WHERE driver_id = ?
     GROUP BY DATE(completed_at)
     ORDER BY ride_day DESC
     LIMIT 14"
);
mysqli_stmt_bind_param($stmt, "i", $driverId);
mysqli_stmt_execute($stmt);
$dailyRows = mysqli_stmt_get_result($stmt);

$stmt = mysqli_prepare($conn,
    "SELECT pickup, drop_location, fare, distance_km, completed_at
     FROM driver_ride_history
     WHERE driver_id = ?
     ORDER BY completed_at DESC
     LIMIT 8"
);
mysqli_stmt_bind_param($stmt, "i", $driverId);
mysqli_stmt_execute($stmt);
$recentTrips = mysqli_stmt_get_result($stmt);

require_once __DIR__ . '/../includes/driver_header.php';
?>

<div class="driver-page-header">
    <div>
        <span class="driver-kicker">Trips</span>
        <h1>Trips & earnings</h1>
    </div>
</div>

<div class="driver-metrics-grid">
    <div class="driver-metric"><span>Today</span><strong><?php echo ridesync_format_money($state['today_earnings']); ?></strong></div>
    <div class="driver-metric"><span>This Week</span><strong><?php echo ridesync_format_money($state['week_earnings']); ?></strong></div>
    <div class="driver-metric"><span>Total</span><strong><?php echo ridesync_format_money($state['total_earnings']); ?></strong></div>
</div>

<section class="driver-panel">
    <h2>Recent breakdown</h2>
    <?php if (mysqli_num_rows($dailyRows) === 0): ?>
        <p class="driver-empty">No completed trips yet.</p>
    <?php else: ?>
        <div class="driver-list">
            <?php while ($row = mysqli_fetch_assoc($dailyRows)): ?>
                <div class="driver-list-item">
                    <div>
                        <strong><?php echo date('M j, Y', strtotime($row['ride_day'])); ?></strong>
                        <span><?php echo (int) $row['trips']; ?> trip<?php echo (int) $row['trips'] === 1 ? '' : 's'; ?></span>
                    </div>
                    <strong><?php echo ridesync_format_money($row['earnings']); ?></strong>
                </div>
            <?php endwhile; ?>
        </div>
    <?php endif; ?>
</section>

<section class="driver-panel">
    <div class="driver-panel-header">
        <div>
            <span class="driver-kicker">Recent Trips</span>
            <h2>Completed rides</h2>
        </div>
        <a href="/ridesync/pages/driver_history.php" class="btn btn-secondary btn-sm">Full history</a>
    </div>
    <?php if (mysqli_num_rows($recentTrips) === 0): ?>
        <p class="driver-empty">No completed trips yet.</p>
    <?php else: ?>
        <div class="driver-list">
            <?php while ($trip = mysqli_fetch_assoc($recentTrips)): ?>
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
