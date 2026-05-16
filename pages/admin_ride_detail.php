<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/admin_helper.php';
require_once __DIR__ . '/../includes/cost_helper.php';

ridesync_require_admin_login();

if (!ridesync_admin_schema_ready($conn)) {
    unset($_SESSION['admin_id'], $_SESSION['admin_name'], $_SESSION['admin_role']);
    $_SESSION['admin_error'] = "Admin database tables are missing.";
    header("Location: /ridesync/pages/admin_login.php");
    exit();
}

$admin = ridesync_fetch_admin($conn, (int) $_SESSION['admin_id']);
if (!$admin || $admin['status'] !== 'active') {
    unset($_SESSION['admin_id'], $_SESSION['admin_name'], $_SESSION['admin_role']);
    $_SESSION['admin_error'] = "This admin account cannot access ride details right now.";
    header("Location: /ridesync/pages/admin_login.php");
    exit();
}
ridesync_admin_sync_session($admin);

function ridesync_admin_ride_rows($result) {
    $rows = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $rows[] = $row;
        }
    }
    return $rows;
}

function ridesync_admin_ride_prepared_rows($conn, $sql, $types, $params) {
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return [];
    }

    $refs = [];
    foreach ($params as $key => $value) {
        $refs[$key] = &$params[$key];
    }
    mysqli_stmt_bind_param($stmt, $types, ...$refs);
    mysqli_stmt_execute($stmt);

    return ridesync_admin_ride_rows(mysqli_stmt_get_result($stmt));
}

$rideId = (int) ($_GET['id'] ?? 0);
if ($rideId <= 0) {
    $_SESSION['admin_error'] = "Invalid ride id.";
    header("Location: /ridesync/pages/admin_dashboard.php?section=rides");
    exit();
}

$stmt = mysqli_prepare(
    $conn,
    "SELECT
        r.*,
        owner.name AS owner_name,
        owner.email AS owner_email,
        owner.college AS owner_college,
        COALESCE(ls.live_status, 'searching') AS live_status,
        ls.eta_minutes,
        ls.note AS live_note,
        ls.updated_at AS live_updated_at,
        d.id AS driver_id,
        d.name AS driver_name,
        d.email AS driver_email,
        d.phone AS driver_phone,
        a.status AS driver_availability
     FROM rides r
     JOIN users owner ON owner.id = r.user_id
     LEFT JOIN ride_live_status ls ON ls.ride_id = r.id
     LEFT JOIN driver_accounts d ON d.id = ls.driver_id
     LEFT JOIN driver_account_availability a ON a.driver_id = d.id
     WHERE r.id = ?
     LIMIT 1"
);
mysqli_stmt_bind_param($stmt, "i", $rideId);
mysqli_stmt_execute($stmt);
$ride = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if (!$ride) {
    $_SESSION['admin_error'] = "Ride not found.";
    header("Location: /ridesync/pages/admin_dashboard.php?section=rides");
    exit();
}

$matches = ridesync_admin_ride_prepared_rows(
    $conn,
    "SELECT m.*, u.name AS requester_name, u.email AS requester_email, u.college AS requester_college
     FROM matches m
     JOIN users u ON u.id = m.matched_user_id
     WHERE m.ride_id = ?
     ORDER BY FIELD(m.status, 'pending', 'accepted', 'rejected'), m.created_at DESC",
    "i",
    [$rideId]
);

$reports = ridesync_admin_ride_prepared_rows(
    $conn,
    "SELECT rep.*, reporter.name AS reporter_name, reported.name AS reported_name
     FROM reports rep
     JOIN users reporter ON reporter.id = rep.reporter_user_id
     LEFT JOIN users reported ON reported.id = rep.reported_user_id
     WHERE rep.ride_id = ?
     ORDER BY FIELD(rep.report_status, 'open', 'reviewing', 'resolved', 'dismissed'), rep.created_at DESC",
    "i",
    [$rideId]
);

$tracking = ridesync_admin_ride_prepared_rows(
    $conn,
    "SELECT rt.*, d.name AS driver_name
     FROM ride_tracking rt
     LEFT JOIN driver_accounts d ON d.id = rt.driver_id
     WHERE rt.ride_id = ?
     ORDER BY rt.recorded_at DESC
     LIMIT 8",
    "i",
    [$rideId]
);

