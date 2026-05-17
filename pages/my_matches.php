<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/cost_helper.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: /ridesync/pages/login.php");
    exit();
}

require_once __DIR__ . '/../includes/header.php';

$user_id = (int) $_SESSION['user_id'];

// ---------- Fetch all matches where this user is the requester ----------
$stmt = $conn->prepare("
    SELECT m.id AS match_id, m.status AS match_status, m.match_score, m.route_overlap_percent, m.created_at AS requested_at,
           r.id AS ride_id,
           r.origin, r.destination, r.travel_date, r.travel_time, r.status AS ride_status,
           u.name AS poster_name, u.email AS poster_email, u.college AS poster_college
    FROM matches m
    JOIN rides r ON m.ride_id = r.id
    JOIN users u ON r.user_id = u.id
    WHERE m.matched_user_id = ?
    ORDER BY m.created_at DESC
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$matches = $stmt->get_result();
$stmt->close();

$driverRequests = null;
$driverRequestsTable = mysqli_query($conn, "SHOW TABLES LIKE 'driver_ride_requests'");
if ($driverRequestsTable && mysqli_num_rows($driverRequestsTable) > 0) {
    $stmt = $conn->prepare("
        SELECT rr.*,
               d.name AS driver_name
        FROM driver_ride_requests rr
        JOIN driver_accounts d ON d.id = rr.driver_id
        WHERE rr.rider_user_id = ?
        ORDER BY rr.requested_at DESC
        LIMIT 20
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $driverRequests = $stmt->get_result();
    $stmt->close();
}
?>

<div class="page-header">
    <h1>My Matches</h1>
    <p>Rides you've requested to join.</p>
</div>

<?php ridesync_flash('match_success', 'alert-success'); ?>
<?php ridesync_flash('match_error', 'alert-error'); ?>

<?php if ($matches->num_rows === 0): ?>
    <div class="empty-state">
        <p>You haven't requested to join any rides yet.</p>
        <a href="/ridesync/pages/search_rides.php" class="btn btn-primary">Search Rides</a>
    </div>
<?php else: ?>
    <div class="matches-grid">
        <?php while ($m = $matches->fetch_assoc()): ?>
            <div class="match-card">
                <div class="match-card-top">
                    <h3><?php echo htmlspecialchars($m['origin']); ?> &rarr; <?php echo htmlspecialchars($m['destination']); ?></h3>
                    <span class="status-badge status-<?php echo $m['match_status']; ?>">
                        <?php echo ucfirst($m['match_status']); ?>
                    </span>
                </div>

                <div class="match-card-details">
                    <p>Date <?php echo date('M j, Y', strtotime($m['travel_date'])); ?> &middot; Time <?php echo date('g:i A', strtotime($m['travel_time'])); ?></p>
                    <p>Posted by <strong><?php echo htmlspecialchars($m['poster_name']); ?></strong> (<?php echo htmlspecialchars($m['poster_college']); ?>)</p>

                    <?php if ($m['match_status'] === 'accepted'): ?>
                        <!-- Show poster's email so they can coordinate -->
                        <p class="contact-info">Contact: <a href="mailto:<?php echo htmlspecialchars($m['poster_email']); ?>"><?php echo htmlspecialchars($m['poster_email']); ?></a></p>
                    <?php endif; ?>

                    <p class="requested-date">Requested <?php echo date('M j, g:i A', strtotime($m['requested_at'])); ?></p>
                    <?php if ($m['match_score'] !== null): ?>
                        <p class="requested-date">Smart score: <?php echo number_format((float) $m['match_score'], 1); ?>% &middot; <?php echo (int) $m['route_overlap_percent']; ?>% overlap</p>
                    <?php endif; ?>
                </div>

                <?php if ($m['match_status'] === 'pending'): ?>
                    <div class="match-card-footer">
                        <a href="/ridesync/pages/ride_detail.php?id=<?php echo (int) $m['ride_id']; ?>" class="btn btn-small btn-secondary">View Details</a>
                        <form method="POST" action="/ridesync/actions/match_action.php" data-confirm-message="Cancel this request?">
                            <input type="hidden" name="action" value="cancel">
                            <input type="hidden" name="match_id" value="<?php echo $m['match_id']; ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="return_to" value="/ridesync/pages/my_matches.php">
                            <button type="submit" class="btn btn-small btn-warning">Cancel Request</button>
                        </form>
                    </div>
                <?php else: ?>
                    <div class="match-card-footer">
                        <a href="/ridesync/pages/ride_detail.php?id=<?php echo (int) $m['ride_id']; ?>" class="btn btn-small btn-secondary">View Details</a>
                    </div>
                <?php endif; ?>
            </div>
        <?php endwhile; ?>
    </div>
<?php endif; ?>

<?php if ($driverRequests && $driverRequests->num_rows > 0): ?>
    <div class="page-header" style="margin-top:32px;">
        <h1>Driver Requests</h1>
        <p>Smart driver fallback requests you've sent.</p>
    </div>

    <div class="matches-grid">
        <?php while ($request = $driverRequests->fetch_assoc()): ?>
            <div class="match-card">
                <div class="match-card-top">
                    <h3><?php echo htmlspecialchars($request['pickup']); ?> &rarr; <?php echo htmlspecialchars($request['drop_location']); ?></h3>
                    <span class="status-badge status-<?php echo htmlspecialchars($request['request_status']); ?>">
                        <?php echo ucfirst(htmlspecialchars($request['request_status'])); ?>
                    </span>
                </div>
                <div class="match-card-details">
                    <p>Driver <strong><?php echo htmlspecialchars($request['driver_name']); ?></strong></p>
                    <p>Estimated fare <?php echo formatCost($request['estimated_fare']); ?></p>
                    <?php if (!empty($request['route_distance_km']) && !empty($request['fare_rate_per_km'])): ?>
                        <p class="requested-date">
                            <?php echo number_format((float) $request['route_distance_km'], 1); ?> km &times; <?php echo formatFareRate($request['fare_rate_per_km']); ?>/km
                        </p>
                    <?php endif; ?>
                    <p class="requested-date">Requested <?php echo date('M j, g:i A', strtotime($request['requested_at'])); ?></p>
                </div>
                <?php if ($request['request_status'] === 'pending'): ?>
                    <div class="match-card-footer">
                        <form method="POST" action="/ridesync/actions/driver_request_action.php" data-confirm-message="Cancel this driver request?">
                            <input type="hidden" name="action_type" value="cancel_pending">
                            <input type="hidden" name="request_id" value="<?php echo (int) $request['id']; ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="return_to" value="/ridesync/pages/my_matches.php">
                            <button type="submit" class="btn btn-small btn-warning">Cancel Driver Request</button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        <?php endwhile; ?>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
