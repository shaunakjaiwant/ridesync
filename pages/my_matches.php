<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/cost_helper.php';
require_once __DIR__ . '/../includes/view_helper.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: /ridesync/pages/login.php");
    exit();
}

$user_id = (int) $_SESSION['user_id'];

// ---------- Fetch all carpool matches where this user is the requester ----------
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
$carpoolMatches = [];
if ($matches) {
    while ($row = $matches->fetch_assoc()) {
        $carpoolMatches[] = $row;
    }
}
$stmt->close();

// ---------- Fetch direct driver requests made by this rider ----------
$driverRequests = [];
try {
    if (ridesync_table_exists($conn, 'driver_ride_requests')) {
        $stmt = $conn->prepare("
            SELECT rr.*,
                   d.name AS driver_name,
                   d.phone AS driver_phone
            FROM driver_ride_requests rr
            JOIN driver_accounts d ON d.id = rr.driver_id
            WHERE rr.rider_user_id = ?
            ORDER BY rr.requested_at DESC
            LIMIT 30
        ");
        if ($stmt) {
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($dr = $res->fetch_assoc()) {
                $driverRequests[] = $dr;
            }
            $stmt->close();
        }
    }
} catch (Throwable $exception) {
    $driverRequests = [];
}

$carpoolCount = count($carpoolMatches);
$driverRequestCount = count($driverRequests);
$totalRequested = $carpoolCount + $driverRequestCount;

// Query count of offered rides for the segmented tab counter
$offeredCount = 0;
$stmtRides = $conn->prepare("SELECT COUNT(*) AS total FROM rides WHERE user_id = ?");
if ($stmtRides) {
    $stmtRides->bind_param("i", $user_id);
    $stmtRides->execute();
    $offeredCount = (int) ($stmtRides->get_result()->fetch_assoc()['total'] ?? 0);
    $stmtRides->close();
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>My Trips</h1>
    <p>Manage rides you've offered, joined community trips, and direct driver requests.</p>
</div>

<nav class="segmented-tab-nav" aria-label="Rider trip categories">
    <a href="/ridesync/pages/my_rides.php" class="tab-btn">
        Offered Rides (<?php echo $offeredCount; ?>)
    </a>
    <a href="/ridesync/pages/my_matches.php" class="tab-btn is-active">
        Joined / Requested Rides (<?php echo $totalRequested; ?>)
    </a>
</nav>

<?php ridesync_flash('match_success', 'alert-success'); ?>
<?php ridesync_flash('match_error', 'alert-error'); ?>

<?php if ($totalRequested === 0): ?>
    <div class="empty-state">
        <p>You haven't requested to join any campus rides or on-demand drivers yet.</p>
        <a href="/ridesync/pages/search_rides.php" class="btn btn-primary">Find a Ride</a>
    </div>
<?php else: ?>

    <?php if ($driverRequestCount > 0): ?>
        <div class="matches-section-header" style="margin-top: 1.5rem; margin-bottom: 0.75rem;">
            <h2 style="font-size: 1.25rem; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 0.5rem;">
                <span class="badge badge-info" style="font-size: 0.78rem; padding: 3px 8px; border-radius: 6px;">Direct Driver</span>
                On-Demand Driver Requests (<?php echo $driverRequestCount; ?>)
            </h2>
            <p class="text-muted" style="font-size: 0.88rem; color: #64748b; margin-top: 2px;">
                Direct requests sent to online verified campus drivers.
            </p>
        </div>

        <div class="matches-grid" style="margin-bottom: 2rem;">
            <?php foreach ($driverRequests as $dr): ?>
                <?php
                $drStatus = $dr['request_status'] ?? 'pending';
                $statusClass = 'status-' . $drStatus;
                if ($drStatus === 'accepted') {
                    $statusLabel = 'Driver En Route';
                } elseif ($drStatus === 'pending') {
                    $statusLabel = 'Awaiting Driver';
                } elseif ($drStatus === 'completed') {
                    $statusLabel = 'Completed';
                } else {
                    $statusLabel = ucfirst($drStatus);
                }
                ?>
                <div class="match-card">
                    <div class="match-card-top">
                        <h3><?php echo ridesync_render_short_address($dr['pickup'], 32); ?> <span class="route-arrow">&rarr;</span> <?php echo ridesync_render_short_address($dr['drop_location'], 32); ?></h3>
                        <span class="status-badge <?php echo $statusClass; ?>">
                            <?php echo htmlspecialchars($statusLabel); ?>
                        </span>
                    </div>

                    <div class="match-card-details">
                        <p>Driver: <strong><?php echo htmlspecialchars($dr['driver_name']); ?></strong><?php if ($drStatus === 'accepted' && !empty($dr['driver_phone'])): ?> &middot; Phone: <a href="tel:<?php echo htmlspecialchars($dr['driver_phone']); ?>"><?php echo htmlspecialchars($dr['driver_phone']); ?></a><?php endif; ?></p>
                        <p>Estimated Fare: <strong><?php echo formatCost($dr['estimated_fare']); ?></strong><?php if (!empty($dr['route_distance_km'])): ?> &middot; <?php echo number_format((float) $dr['route_distance_km'], 1); ?> km<?php endif; ?></p>
                        <p class="requested-date">Requested <?php echo date('M j, g:i A', strtotime($dr['requested_at'])); ?></p>
                        <?php if (!empty($dr['responded_at']) && $drStatus !== 'pending'): ?>
                            <p class="requested-date">Responded <?php echo date('M j, g:i A', strtotime($dr['responded_at'])); ?></p>
                        <?php endif; ?>
                    </div>

                    <div class="match-card-footer">
                        <?php if ($drStatus === 'pending'): ?>
                            <form method="POST" action="/ridesync/actions/driver_request_action.php" data-confirm-message="Cancel this driver request?">
                                <input type="hidden" name="action_type" value="cancel_pending">
                                <input type="hidden" name="request_id" value="<?php echo (int) $dr['id']; ?>">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <input type="hidden" name="return_to" value="/ridesync/pages/my_matches.php">
                                <button type="submit" class="btn btn-small btn-warning">Cancel Request</button>
                            </form>
                        <?php elseif ($drStatus === 'accepted'): ?>
                            <span class="badge badge-success" style="padding: 5px 10px; font-weight: 600;">Driver Accepted &middot; Live</span>
                        <?php else: ?>
                            <span class="text-muted" style="font-size: 0.85rem;"><?php echo htmlspecialchars(ucfirst($drStatus)); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($carpoolCount > 0): ?>
        <?php if ($driverRequestCount > 0): ?>
            <div class="matches-section-header" style="margin-top: 1.5rem; margin-bottom: 0.75rem;">
                <h2 style="font-size: 1.25rem; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 0.5rem;">
                    <span class="badge badge-primary" style="font-size: 0.78rem; padding: 3px 8px; border-radius: 6px;">Carpool</span>
                    Community Ride Join Requests (<?php echo $carpoolCount; ?>)
                </h2>
                <p class="text-muted" style="font-size: 0.88rem; color: #64748b; margin-top: 2px;">
                    Requests to join carpool trips posted by students and campus members.
                </p>
            </div>
        <?php endif; ?>

        <div class="matches-grid">
            <?php foreach ($carpoolMatches as $m): ?>
                <div class="match-card">
                    <div class="match-card-top">
                        <h3><?php echo ridesync_render_short_address($m['origin'], 32); ?> <span class="route-arrow">&rarr;</span> <?php echo ridesync_render_short_address($m['destination'], 32); ?></h3>
                        <span class="status-badge status-<?php echo $m['match_status']; ?>">
                            <?php echo ucfirst($m['match_status']); ?>
                        </span>
                    </div>

                    <div class="match-card-details">
                        <p>Date <?php echo date('M j, Y', strtotime($m['travel_date'])); ?> &middot; Time <?php echo date('g:i A', strtotime($m['travel_time'])); ?></p>
                        <p>Posted by <strong><?php echo htmlspecialchars($m['poster_name']); ?></strong> (<?php echo htmlspecialchars($m['poster_college']); ?>)</p>

                        <?php if ($m['match_status'] === 'accepted'): ?>
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
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