$acceptedCount = 0;
$pendingCount = 0;
foreach ($matches as $match) {
    if ($match['status'] === 'accepted') {
        $acceptedCount++;
    } elseif ($match['status'] === 'pending') {
        $pendingCount++;
    }
}

$fareEstimate = ridesync_estimate_total_ride_fare((float) ($ride['route_distance_km'] ?? 0));
$fareParticipants = max(1, $acceptedCount + 1);
$farePerPassenger = $fareEstimate > 0 ? $fareEstimate / $fareParticipants : 0;

require_once __DIR__ . '/../includes/admin_header.php';
?>

<div class="admin-command-center">
    <section class="admin-hero-panel">
        <div>
            <span class="driver-kicker">Ride Control Link</span>
            <h1>#<?php echo (int) $ride['id']; ?> <?php echo htmlspecialchars($ride['origin']); ?> &rarr; <?php echo htmlspecialchars($ride['destination']); ?></h1>
            <p><?php echo htmlspecialchars(date('l, M j, Y', strtotime($ride['travel_date'])) . ' at ' . date('g:i A', strtotime($ride['travel_time']))); ?></p>
        </div>
        <div class="admin-hero-actions">
            <a class="btn btn-secondary" href="/ridesync/pages/admin_dashboard.php?section=rides">Back to Rides</a>
            <a class="btn btn-primary" href="/ridesync/pages/admin_user_detail.php?user_id=<?php echo (int) $ride['user_id']; ?>">Open Owner</a>
            <?php if (!empty($ride['driver_id'])): ?>
                <a class="btn btn-secondary" href="/ridesync/pages/admin_driver_verification.php?driver_id=<?php echo (int) $ride['driver_id']; ?>">Open Driver</a>
            <?php endif; ?>
        </div>
    </section>

    <section class="admin-priority-grid">
        <article class="admin-op-card is-primary"><span>Live Status</span><strong><?php echo htmlspecialchars(ridesync_admin_status_label($ride['live_status'])); ?></strong><small><?php echo htmlspecialchars($ride['status']); ?> ride record</small></article>
        <article class="admin-op-card"><span>Seats</span><strong><?php echo (int) $ride['seats_available']; ?></strong><small><?php echo $acceptedCount; ?> accepted riders</small></article>
        <article class="admin-op-card is-warning"><span>Pending Requests</span><strong><?php echo $pendingCount; ?></strong><small><?php echo count($matches); ?> total join requests</small></article>
        <article class="admin-op-card"><span>Distance</span><strong><?php echo $ride['route_distance_km'] !== null ? number_format((float) $ride['route_distance_km'], 1) : '0'; ?></strong><small>km mapped or estimated</small></article>
        <article class="admin-op-card"><span>Fare Split</span><strong>Rs <?php echo number_format((float) $farePerPassenger, 0); ?></strong><small>of Rs <?php echo number_format((float) $fareEstimate, 0); ?> total</small></article>
    </section>

    <section class="admin-command-grid is-lower">
        <article class="admin-command-card">
            <div class="admin-card-head"><div><span class="driver-kicker">Rider Panel</span><h2>Ride Owner</h2></div></div>
            <div class="admin-health-stack">
                <div><span>Name</span><strong><?php echo htmlspecialchars($ride['owner_name']); ?></strong></div>
                <div><span>Email</span><strong><?php echo htmlspecialchars($ride['owner_email']); ?></strong></div>
                <div><span>College</span><strong><?php echo htmlspecialchars($ride['owner_college']); ?></strong></div>
                <div><span>Route</span><strong><?php echo htmlspecialchars($ride['origin'] . ' -> ' . $ride['destination']); ?></strong></div>
            </div>
        </article>

        <article class="admin-command-card">
            <div class="admin-card-head"><div><span class="driver-kicker">Driver Panel</span><h2>Assigned Driver</h2></div></div>
            <div class="admin-health-stack">
                <div><span>Name</span><strong><?php echo htmlspecialchars($ride['driver_name'] ?: 'Not assigned'); ?></strong></div>
                <div><span>Email</span><strong><?php echo htmlspecialchars($ride['driver_email'] ?: 'No driver email'); ?></strong></div>
                <div><span>Availability</span><strong><?php echo htmlspecialchars(ridesync_admin_status_label($ride['driver_availability'] ?: 'offline')); ?></strong></div>
                <div><span>ETA / Note</span><strong><?php echo htmlspecialchars(($ride['eta_minutes'] ? $ride['eta_minutes'] . ' min' : 'No ETA') . ' - ' . ($ride['live_note'] ?: 'No note')); ?></strong></div>
            </div>
        </article>
    </section>

    <section class="admin-command-card admin-table-card">
        <div class="admin-card-head"><div><span class="driver-kicker">Community Pool</span><h2>Join Requests</h2></div></div>
        <div class="admin-table-wrap">
            <table class="admin-smart-table">
                <thead><tr><th>Requester</th><th>Status</th><th>Score</th><th>Pickup</th><th>Drop</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php foreach ($matches as $match): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($match['requester_name']); ?></strong><span><?php echo htmlspecialchars($match['requester_email']); ?></span></td>
                            <td><span class="badge badge-<?php echo htmlspecialchars(ridesync_admin_badge_class($match['status'])); ?>"><?php echo htmlspecialchars(ridesync_admin_status_label($match['status'])); ?></span></td>
                            <td><?php echo $match['match_score'] !== null ? number_format((float) $match['match_score'], 0) . '%' : 'Manual'; ?></td>
                            <td><?php echo $match['pickup_distance_km'] !== null ? number_format((float) $match['pickup_distance_km'], 1) . ' km' : 'Not mapped'; ?></td>
                            <td><?php echo $match['drop_distance_km'] !== null ? number_format((float) $match['drop_distance_km'], 1) . ' km' : 'Not mapped'; ?></td>
                            <td><a class="btn btn-secondary btn-sm" href="/ridesync/pages/admin_user_detail.php?user_id=<?php echo (int) $match['matched_user_id']; ?>">Open User</a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (count($matches) === 0): ?>
                        <tr><td colspan="6">No join requests for this ride.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="admin-command-grid is-lower">
        <article class="admin-command-card admin-table-card">
            <div class="admin-card-head"><div><span class="driver-kicker">Moderation</span><h2>Ride Reports</h2></div></div>
            <div class="admin-table-wrap">
                <table class="admin-smart-table">
                    <thead><tr><th>Report</th><th>Reporter</th><th>Reported</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php foreach ($reports as $report): ?>
                            <tr>
                                <td><strong>#<?php echo (int) $report['id']; ?> <?php echo htmlspecialchars(ridesync_admin_status_label($report['reason'])); ?></strong><span><?php echo htmlspecialchars(date('M j, g:i A', strtotime($report['created_at']))); ?></span></td>
                                <td><?php echo htmlspecialchars($report['reporter_name']); ?></td>
                                <td><?php echo htmlspecialchars($report['reported_name'] ?: 'Not linked'); ?></td>
                                <td><span class="badge badge-<?php echo htmlspecialchars(ridesync_admin_badge_class($report['report_status'])); ?>"><?php echo htmlspecialchars(ridesync_admin_status_label($report['report_status'])); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (count($reports) === 0): ?>
                            <tr><td colspan="4">No reports for this ride.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </article>

        <article class="admin-command-card admin-table-card">
            <div class="admin-card-head"><div><span class="driver-kicker">Tracking</span><h2>Latest Driver Points</h2></div></div>
            <div class="admin-table-wrap">
                <table class="admin-smart-table">
                    <thead><tr><th>Time</th><th>Driver</th><th>Location</th><th>ETA</th></tr></thead>
                    <tbody>
                        <?php foreach ($tracking as $point): ?>
                            <tr>
                                <td><?php echo htmlspecialchars(date('M j, g:i A', strtotime($point['recorded_at']))); ?></td>
                                <td><?php echo htmlspecialchars($point['driver_name'] ?: 'Unknown driver'); ?></td>
                                <td><?php echo htmlspecialchars($point['lat'] . ', ' . $point['lng']); ?></td>
                                <td><?php echo $point['eta_minutes'] !== null ? (int) $point['eta_minutes'] . ' min' : 'No ETA'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (count($tracking) === 0): ?>
                            <tr><td colspan="4">No tracking points recorded for this ride.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </article>
    </section>
</div>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
