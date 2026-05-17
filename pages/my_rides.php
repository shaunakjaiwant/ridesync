<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/matching_helper.php';

// Must be logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: /ridesync/pages/login.php");
    exit();
}

$user_id = (int) $_SESSION['user_id'];

// ---------- Handle ride actions (close / delete) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ride_action'])) {
    $ride_id = (int) ($_POST['ride_id'] ?? 0);
    $rideAction = $_POST['ride_action'] ?? '';

    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        $_SESSION['error'] = "Invalid request. Please try again.";
        header("Location: /ridesync/pages/my_rides.php");
        exit();
    }

    if ($ride_id <= 0 || !in_array($rideAction, ['close', 'reopen', 'delete'], true)) {
        $_SESSION['error'] = "Invalid ride action.";
        header("Location: /ridesync/pages/my_rides.php");
        exit();
    }

    mysqli_begin_transaction($conn);

    try {
        $stmt = $conn->prepare("
            SELECT r.*,
                   COALESCE(ls.live_status, 'searching') AS live_status,
                   ls.driver_id,
                   (SELECT COUNT(*) FROM matches WHERE ride_id = r.id) AS match_count,
                   (SELECT COUNT(*) FROM matches WHERE ride_id = r.id AND status = 'accepted') AS accepted_count
            FROM rides r
            LEFT JOIN ride_live_status ls ON ls.ride_id = r.id
            WHERE r.id = ? AND r.user_id = ?
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->bind_param("ii", $ride_id, $user_id);
        $stmt->execute();
        $ride = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$ride) {
            throw new RuntimeException("Ride not found or you do not have permission to manage it.");
        }

        $liveStatus = $ride['live_status'] ?? 'searching';
        $hasStartedLifecycle = in_array($liveStatus, ['driver_assigned', 'arriving', 'active', 'completed', 'cancelled'], true);
        $travelTimestamp = strtotime(($ride['travel_date'] ?? '') . ' ' . ($ride['travel_time'] ?? ''));
        $isPastRide = $travelTimestamp && $travelTimestamp < (time() - 900);

        if ($rideAction === 'close') {
            if ($ride['status'] !== 'open') {
                throw new RuntimeException("Only open rides can be closed.");
            }

            if (in_array($liveStatus, ['active', 'completed', 'cancelled'], true)) {
                throw new RuntimeException("Active, completed, or cancelled rides cannot be manually closed.");
            }

            $stmt = $conn->prepare("UPDATE rides SET status = 'closed' WHERE id = ? AND user_id = ? AND status = 'open'");
            $stmt->bind_param("ii", $ride_id, $user_id);
            $stmt->execute();
            $stmt->close();

            $nextLiveStatus = !empty($ride['driver_id'])
                ? 'driver_assigned'
                : (((int) $ride['accepted_count'] > 0) ? 'matched' : 'searching');
            ridesync_update_live_status($conn, $ride_id, $nextLiveStatus, 'Ride closed to new join requests.');
            $_SESSION['success'] = "Ride closed to new join requests.";
        } elseif ($rideAction === 'reopen') {
            if ($ride['status'] !== 'closed') {
                throw new RuntimeException("Only closed rides can be reopened.");
            }

            if ($isPastRide) {
                throw new RuntimeException("Past rides cannot be reopened.");
            }

            if ((int) $ride['seats_available'] <= 0) {
                throw new RuntimeException("Add available seats before reopening this ride.");
            }

            if ($hasStartedLifecycle) {
                throw new RuntimeException("Assigned, active, completed, or cancelled rides cannot be reopened.");
            }

            $stmt = $conn->prepare("UPDATE rides SET status = 'open' WHERE id = ? AND user_id = ? AND status = 'closed'");
            $stmt->bind_param("ii", $ride_id, $user_id);
            $stmt->execute();
            $stmt->close();

            ridesync_update_live_status($conn, $ride_id, 'searching', 'Ride reopened for smart matching.');
            $_SESSION['success'] = "Ride reopened for smart matching.";
        } elseif ($rideAction === 'delete') {
            if ((int) $ride['match_count'] > 0 || $hasStartedLifecycle) {
                throw new RuntimeException("This ride already has requests or live activity. Cancel it instead of deleting it.");
            }

            $stmt = $conn->prepare("DELETE FROM rides WHERE id = ? AND user_id = ?");
            $stmt->bind_param("ii", $ride_id, $user_id);
            $stmt->execute();
            $stmt->close();

            $_SESSION['success'] = "Ride deleted.";
        }

        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        $_SESSION['error'] = $e instanceof RuntimeException ? $e->getMessage() : "Could not update the ride. Please try again.";
    }

    // Refresh the page to reflect changes
    header("Location: /ridesync/pages/my_rides.php");
    exit();
}

