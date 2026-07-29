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
if (!$admin || $admin['status'] !== 'active') {
    unset($_SESSION['admin_id'], $_SESSION['admin_name'], $_SESSION['admin_role']);
    $_SESSION['admin_error'] = "This admin account cannot access user details right now.";
    header("Location: /ridesync/pages/admin_login.php");
    exit();
}
ridesync_admin_sync_session($admin);

function ridesync_admin_detail_rows($result) {
    $rows = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $rows[] = $row;
        }
    }
    return $rows;
}

function ridesync_admin_detail_prepared_rows($conn, $sql, $types, $params) {
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

    return ridesync_admin_detail_rows(mysqli_stmt_get_result($stmt));
}

$userId = (int) ($_GET['user_id'] ?? 0);
if ($userId <= 0) {
    $_SESSION['admin_error'] = "Invalid user id.";
    header("Location: /ridesync/pages/admin_dashboard.php?section=users");
    exit();
}

$stmt = mysqli_prepare(
    $conn,
    "SELECT
        u.*,
        COALESCE(uv.verification_status, 'unverified') AS verification_status,
        uv.verification_type,
        uv.reference AS verification_reference,
        linked_driver.id AS linked_driver_id,
        linked_driver.name AS linked_driver_name,
        linked_driver.email AS linked_driver_email,
        linked_driver.status AS linked_driver_status,
        linked_profile.verification_status AS linked_driver_verification,
        COALESCE(linked_availability.status, 'offline') AS linked_driver_availability
     FROM users u
     LEFT JOIN (
        SELECT user_id,
               SUBSTRING_INDEX(GROUP_CONCAT(status ORDER BY updated_at DESC), ',', 1) AS verification_status,
               SUBSTRING_INDEX(GROUP_CONCAT(verification_type ORDER BY updated_at DESC), ',', 1) AS verification_type,
               SUBSTRING_INDEX(GROUP_CONCAT(reference ORDER BY updated_at DESC), ',', 1) AS reference
        FROM user_verifications
        GROUP BY user_id
     ) uv ON uv.user_id = u.id
     LEFT JOIN driver_accounts linked_driver
        ON CONVERT(linked_driver.email USING utf8mb4) COLLATE utf8mb4_unicode_ci
         = CONVERT(u.email USING utf8mb4) COLLATE utf8mb4_unicode_ci
     LEFT JOIN driver_account_profiles linked_profile ON linked_profile.driver_id = linked_driver.id
     LEFT JOIN driver_account_availability linked_availability ON linked_availability.driver_id = linked_driver.id
     WHERE u.id = ?
     LIMIT 1"
);
mysqli_stmt_bind_param($stmt, "i", $userId);
mysqli_stmt_execute($stmt);
$user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if (!$user) {
    $_SESSION['admin_error'] = "User not found.";
    header("Location: /ridesync/pages/admin_dashboard.php?section=users");
    exit();
}

$statsResult = mysqli_query(
    $conn,
    "SELECT
        (SELECT COUNT(*) FROM rides WHERE user_id = {$userId}) AS rides_posted,
        (SELECT COUNT(*) FROM rides WHERE user_id = {$userId} AND status = 'open') AS open_rides,
        (SELECT COUNT(*) FROM matches WHERE matched_user_id = {$userId}) AS join_requests,
        (SELECT COUNT(*) FROM matches WHERE matched_user_id = {$userId} AND status = 'accepted') AS accepted_join_requests,
        (SELECT COUNT(*)
         FROM matches m
         JOIN rides r ON r.id = m.ride_id
         WHERE r.user_id = {$userId} AND m.status = 'pending') AS pending_incoming_requests,
        (SELECT COUNT(*) FROM reports WHERE reporter_user_id = {$userId}) AS reports_filed,
        (SELECT COUNT(*) FROM reports WHERE reported_user_id = {$userId}) AS reports_against,
        (SELECT COALESCE(SUM(CASE WHEN transaction_type = 'fare_due' THEN amount ELSE 0 END), 0)
                - COALESCE(SUM(CASE WHEN transaction_type = 'cash_paid' THEN amount ELSE 0 END), 0)
         FROM wallet_transactions
         WHERE user_id = {$userId}) AS pending_due"
);
$stats = $statsResult ? (mysqli_fetch_assoc($statsResult) ?: []) : [];

