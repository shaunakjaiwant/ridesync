<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/driver_account_helper.php';
require_once __DIR__ . '/../includes/driver_opportunity_helper.php';

ridesync_require_driver_login();

$driverId = (int) $_SESSION['driver_id'];
$state = ridesync_fetch_driver_state($conn, $driverId);

if (!$state['schema_ready']) {
    $_SESSION['driver_auth_error'] = "Driver database tables are missing.";
    header("Location: /ridesync/pages/driver_login.php");
    exit();
}

if (($state['account']['status'] ?? '') !== 'active') {
    unset($_SESSION['driver_id'], $_SESSION['driver_name']);
    $_SESSION['driver_auth_error'] = "This driver account cannot access the dashboard right now.";
    header("Location: /ridesync/pages/driver_login.php");
    exit();
}

if (!ridesync_driver_onboarding_complete($state)) {
    header("Location: /ridesync/pages/driver_profile.php");
    exit();
}

$isVerifiedDriver = ridesync_driver_is_verified($state);
$communityRideCount = ridesync_count_driver_community_rides($conn, $driverId);
$communityRides = ridesync_fetch_driver_community_rides($conn, $driverId, 3);

require_once __DIR__ . '/../includes/driver_header.php';
?>

<div data-driver-live>
<div class="driver-page-header">
    <div>
        <span class="driver-kicker">Driver Home</span>
        <h1>Welcome, <?php echo htmlspecialchars($_SESSION['driver_name']); ?></h1>
    </div>
    <span class="driver-status-pill driver-status-<?php echo htmlspecialchars($state['availability']); ?>">
        <?php echo $state['availability'] === 'online' ? 'Online' : 'Offline'; ?>
    </span>
</div>

<?php ridesync_flash('driver_success', 'alert-success'); ?>
<?php ridesync_flash('driver_error', 'alert-error'); ?>

<section class="driver-home-card">
    <div>
        <span class="driver-kicker">Availability</span>
        <h2>
            <?php if (!$isVerifiedDriver): ?>
                Verification review required
            <?php else: ?>
                <?php echo $state['availability'] === 'online' ? 'You are receiving ride requests' : 'You are not visible to riders'; ?>
            <?php endif; ?>
        </h2>
        <p>
            <?php if (!$isVerifiedDriver): ?>
                Admin approval keeps RideSync safe. You can update your documents from the profile tab while your review is pending.
            <?php else: ?>
                <?php echo $state['availability'] === 'online' ? 'Go offline when you cannot respond quickly.' : 'Go online when you are ready to accept requests.'; ?>
            <?php endif; ?>
        </p>
    </div>

    <form action="/ridesync/actions/driver_account_action.php" method="POST" data-driver-availability-form>
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="action_type" value="toggle_availability">
        <input type="hidden" name="current_lat" value="">
        <input type="hidden" name="current_lng" value="">
        <?php if ($state['availability'] === 'online'): ?>
            <input type="hidden" name="status" value="offline">
            <button type="submit" class="driver-toggle driver-toggle-off">Go Offline</button>
        <?php elseif (!$isVerifiedDriver): ?>
            <input type="hidden" name="status" value="online">
            <button type="submit" class="driver-toggle driver-toggle-off" disabled>Pending Review</button>
        <?php else: ?>
            <input type="hidden" name="status" value="online">
            <button type="submit" class="driver-toggle driver-toggle-on">Go Online</button>
        <?php endif; ?>
    </form>
</section>

<?php if ((int) ($state['active_workload'] ?? 0) > 0): ?>
    <section class="driver-notice-card">
        <strong>Active trip in progress</strong>
        <span>You are hidden from new rider requests until the current trip is completed.</span>
    </section>
<?php endif; ?>

