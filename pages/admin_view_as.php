<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/admin_helper.php';
require_once __DIR__ . '/../includes/admin_operations_helper.php';

ridesync_require_admin_login();

if (!ridesync_admin_schema_ready($conn)) {
    unset($_SESSION['admin_id'], $_SESSION['admin_name'], $_SESSION['admin_role']);
    $_SESSION['admin_error'] = "Admin database tables are missing.";
    header("Location: /ridesync/pages/admin_login.php");
    exit();
}

$admin = ridesync_fetch_admin($conn, (int) $_SESSION['admin_id']);
if (!$admin || ($admin['status'] ?? '') !== 'active') {
    unset($_SESSION['admin_id'], $_SESSION['admin_name'], $_SESSION['admin_role']);
    $_SESSION['admin_error'] = "This admin account cannot access read-only panel inspection right now.";
    header("Location: /ridesync/pages/admin_login.php");
    exit();
}
ridesync_admin_sync_session($admin);

function ridesync_admin_view_as_rows($result) {
    $rows = [];
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $rows[] = $row;
    }
    return $rows;
}

function ridesync_admin_view_as_prepared_rows($conn, $sql, $types, $params) {
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

    return ridesync_admin_view_as_rows(mysqli_stmt_get_result($stmt));
}

function ridesync_admin_view_as_first($conn, $sql, $types, $params) {
    $rows = ridesync_admin_view_as_prepared_rows($conn, $sql, $types, $params);
    return $rows[0] ?? null;
}

$type = strtolower(trim((string) ($_GET['type'] ?? '')));
$id = (int) ($_GET['id'] ?? 0);
if (!in_array($type, ['user', 'driver'], true) || $id <= 0) {
    $_SESSION['admin_error'] = "Invalid read-only inspection target.";
    header("Location: /ridesync/pages/admin_dashboard.php?section=overview");
    exit();
}

$user = null;
$driver = null;
$postedRides = [];
$joinRequests = [];
$incomingRequests = [];
$notifications = [];
$driverRequests = [];
$driverDocuments = [];
$driverLiveRides = [];
$driverHistory = [];