$postedRides = ridesync_admin_detail_prepared_rows(
    $conn,
    "SELECT r.*, COALESCE(ls.live_status, 'searching') AS live_status, d.name AS driver_name
     FROM rides r
     LEFT JOIN ride_live_status ls ON ls.ride_id = r.id
     LEFT JOIN driver_accounts d ON d.id = ls.driver_id
     WHERE r.user_id = ?
     ORDER BY r.created_at DESC
     LIMIT 12",
    "i",
    [$userId]
);

$sentRequests = ridesync_admin_detail_prepared_rows(
    $conn,
    "SELECT m.*, r.origin, r.destination, r.travel_date, r.travel_time, owner.name AS owner_name
     FROM matches m
     JOIN rides r ON r.id = m.ride_id
     JOIN users owner ON owner.id = r.user_id
     WHERE m.matched_user_id = ?
     ORDER BY m.created_at DESC
     LIMIT 12",
    "i",
    [$userId]
);

$incomingRequests = ridesync_admin_detail_prepared_rows(
    $conn,
    "SELECT m.*, r.origin, r.destination, requester.name AS requester_name, requester.email AS requester_email
     FROM matches m
     JOIN rides r ON r.id = m.ride_id
     JOIN users requester ON requester.id = m.matched_user_id
     WHERE r.user_id = ?
     ORDER BY FIELD(m.status, 'pending', 'accepted', 'rejected'), m.created_at DESC
     LIMIT 12",
    "i",
    [$userId]
);

$reports = ridesync_admin_detail_prepared_rows(
    $conn,
    "SELECT rep.*, reporter.name AS reporter_name, reported.name AS reported_name, r.origin, r.destination
     FROM reports rep
     JOIN users reporter ON reporter.id = rep.reporter_user_id
     LEFT JOIN users reported ON reported.id = rep.reported_user_id
     LEFT JOIN rides r ON r.id = rep.ride_id
     WHERE rep.reporter_user_id = ? OR rep.reported_user_id = ?
     ORDER BY rep.created_at DESC
     LIMIT 12",
    "ii",
    [$userId, $userId]
);

$notifications = ridesync_admin_detail_prepared_rows(
    $conn,
    "SELECT title, message, is_read, created_at
     FROM notifications
     WHERE user_id = ?
     ORDER BY created_at DESC
     LIMIT 8",
    "i",
    [$userId]
);
$adminNotes = ridesync_admin_fetch_notes($conn, 'user', $userId, 8);
$adminNotesReady = ridesync_admin_notes_schema_ready($conn);
$userHealth = ridesync_admin_record_health_score($conn, 'user', $userId, [
    'user' => $user,
    'stats' => $stats,
]);
$userTimeline = ridesync_admin_record_timeline($conn, 'user', $userId, 12);

require_once __DIR__ . '/../includes/admin_header.php';
?>