// ---------- Fetch all rides by this user ----------
$stmt = $conn->prepare("
    SELECT r.*,
           COALESCE(ls.live_status, 'searching') AS live_status,
           ls.driver_id,
           (SELECT COUNT(*) FROM matches WHERE ride_id = r.id) AS match_count,
           (SELECT COUNT(*) FROM matches WHERE ride_id = r.id AND status = 'accepted') AS accepted_count
    FROM rides r
    LEFT JOIN ride_live_status ls ON ls.ride_id = r.id
    WHERE r.user_id = ?
    ORDER BY r.travel_date DESC, r.travel_time DESC
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$rides = $stmt->get_result();
$stmt->close();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>My Rides</h1>
    <p>Rides you've posted and their join requests.</p>
</div>

<?php ridesync_flash('success', 'alert-success'); ?>
<?php ridesync_flash('error', 'alert-error'); ?>
<?php ridesync_flash('match_success', 'alert-success'); ?>
<?php ridesync_flash('match_error', 'alert-error'); ?>

<?php if ($rides->num_rows === 0): ?>
    <div class="empty-state">
        <p>You haven't posted any rides yet.</p>
        <a href="/ridesync/pages/post_ride.php" class="btn btn-primary">Post Your First Ride</a>
    </div>
<?php else: ?>
    <?php while ($ride = $rides->fetch_assoc()): ?>
        <?php
        $liveStatus = $ride['live_status'] ?? 'searching';
        $hasStartedLifecycle = in_array($liveStatus, ['driver_assigned', 'arriving', 'active', 'completed', 'cancelled'], true);
        $travelTimestamp = strtotime(($ride['travel_date'] ?? '') . ' ' . ($ride['travel_time'] ?? ''));
        $isPastRide = $travelTimestamp && $travelTimestamp < (time() - 900);
        $canCloseRide = $ride['status'] === 'open' && !in_array($liveStatus, ['active', 'completed', 'cancelled'], true);
        $canReopenRide = $ride['status'] === 'closed'
            && !$isPastRide
            && (int) $ride['seats_available'] > 0
            && !$hasStartedLifecycle;
        $canDeleteRide = (int) ($ride['match_count'] ?? 0) === 0 && !$hasStartedLifecycle;
        $canCancelRide = $ride['status'] === 'open' && !in_array($liveStatus, ['active', 'completed', 'cancelled'], true);
        ?>
        <div class="ride-manage-card <?php echo $ride['status'] !== 'open' ? 'ride-closed' : ''; ?>">
            <div class="ride-manage-header">
                <div>
                    <h2><?php echo htmlspecialchars($ride['origin']); ?> &rarr; <?php echo htmlspecialchars($ride['destination']); ?></h2>
                    <p class="ride-meta">
                        Date <?php echo date('M j, Y', strtotime($ride['travel_date'])); ?>
                        &nbsp;&middot;&nbsp;
                        Time <?php echo date('g:i A', strtotime($ride['travel_time'])); ?>
                        &nbsp;&middot;&nbsp;
                        <?php echo (int) $ride['seats_available']; ?> seat(s) left
                        &nbsp;&middot;&nbsp;
                        <span class="status-badge status-<?php echo $ride['status']; ?>">
                            <?php echo ucfirst($ride['status']); ?>
                        </span>
                        &nbsp;&middot;&nbsp;
                        Live <?php echo ucwords(str_replace('_', ' ', htmlspecialchars($liveStatus))); ?>
                    </p>
                </div>
                <div class="ride-manage-actions">
                    <?php if ($canCloseRide): ?>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="ride_id" value="<?php echo $ride['id']; ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <button type="submit" name="ride_action" value="close" class="btn btn-small btn-warning">Close Ride</button>
                        </form>
                    <?php elseif ($canReopenRide): ?>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="ride_id" value="<?php echo $ride['id']; ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <button type="submit" name="ride_action" value="reopen" class="btn btn-small btn-secondary">Reopen</button>
                        </form>
                    <?php endif; ?>
                    <?php if ($canDeleteRide): ?>
                        <form method="POST" style="display:inline;" data-confirm-message="Delete this ride permanently?">
                            <input type="hidden" name="ride_id" value="<?php echo $ride['id']; ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <button type="submit" name="ride_action" value="delete" class="btn btn-small btn-danger">Delete</button>
                        </form>
                    <?php endif; ?>
                    <?php if ($canCancelRide): ?>
                        <form action="/ridesync/actions/cancel_ride_action.php" method="POST"
                              data-confirm-message="Cancel this ride? All match requests will be rejected.">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="ride_id" value="<?php echo $ride['id']; ?>">
                            <button type="submit" class="btn btn-danger btn-sm">Cancel Ride</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Match requests for this ride -->
            <?php
            $m_stmt = $conn->prepare("
                SELECT m.id AS match_id, m.status AS match_status, m.match_score, m.route_overlap_percent, m.created_at AS requested_at,
                       u.name, u.email, u.college, u.gender
                FROM matches m
                JOIN users u ON m.matched_user_id = u.id
                WHERE m.ride_id = ?
                ORDER BY m.created_at DESC
            ");
            $m_stmt->bind_param("i", $ride['id']);
            $m_stmt->execute();
            $match_requests = $m_stmt->get_result();
            $m_stmt->close();
            ?>

            <?php if ($match_requests->num_rows > 0): ?>
                <div class="match-requests-section">
                    <h4>Join Requests (<?php echo $match_requests->num_rows; ?>)</h4>
                    <div class="match-list">
                        <?php while ($m = $match_requests->fetch_assoc()): ?>
                            <div class="match-item">
                                <div class="match-info">
                                    <strong><?php echo htmlspecialchars($m['name']); ?></strong>
                                    <span class="match-detail"><?php echo htmlspecialchars($m['college']); ?> · <?php echo $m['gender']; ?></span>
                                    <span class="match-detail"><?php echo htmlspecialchars($m['email']); ?></span>
                                    <span class="match-detail">Requested <?php echo date('M j, g:i A', strtotime($m['requested_at'])); ?></span>
                                    <?php if ($m['match_score'] !== null): ?>
                                        <span class="match-detail">Smart score <?php echo number_format((float) $m['match_score'], 1); ?>% &middot; <?php echo (int) $m['route_overlap_percent']; ?>% overlap</span>
                                    <?php endif; ?>
                                </div>
                                <div class="match-actions">
                                    <?php if ($m['match_status'] === 'pending'): ?>
                                        <form method="POST" action="/ridesync/actions/match_action.php" style="display:inline;">
                                            <input type="hidden" name="action" value="accept">
                                            <input type="hidden" name="match_id" value="<?php echo $m['match_id']; ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                            <input type="hidden" name="return_to" value="/ridesync/pages/my_rides.php">
                                            <button type="submit" class="btn btn-small btn-primary">Accept</button>
                                        </form>
                                        <form method="POST" action="/ridesync/actions/match_action.php" style="display:inline;">
                                            <input type="hidden" name="action" value="reject">
                                            <input type="hidden" name="match_id" value="<?php echo $m['match_id']; ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                            <input type="hidden" name="return_to" value="/ridesync/pages/my_rides.php">
                                            <button type="submit" class="btn btn-small btn-danger">Reject</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="status-badge status-<?php echo $m['match_status']; ?>">
                                            <?php echo ucfirst($m['match_status']); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                </div>
            <?php else: ?>
                <p class="no-requests">No join requests yet.</p>
            <?php endif; ?>
        </div>
    <?php endwhile; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