<div class="driver-metrics-grid">
    <div class="driver-metric">
        <span>Today</span>
        <strong data-driver-today><?php echo ridesync_format_money($state['today_earnings']); ?></strong>
    </div>
    <div class="driver-metric">
        <span>This Week</span>
        <strong data-driver-week><?php echo ridesync_format_money($state['week_earnings']); ?></strong>
    </div>
    <div class="driver-metric">
        <span>Pending Requests</span>
        <strong data-driver-pending><?php echo (int) $state['pending_requests']; ?></strong>
    </div>
    <div class="driver-metric">
        <span>Active Trips</span>
        <strong data-driver-active><?php echo (int) ($state['active_workload'] ?? 0); ?></strong>
    </div>
    <div class="driver-metric">
        <span>Total Trips</span>
        <strong data-driver-trips><?php echo (int) $state['completed_trips']; ?></strong>
    </div>
</div>

<div class="driver-action-row">
    <a href="/ridesync/pages/driver_requests.php" class="btn btn-primary">View Requests & Posted Rides</a>
    <a href="/ridesync/pages/driver_earnings.php" class="btn btn-secondary">View Earnings</a>
    <a href="/ridesync/pages/driver_history.php" class="btn btn-secondary">View History</a>
</div>

<section class="driver-panel driver-community-panel">
    <div class="driver-panel-header">
        <div>
            <span class="driver-kicker">Community Pool</span>
            <h2><?php echo (int) $communityRideCount; ?> posted ride<?php echo $communityRideCount === 1 ? '' : 's'; ?> waiting for driver support</h2>
            <p>These are rides posted from the user side. Drivers can pick them up when verified and online.</p>
        </div>
        <a href="/ridesync/pages/driver_requests.php#community-rides" class="btn btn-secondary">Open Pool</a>
    </div>

    <?php if (count($communityRides) === 0): ?>
        <p class="driver-empty">No open posted rides right now. New user posts will appear here automatically.</p>
    <?php else: ?>
        <div class="driver-opportunity-list">
            <?php foreach ($communityRides as $ride): ?>
                <?php
                    $isAssignedToDriver = (int) ($ride['assigned_driver_id'] ?? 0) === $driverId;
                    $etaMinutes = ridesync_driver_community_eta($ride, $state);
                    $fareEstimate = ridesync_driver_community_fare($ride);
                ?>
                <article class="driver-opportunity-card">
                    <div class="driver-opportunity-main">
                        <span class="driver-opportunity-status <?php echo $isAssignedToDriver ? 'is-assigned' : ''; ?>">
                            <?php echo $isAssignedToDriver ? 'Assigned to you' : 'Open ride'; ?>
                        </span>
                        <h3><?php echo htmlspecialchars($ride['origin']); ?> <span class="route-arrow">&rarr;</span> <?php echo htmlspecialchars($ride['destination']); ?></h3>
                        <p>
                            <?php echo htmlspecialchars(ridesync_driver_community_time_label($ride)); ?>
                            &middot; <?php echo (int) $ride['seats_available']; ?> seat<?php echo (int) $ride['seats_available'] === 1 ? '' : 's'; ?>
                            <?php if (!empty($ride['poster_name'])): ?>
                                &middot; Posted by <?php echo htmlspecialchars($ride['poster_name']); ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="driver-opportunity-side">
                        <strong><?php echo formatCost($fareEstimate); ?></strong>
                        <span>
                            <?php echo $etaMinutes !== null ? $etaMinutes . ' min pickup ETA' : 'Pickup ETA after location update'; ?>
                        </span>
                        <?php if ($isAssignedToDriver): ?>
                            <button type="button" class="btn btn-secondary" disabled>Already Assigned</button>
                        <?php elseif (!$isVerifiedDriver): ?>
                            <button type="button" class="btn btn-secondary" disabled>Verification Needed</button>
                        <?php elseif ($state['availability'] !== 'online'): ?>
                            <button type="button" class="btn btn-secondary" disabled>Go Online First</button>
                        <?php else: ?>
                            <form action="/ridesync/actions/driver_account_action.php" method="POST">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <input type="hidden" name="action_type" value="claim_community_ride">
                                <input type="hidden" name="ride_id" value="<?php echo (int) $ride['id']; ?>">
                                <input type="hidden" name="return_to" value="/ridesync/pages/driver_dashboard.php">
                                <button type="submit" class="btn btn-primary">Drive This Ride</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
</div>

<?php require_once __DIR__ . '/../includes/driver_footer.php'; ?>