<div class="admin-command-center">
    <section class="admin-hero-panel">
        <div>
            <span class="driver-kicker">User Control Link</span>
            <h1><?php echo htmlspecialchars($user['name']); ?></h1>
            <p><?php echo htmlspecialchars($user['email']); ?> &middot; <?php echo htmlspecialchars($user['college']); ?></p>
        </div>
        <div class="admin-hero-actions">
            <a class="btn btn-secondary" href="/ridesync/pages/admin_dashboard.php?section=users">Back to Users</a>
            <a class="btn btn-secondary" href="/ridesync/pages/admin_view_as.php?type=user&id=<?php echo (int) $userId; ?>">View As User</a>
            <?php if (!empty($user['linked_driver_id'])): ?>
                <a class="btn btn-primary" href="/ridesync/pages/admin_driver_verification.php?driver_id=<?php echo (int) $user['linked_driver_id']; ?>">Open Linked Driver</a>
            <?php endif; ?>
            <?php $isUserSuspended = ($user['status'] ?? 'active') === 'suspended'; ?>
            <form action="/ridesync/actions/admin_action.php" method="POST" data-confirm-message="<?php echo $isUserSuspended ? 'Reinstate this rider account?' : 'Suspend this rider account?'; ?>" style="display: inline-flex; gap: 0.4rem; align-items: center;">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="action_type" value="user_account_status">
                <input type="hidden" name="user_id" value="<?php echo (int) $userId; ?>">
                <input type="hidden" name="status" value="<?php echo $isUserSuspended ? 'active' : 'suspended'; ?>">
                <input type="hidden" name="return_to" value="/ridesync/pages/admin_user_detail.php?user_id=<?php echo (int) $userId; ?>">
                <?php if (!$isUserSuspended): ?>
                    <input type="text" name="reason" placeholder="Reason (optional)" style="font-size: 0.8rem; padding: 0.35rem 0.6rem; min-height: 34px; border: 1px solid var(--line); border-radius: 6px; width: 160px;">
                    <button type="submit" class="btn btn-danger btn-sm">Suspend Account</button>
                <?php else: ?>
                    <button type="submit" class="btn btn-primary btn-sm">Reinstate Account</button>
                <?php endif; ?>
            </form>
        </div>
    </section>

    <section class="admin-priority-grid">
        <article class="admin-op-card is-primary"><span>Rides Posted</span><strong><?php echo (int) ($stats['rides_posted'] ?? 0); ?></strong><small><?php echo (int) ($stats['open_rides'] ?? 0); ?> open right now</small></article>
        <article class="admin-op-card"><span>Join Requests</span><strong><?php echo (int) ($stats['join_requests'] ?? 0); ?></strong><small><?php echo (int) ($stats['accepted_join_requests'] ?? 0); ?> accepted</small></article>
        <article class="admin-op-card is-warning"><span>Incoming Queue</span><strong><?php echo (int) ($stats['pending_incoming_requests'] ?? 0); ?></strong><small>Waiting on this user's rides</small></article>
        <article class="admin-op-card is-danger"><span>Reports</span><strong><?php echo (int) ($stats['reports_against'] ?? 0); ?></strong><small><?php echo (int) ($stats['reports_filed'] ?? 0); ?> filed by user</small></article>
        <article class="admin-op-card"><span>Fare Due</span><strong>Rs <?php echo number_format((float) ($stats['pending_due'] ?? 0), 0); ?></strong><small>Wallet sync</small></article>
    </section>

    <section class="admin-record-insight-grid">
        <article class="admin-command-card admin-record-health-card is-<?php echo htmlspecialchars($userHealth['severity']); ?>">
            <div class="admin-card-head">
                <div>
                    <span class="driver-kicker">Record Health</span>
                    <h2>User Risk Profile</h2>
                </div>
                <span><?php echo htmlspecialchars($userHealth['label']); ?></span>
            </div>
            <div class="admin-record-health-score">
                <strong><?php echo (int) $userHealth['score']; ?></strong>
                <div>
                    <span>Health score /100</span>
                    <div class="admin-risk-meter" aria-hidden="true">
                        <span style="width: <?php echo max(0, min(100, (int) $userHealth['score'])); ?>%;"></span>
                    </div>
                </div>
            </div>
            <div class="admin-record-factor-list">
                <?php foreach ($userHealth['factors'] as $factor): ?>
                    <div class="is-<?php echo htmlspecialchars($factor['severity']); ?>">
                        <span><?php echo htmlspecialchars(ridesync_admin_status_label($factor['severity'])); ?></span>
                        <strong><?php echo htmlspecialchars($factor['title']); ?></strong>
                        <p><?php echo htmlspecialchars($factor['detail']); ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        </article>

        <article class="admin-command-card admin-record-timeline-card">
            <div class="admin-card-head">
                <div>
                    <span class="driver-kicker">Universal Timeline</span>
                    <h2>User Activity Trail</h2>
                </div>
                <span><?php echo count($userTimeline); ?> events</span>
            </div>
            <div class="admin-record-timeline">
                <?php foreach ($userTimeline as $event): ?>
                    <a class="admin-timeline-row is-<?php echo htmlspecialchars($event['severity']); ?>" href="<?php echo htmlspecialchars($event['href']); ?>">
                        <span class="admin-timeline-pin"></span>
                        <div>
                            <span><?php echo htmlspecialchars($event['type']); ?> - <?php echo htmlspecialchars($event['meta']); ?></span>
                            <strong><?php echo htmlspecialchars($event['title']); ?></strong>
                            <p><?php echo htmlspecialchars($event['detail']); ?></p>
                        </div>
                        <time><?php echo htmlspecialchars(date('M j, g:i A', strtotime((string) $event['created_at']))); ?></time>
                    </a>
                <?php endforeach; ?>
            </div>
        </article>
    </section>

    <section class="admin-command-grid is-lower">
        <article class="admin-command-card">
            <div class="admin-card-head"><div><span class="driver-kicker">Profile</span><h2>Trust and Panel Status</h2></div></div>
            <div class="admin-health-stack">
                <div><span>Student Verification</span><strong><?php echo htmlspecialchars(ridesync_admin_status_label($user['verification_status'])); ?></strong></div>
                <div><span>Verification Reference</span><strong><?php echo htmlspecialchars($user['verification_reference'] ?: 'Not provided'); ?></strong></div>
                <div><span>Linked Driver</span><strong><?php echo htmlspecialchars($user['linked_driver_name'] ?: 'No linked driver account'); ?></strong></div>
                <div><span>Driver Panel Status</span><strong><?php echo htmlspecialchars(!empty($user['linked_driver_id']) ? ridesync_admin_status_label(($user['linked_driver_status'] ?: 'inactive') . ' / ' . ($user['linked_driver_verification'] ?: 'pending')) : 'Rider only'); ?></strong></div>
            </div>
        </article>

        <article class="admin-command-card">
            <div class="admin-card-head"><div><span class="driver-kicker">Admin Notes</span><h2>Internal User Notes</h2></div></div>
            <div class="admin-note-panel">
                <?php if (count($adminNotes) === 0): ?>
                    <p>No internal notes have been saved for this user.</p>
                <?php else: ?>
                    <?php foreach ($adminNotes as $note): ?>
                        <p><b><?php echo htmlspecialchars(ridesync_admin_status_label($note['note_type'] ?? 'general')); ?></b> <?php echo htmlspecialchars($note['note_text']); ?> <small><?php echo htmlspecialchars($note['admin_name'] ?: 'Admin'); ?>, <?php echo htmlspecialchars(date('M j, g:i A', strtotime((string) $note['created_at']))); ?></small></p>
                    <?php endforeach; ?>
                <?php endif; ?>
                <?php if ($adminNotesReady): ?>
                    <form action="/ridesync/actions/admin_action.php" method="POST" class="admin-note-form">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="action_type" value="admin_note_create">
                        <input type="hidden" name="entity_type" value="user">
                        <input type="hidden" name="entity_id" value="<?php echo (int) $userId; ?>">
                        <input type="hidden" name="return_to" value="/ridesync/pages/admin_user_detail.php?user_id=<?php echo (int) $userId; ?>">
                        <select name="note_type" aria-label="Note type">
                            <option value="general">General</option>
                            <option value="risk">Risk</option>
                            <option value="support">Support</option>
                            <option value="compliance">Compliance</option>
                        </select>
                        <textarea name="note_text" maxlength="2000" placeholder="Add internal note" required></textarea>
                        <button type="submit" class="btn btn-primary btn-sm">Save Note</button>
                    </form>
                <?php else: ?>
                    <small>Run schema upgrade to enable persistent internal notes.</small>
                <?php endif; ?>
            </div>
        </article>

        <article class="admin-command-card">
            <div class="admin-card-head"><div><span class="driver-kicker">Notifications</span><h2>User Alerts</h2></div></div>
            <?php if (count($notifications) === 0): ?>
                <div class="driver-empty-card">No notifications sent to this user yet.</div>
            <?php else: ?>
                <div class="admin-feed-list">
                    <?php foreach ($notifications as $notification): ?>
                        <div class="admin-feed-item">
                            <span class="admin-feed-dot badge-<?php echo (int) $notification['is_read'] === 1 ? 'accepted' : 'open'; ?>"></span>
                            <div>
                                <strong><?php echo htmlspecialchars($notification['title']); ?></strong>
                                <p><?php echo htmlspecialchars($notification['message']); ?></p>
                                <small><?php echo htmlspecialchars(date('M j, g:i A', strtotime($notification['created_at']))); ?></small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </article>
    </section>

    <section class="admin-command-card admin-table-card">
        <div class="admin-card-head"><div><span class="driver-kicker">Rider Panel</span><h2>Posted Rides</h2></div></div>
        <div class="admin-table-wrap">
            <table class="admin-smart-table">
                <thead><tr><th>Ride</th><th>Status</th><th>Seats</th><th>Driver</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php foreach ($postedRides as $ride): ?>
                        <tr>
                            <td><strong>#<?php echo (int) $ride['id']; ?> <?php echo htmlspecialchars($ride['origin']); ?> &rarr; <?php echo htmlspecialchars($ride['destination']); ?></strong><span><?php echo htmlspecialchars(date('M j', strtotime($ride['travel_date'])) . ' at ' . date('g:i A', strtotime($ride['travel_time']))); ?></span></td>
                            <td><span class="badge badge-<?php echo htmlspecialchars(ridesync_admin_badge_class($ride['live_status'])); ?>"><?php echo htmlspecialchars(ridesync_admin_status_label($ride['live_status'])); ?></span></td>
                            <td><?php echo (int) $ride['seats_available']; ?></td>
                            <td><?php echo htmlspecialchars($ride['driver_name'] ?: 'Not assigned'); ?></td>
                            <td><a class="btn btn-secondary btn-sm" href="/ridesync/pages/admin_ride_detail.php?id=<?php echo (int) $ride['id']; ?>">Open</a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (count($postedRides) === 0): ?>
                        <tr><td colspan="5">No posted rides for this user.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="admin-command-grid is-lower">
        <article class="admin-command-card admin-table-card">
            <div class="admin-card-head"><div><span class="driver-kicker">Requests Sent</span><h2>Join Requests</h2></div></div>
            <div class="admin-table-wrap">
                <table class="admin-smart-table">
                    <thead><tr><th>Ride</th><th>Owner</th><th>Status</th><th>Score</th></tr></thead>
                    <tbody>
                        <?php foreach ($sentRequests as $request): ?>
                            <tr>
                                <td><strong>#<?php echo (int) $request['ride_id']; ?> <?php echo htmlspecialchars($request['origin']); ?> &rarr; <?php echo htmlspecialchars($request['destination']); ?></strong></td>
                                <td><?php echo htmlspecialchars($request['owner_name']); ?></td>
                                <td><span class="badge badge-<?php echo htmlspecialchars(ridesync_admin_badge_class($request['status'])); ?>"><?php echo htmlspecialchars(ridesync_admin_status_label($request['status'])); ?></span></td>
                                <td><?php echo $request['match_score'] !== null ? number_format((float) $request['match_score'], 0) . '%' : 'Manual'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (count($sentRequests) === 0): ?>
                            <tr><td colspan="4">No join requests sent.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </article>

        <article class="admin-command-card admin-table-card">
            <div class="admin-card-head"><div><span class="driver-kicker">Requests Received</span><h2>Incoming Join Requests</h2></div></div>
            <div class="admin-table-wrap">
                <table class="admin-smart-table">
                    <thead><tr><th>Ride</th><th>Requester</th><th>Status</th><th>Score</th></tr></thead>
                    <tbody>
                        <?php foreach ($incomingRequests as $request): ?>
                            <tr>
                                <td><strong>#<?php echo (int) $request['ride_id']; ?> <?php echo htmlspecialchars($request['origin']); ?> &rarr; <?php echo htmlspecialchars($request['destination']); ?></strong></td>
                                <td><?php echo htmlspecialchars($request['requester_name']); ?><span><?php echo htmlspecialchars($request['requester_email']); ?></span></td>
                                <td><span class="badge badge-<?php echo htmlspecialchars(ridesync_admin_badge_class($request['status'])); ?>"><?php echo htmlspecialchars(ridesync_admin_status_label($request['status'])); ?></span></td>
                                <td><?php echo $request['match_score'] !== null ? number_format((float) $request['match_score'], 0) . '%' : 'Manual'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (count($incomingRequests) === 0): ?>
                            <tr><td colspan="4">No incoming join requests.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </article>
    </section>

    <section class="admin-command-card admin-table-card">
        <div class="admin-card-head"><div><span class="driver-kicker">Moderation</span><h2>Reports Connected To User</h2></div></div>
        <div class="admin-table-wrap">
            <table class="admin-smart-table">
                <thead><tr><th>Report</th><th>Reporter</th><th>Reported</th><th>Status</th><th>Ride</th></tr></thead>
                <tbody>
                    <?php foreach ($reports as $report): ?>
                        <tr>
                            <td><strong>#<?php echo (int) $report['id']; ?> <?php echo htmlspecialchars(ridesync_admin_status_label($report['reason'])); ?></strong><span><?php echo htmlspecialchars(date('M j, g:i A', strtotime($report['created_at']))); ?></span></td>
                            <td><?php echo htmlspecialchars($report['reporter_name']); ?></td>
                            <td><?php echo htmlspecialchars($report['reported_name'] ?: 'Not linked'); ?></td>
                            <td><span class="badge badge-<?php echo htmlspecialchars(ridesync_admin_badge_class($report['report_status'])); ?>"><?php echo htmlspecialchars(ridesync_admin_status_label($report['report_status'])); ?></span></td>
                            <td><?php echo htmlspecialchars($report['origin'] ? $report['origin'] . ' -> ' . $report['destination'] : 'No ride'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (count($reports) === 0): ?>
                        <tr><td colspan="5">No reports connected to this user.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