if ($type === 'user') {
    $user = ridesync_admin_view_as_first(
        $conn,
        "SELECT u.*, COALESCE(uv.status, 'unverified') AS verification_status,
                uv.verification_type, uv.reference AS verification_reference,
                d.id AS linked_driver_id, d.status AS linked_driver_status
         FROM users u
         LEFT JOIN (
            SELECT uv1.*
            FROM user_verifications uv1
            JOIN (
                SELECT user_id, MAX(id) AS latest_id
                FROM user_verifications
                GROUP BY user_id
            ) latest ON latest.latest_id = uv1.id
         ) uv ON uv.user_id = u.id
         LEFT JOIN driver_accounts d
            ON CONVERT(d.email USING utf8mb4) COLLATE utf8mb4_unicode_ci
             = CONVERT(u.email USING utf8mb4) COLLATE utf8mb4_unicode_ci
         WHERE u.id = ?
         LIMIT 1",
        'i',
        [$id]
    );

    if (!$user) {
        $_SESSION['admin_error'] = "User not found.";
        header("Location: /ridesync/pages/admin_dashboard.php?section=users");
        exit();
    }

    $postedRides = ridesync_admin_view_as_prepared_rows(
        $conn,
        "SELECT r.*, COALESCE(ls.live_status, r.status) AS live_status, d.name AS driver_name
         FROM rides r
         LEFT JOIN ride_live_status ls ON ls.ride_id = r.id
         LEFT JOIN driver_accounts d ON d.id = ls.driver_id
         WHERE r.user_id = ?
         ORDER BY r.created_at DESC
         LIMIT 10",
        'i',
        [$id]
    );
    $joinRequests = ridesync_admin_view_as_prepared_rows(
        $conn,
        "SELECT m.*, r.origin, r.destination, r.travel_date, r.travel_time, owner.name AS owner_name
         FROM matches m
         JOIN rides r ON r.id = m.ride_id
         JOIN users owner ON owner.id = r.user_id
         WHERE m.matched_user_id = ?
         ORDER BY m.created_at DESC
         LIMIT 10",
        'i',
        [$id]
    );
    $incomingRequests = ridesync_admin_view_as_prepared_rows(
        $conn,
        "SELECT m.*, r.origin, r.destination, requester.name AS requester_name
         FROM matches m
         JOIN rides r ON r.id = m.ride_id
         JOIN users requester ON requester.id = m.matched_user_id
         WHERE r.user_id = ?
         ORDER BY FIELD(m.status, 'pending', 'accepted', 'rejected'), m.created_at DESC
         LIMIT 10",
        'i',
        [$id]
    );
    $notifications = ridesync_admin_view_as_prepared_rows(
        $conn,
        "SELECT title, message, is_read, created_at
         FROM notifications
         WHERE user_id = ?
         ORDER BY created_at DESC
         LIMIT 8",
        'i',
        [$id]
    );
} else {
    $driver = ridesync_admin_view_as_first(
        $conn,
        "SELECT d.*, p.verification_status AS profile_status, p.license_number,
                v.vehicle_type, v.vehicle_number, v.seating_capacity,
                COALESCE(a.status, 'offline') AS availability_status,
                a.last_changed_at
         FROM driver_accounts d
         LEFT JOIN driver_account_profiles p ON p.driver_id = d.id
         LEFT JOIN driver_account_vehicles v ON v.driver_id = d.id
         LEFT JOIN driver_account_availability a ON a.driver_id = d.id
         WHERE d.id = ?
         LIMIT 1",
        'i',
        [$id]
    );

    if (!$driver) {
        $_SESSION['admin_error'] = "Driver not found.";
        header("Location: /ridesync/pages/admin_dashboard.php?section=drivers");
        exit();
    }

    $driverRequests = ridesync_admin_view_as_prepared_rows(
        $conn,
        "SELECT rr.*, u.name AS rider_name, u.email AS rider_email
         FROM driver_ride_requests rr
         LEFT JOIN users u ON u.id = rr.rider_user_id
         WHERE rr.driver_id = ?
         ORDER BY rr.requested_at DESC
         LIMIT 10",
        'i',
        [$id]
    );
    $driverDocuments = ridesync_admin_view_as_prepared_rows(
        $conn,
        "SELECT id, document_type, document_reference, verification_status, created_at
         FROM driver_account_documents
         WHERE driver_id = ?
         ORDER BY created_at DESC
         LIMIT 10",
        'i',
        [$id]
    );
    $driverLiveRides = ridesync_admin_view_as_prepared_rows(
        $conn,
        "SELECT ls.*, r.origin, r.destination, r.travel_date, r.travel_time, u.name AS owner_name
         FROM ride_live_status ls
         JOIN rides r ON r.id = ls.ride_id
         JOIN users u ON u.id = r.user_id
         WHERE ls.driver_id = ?
         ORDER BY ls.updated_at DESC
         LIMIT 10",
        'i',
        [$id]
    );
    $driverHistory = ridesync_admin_view_as_prepared_rows(
        $conn,
        "SELECT *
         FROM driver_ride_history
         WHERE driver_id = ?
         ORDER BY completed_at DESC
         LIMIT 10",
        'i',
        [$id]
    );
    $notifications = ridesync_admin_view_as_prepared_rows(
        $conn,
        "SELECT title, message, is_read, created_at
         FROM notifications
         WHERE driver_id = ?
         ORDER BY created_at DESC
         LIMIT 8",
        'i',
        [$id]
    );
}

require_once __DIR__ . '/../includes/admin_header.php';
?>

<div class="admin-command-center">
    <section class="admin-hero-panel">
        <div>
            <span class="driver-kicker">Read-Only Panel Inspector</span>
            <h1><?php echo htmlspecialchars($type === 'user' ? ($user['name'] ?? 'User') : ($driver['name'] ?? 'Driver')); ?></h1>
            <p>No session takeover, no account mutation, no impersonation cookies. This is an admin-side snapshot.</p>
        </div>
        <div class="admin-hero-actions">
            <?php if ($type === 'user'): ?>
                <a class="btn btn-secondary" href="/ridesync/pages/admin_user_detail.php?user_id=<?php echo (int) $id; ?>">Back to User</a>
            <?php else: ?>
                <a class="btn btn-secondary" href="/ridesync/pages/admin_driver_verification.php?driver_id=<?php echo (int) $id; ?>">Back to Driver</a>
            <?php endif; ?>
            <a class="btn btn-primary" href="/ridesync/pages/admin_dashboard.php?section=<?php echo $type === 'user' ? 'users' : 'drivers'; ?>">Open Queue</a>
        </div>
    </section>

    <?php if ($type === 'user'): ?>
        <section class="admin-priority-grid">
            <article class="admin-op-card is-primary"><span>Account</span><strong><?php echo htmlspecialchars(ridesync_admin_status_label($user['verification_status'] ?? 'unverified')); ?></strong><small><?php echo htmlspecialchars($user['email'] ?? ''); ?></small></article>
            <article class="admin-op-card"><span>Posted Rides</span><strong><?php echo count($postedRides); ?></strong><small>latest visible records</small></article>
            <article class="admin-op-card"><span>Join Requests</span><strong><?php echo count($joinRequests); ?></strong><small>latest outbound requests</small></article>
            <article class="admin-op-card is-warning"><span>Incoming</span><strong><?php echo count($incomingRequests); ?></strong><small>requests on owned rides</small></article>
            <article class="admin-op-card"><span>Notifications</span><strong><?php echo count($notifications); ?></strong><small>latest alerts</small></article>
        </section>

        <section class="admin-command-grid is-lower">
            <article class="admin-command-card admin-table-card">
                <div class="admin-card-head"><div><span class="driver-kicker">User Panel</span><h2>Posted Rides</h2></div></div>
                <div class="admin-table-wrap">
                    <table class="admin-smart-table"><thead><tr><th>Ride</th><th>Status</th><th>Seats</th><th>Driver</th></tr></thead><tbody>
                        <?php foreach ($postedRides as $ride): ?>
                            <tr><td><strong>#<?php echo (int) $ride['id']; ?> <?php echo htmlspecialchars($ride['origin']); ?> -> <?php echo htmlspecialchars($ride['destination']); ?></strong><span><?php echo htmlspecialchars($ride['travel_date'] . ' ' . $ride['travel_time']); ?></span></td><td><span class="badge badge-<?php echo htmlspecialchars(ridesync_admin_badge_class($ride['live_status'])); ?>"><?php echo htmlspecialchars(ridesync_admin_status_label($ride['live_status'])); ?></span></td><td><?php echo (int) $ride['seats_available']; ?></td><td><?php echo htmlspecialchars($ride['driver_name'] ?: 'Not assigned'); ?></td></tr>
                        <?php endforeach; ?>
                        <?php if (count($postedRides) === 0): ?><tr><td colspan="4">No posted rides visible.</td></tr><?php endif; ?>
                    </tbody></table>
                </div>
            </article>
            <article class="admin-command-card">
                <div class="admin-card-head"><div><span class="driver-kicker">User Panel</span><h2>Notifications</h2></div></div>
                <div class="admin-feed-list">
                    <?php foreach ($notifications as $notification): ?>
                        <div class="admin-feed-item"><span class="admin-feed-dot badge-<?php echo (int) $notification['is_read'] === 1 ? 'accepted' : 'pending'; ?>"></span><div><strong><?php echo htmlspecialchars($notification['title']); ?></strong><p><?php echo htmlspecialchars($notification['message']); ?></p><small><?php echo htmlspecialchars(date('M j, g:i A', strtotime((string) $notification['created_at']))); ?></small></div></div>
                    <?php endforeach; ?>
                    <?php if (count($notifications) === 0): ?><div class="driver-empty-card">No notifications visible.</div><?php endif; ?>
                </div>
            </article>
        </section>

        <section class="admin-command-grid is-lower">
            <article class="admin-command-card admin-table-card">
                <div class="admin-card-head"><div><span class="driver-kicker">User Panel</span><h2>Join Requests Sent</h2></div></div>
                <div class="admin-table-wrap">
                    <table class="admin-smart-table"><thead><tr><th>Ride</th><th>Owner</th><th>Status</th></tr></thead><tbody>
                        <?php foreach ($joinRequests as $request): ?>
                            <tr><td><strong>#<?php echo (int) $request['ride_id']; ?> <?php echo htmlspecialchars($request['origin']); ?> -> <?php echo htmlspecialchars($request['destination']); ?></strong></td><td><?php echo htmlspecialchars($request['owner_name']); ?></td><td><span class="badge badge-<?php echo htmlspecialchars(ridesync_admin_badge_class($request['status'])); ?>"><?php echo htmlspecialchars(ridesync_admin_status_label($request['status'])); ?></span></td></tr>
                        <?php endforeach; ?>
                        <?php if (count($joinRequests) === 0): ?><tr><td colspan="3">No join requests sent.</td></tr><?php endif; ?>
                    </tbody></table>
                </div>
            </article>
            <article class="admin-command-card admin-table-card">
                <div class="admin-card-head"><div><span class="driver-kicker">User Panel</span><h2>Incoming Requests</h2></div></div>
                <div class="admin-table-wrap">
                    <table class="admin-smart-table"><thead><tr><th>Ride</th><th>Requester</th><th>Status</th></tr></thead><tbody>
                        <?php foreach ($incomingRequests as $request): ?>
                            <tr><td><strong>#<?php echo (int) $request['ride_id']; ?> <?php echo htmlspecialchars($request['origin']); ?> -> <?php echo htmlspecialchars($request['destination']); ?></strong></td><td><?php echo htmlspecialchars($request['requester_name']); ?></td><td><span class="badge badge-<?php echo htmlspecialchars(ridesync_admin_badge_class($request['status'])); ?>"><?php echo htmlspecialchars(ridesync_admin_status_label($request['status'])); ?></span></td></tr>
                        <?php endforeach; ?>
                        <?php if (count($incomingRequests) === 0): ?><tr><td colspan="3">No incoming requests.</td></tr><?php endif; ?>
                    </tbody></table>
                </div>
            </article>
        </section>
    <?php else: ?>
        <section class="admin-priority-grid">
            <article class="admin-op-card is-primary"><span>Account</span><strong><?php echo htmlspecialchars(ridesync_admin_status_label($driver['status'] ?? 'inactive')); ?></strong><small><?php echo htmlspecialchars($driver['email'] ?? ''); ?></small></article>
            <article class="admin-op-card"><span>Availability</span><strong><?php echo htmlspecialchars(ridesync_admin_status_label($driver['availability_status'] ?? 'offline')); ?></strong><small>driver panel state</small></article>
            <article class="admin-op-card"><span>Verification</span><strong><?php echo htmlspecialchars(ridesync_admin_status_label($driver['profile_status'] ?? 'pending')); ?></strong><small><?php echo htmlspecialchars($driver['license_number'] ?: 'No license'); ?></small></article>
            <article class="admin-op-card is-warning"><span>Requests</span><strong><?php echo count($driverRequests); ?></strong><small>latest direct requests</small></article>
            <article class="admin-op-card"><span>Documents</span><strong><?php echo count($driverDocuments); ?></strong><small>submitted references</small></article>
        </section>

        <section class="admin-command-grid is-lower">
            <article class="admin-command-card">
                <div class="admin-card-head"><div><span class="driver-kicker">Driver Panel</span><h2>Vehicle and Status</h2></div></div>
                <div class="admin-health-stack">
                    <div><span>Vehicle</span><strong><?php echo htmlspecialchars(($driver['vehicle_type'] ?: 'Vehicle') . ' / ' . ($driver['vehicle_number'] ?: 'No number')); ?></strong></div>
                    <div><span>Seats</span><strong><?php echo (int) ($driver['seating_capacity'] ?? 0); ?></strong></div>
                    <div><span>Onboarding</span><strong><?php echo htmlspecialchars(ridesync_admin_status_label($driver['onboarding_status'] ?? 'unknown')); ?></strong></div>
                    <div><span>Last Availability Change</span><strong><?php echo htmlspecialchars($driver['last_changed_at'] ?: 'No activity'); ?></strong></div>
                </div>
            </article>
            <article class="admin-command-card">
                <div class="admin-card-head"><div><span class="driver-kicker">Driver Panel</span><h2>Notifications</h2></div></div>
                <div class="admin-feed-list">
                    <?php foreach ($notifications as $notification): ?>
                        <div class="admin-feed-item"><span class="admin-feed-dot badge-<?php echo (int) $notification['is_read'] === 1 ? 'accepted' : 'pending'; ?>"></span><div><strong><?php echo htmlspecialchars($notification['title']); ?></strong><p><?php echo htmlspecialchars($notification['message']); ?></p><small><?php echo htmlspecialchars(date('M j, g:i A', strtotime((string) $notification['created_at']))); ?></small></div></div>
                    <?php endforeach; ?>
                    <?php if (count($notifications) === 0): ?><div class="driver-empty-card">No driver notifications visible.</div><?php endif; ?>
                </div>
            </article>
        </section>

        <section class="admin-command-grid is-lower">
            <article class="admin-command-card admin-table-card">
                <div class="admin-card-head"><div><span class="driver-kicker">Driver Panel</span><h2>Direct Requests</h2></div></div>
                <div class="admin-table-wrap">
                    <table class="admin-smart-table"><thead><tr><th>Route</th><th>Rider</th><th>Status</th><th>Fare</th></tr></thead><tbody>
                        <?php foreach ($driverRequests as $request): ?>
                            <tr><td><strong><?php echo htmlspecialchars($request['pickup']); ?> -> <?php echo htmlspecialchars($request['drop_location']); ?></strong><span><?php echo htmlspecialchars($request['requested_at']); ?></span></td><td><?php echo htmlspecialchars($request['rider_name'] ?: 'Guest'); ?></td><td><span class="badge badge-<?php echo htmlspecialchars(ridesync_admin_badge_class($request['request_status'])); ?>"><?php echo htmlspecialchars(ridesync_admin_status_label($request['request_status'])); ?></span></td><td>Rs <?php echo number_format((float) ($request['estimated_fare'] ?? 0), 0); ?></td></tr>
                        <?php endforeach; ?>
                        <?php if (count($driverRequests) === 0): ?><tr><td colspan="4">No direct requests visible.</td></tr><?php endif; ?>
                    </tbody></table>
                </div>
            </article>
            <article class="admin-command-card admin-table-card">
                <div class="admin-card-head"><div><span class="driver-kicker">Driver Panel</span><h2>Documents</h2></div></div>
                <div class="admin-table-wrap">
                    <table class="admin-smart-table"><thead><tr><th>Document</th><th>Status</th><th>Reference</th></tr></thead><tbody>
                        <?php foreach ($driverDocuments as $document): ?>
                            <tr><td><strong><?php echo htmlspecialchars(ridesync_admin_status_label($document['document_type'])); ?></strong><span><?php echo htmlspecialchars(date('M j, g:i A', strtotime((string) $document['created_at']))); ?></span></td><td><span class="badge badge-<?php echo htmlspecialchars(ridesync_admin_badge_class($document['verification_status'])); ?>"><?php echo htmlspecialchars(ridesync_admin_status_label($document['verification_status'])); ?></span></td><td><?php echo htmlspecialchars($document['document_reference'] ?: 'No reference'); ?></td></tr>
                        <?php endforeach; ?>
                        <?php if (count($driverDocuments) === 0): ?><tr><td colspan="3">No documents visible.</td></tr><?php endif; ?>
                    </tbody></table>
                </div>
            </article>
        </section>

        <section class="admin-command-grid is-lower">
            <article class="admin-command-card admin-table-card">
                <div class="admin-card-head"><div><span class="driver-kicker">Driver Panel</span><h2>Live Assignments</h2></div></div>
                <div class="admin-table-wrap">
                    <table class="admin-smart-table"><thead><tr><th>Ride</th><th>Owner</th><th>Status</th><th>ETA</th></tr></thead><tbody>
                        <?php foreach ($driverLiveRides as $ride): ?>
                            <tr><td><strong>#<?php echo (int) $ride['ride_id']; ?> <?php echo htmlspecialchars($ride['origin']); ?> -> <?php echo htmlspecialchars($ride['destination']); ?></strong></td><td><?php echo htmlspecialchars($ride['owner_name']); ?></td><td><span class="badge badge-<?php echo htmlspecialchars(ridesync_admin_badge_class($ride['live_status'])); ?>"><?php echo htmlspecialchars(ridesync_admin_status_label($ride['live_status'])); ?></span></td><td><?php echo $ride['eta_minutes'] !== null ? (int) $ride['eta_minutes'] . ' min' : 'No ETA'; ?></td></tr>
                        <?php endforeach; ?>
                        <?php if (count($driverLiveRides) === 0): ?><tr><td colspan="4">No live assignments visible.</td></tr><?php endif; ?>
                    </tbody></table>
                </div>
            </article>
            <article class="admin-command-card admin-table-card">
                <div class="admin-card-head"><div><span class="driver-kicker">Driver Panel</span><h2>Ride History</h2></div></div>
                <div class="admin-table-wrap">
                    <table class="admin-smart-table"><thead><tr><th>Route</th><th>Completed</th><th>Fare</th></tr></thead><tbody>
                        <?php foreach ($driverHistory as $history): ?>
                            <tr><td><strong><?php echo htmlspecialchars(($history['pickup'] ?? 'Pickup') . ' -> ' . ($history['drop_location'] ?? 'Drop')); ?></strong></td><td><?php echo htmlspecialchars($history['completed_at'] ?? 'Unknown'); ?></td><td>Rs <?php echo number_format((float) ($history['fare'] ?? 0), 0); ?></td></tr>
                        <?php endforeach; ?>
                        <?php if (count($driverHistory) === 0): ?><tr><td colspan="3">No ride history visible.</td></tr><?php endif; ?>
                    </tbody></table>
                </div>
            </article>
        </section>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
