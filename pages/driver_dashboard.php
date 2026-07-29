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

<?php if ((int) ($state['completed_trips'] ?? 0) === 0 && (int) ($state['pending_requests'] ?? 0) === 0): ?>
    <section class="quick-start-onboarding-banner" style="background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%); border: 1px solid #86efac; border-radius: 12px; padding: 1.5rem; margin-bottom: 1.75rem;">
        <div style="max-width: 600px; margin-bottom: 1rem;">
            <span style="font-size: 0.8rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #15803d; display: flex; align-items: center; gap: 0.35rem;">
                <svg class="ui-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.5 2.8C2.05 10.9 2 11.2 2 11.5V16c0 .6.4 1 1 1h2"/><circle cx="7" cy="17" r="2"/><path d="M9 17h6"/><circle cx="17" cy="17" r="2"/></svg>
                Driver Onboarding
            </span>
            <h2 style="font-size: 1.35rem; font-weight: 700; color: #0f172a; margin: 0.25rem 0 0.5rem 0;">Ready to start accepting passenger requests?</h2>
            <p style="font-size: 0.92rem; color: #334155; margin: 0; line-height: 1.5;">Set your availability status to Online to start receiving passenger matching requests across campus.</p>
        </div>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem;">
            <a href="/ridesync/pages/driver_requests.php" style="background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; padding: 1rem; text-decoration: none; color: inherit; display: flex; flex-direction: column; gap: 0.25rem;">
                <span style="font-size: 0.8rem; font-weight: 600; color: #2563eb; display: flex; align-items: center; gap: 0.35rem;">
                    View Requests
                    <svg class="ui-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4"/><path d="M12 16h4"/><path d="M8 11h.01"/><path d="M8 16h.01"/></svg>
                </span>
                <strong style="font-size: 1rem; color: #0f172a;">Passenger Ride Requests</strong>
                <small style="color: #64748b;">Review incoming rider requests.</small>
            </a>
            <a href="/ridesync/pages/driver_profile.php" style="background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; padding: 1rem; text-decoration: none; color: inherit; display: flex; flex-direction: column; gap: 0.25rem;">
                <span style="font-size: 0.8rem; font-weight: 600; color: #059669; display: flex; align-items: center; gap: 0.35rem;">
                    Verification Docs
                    <svg class="ui-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/><path d="M16 13H8"/><path d="M16 17H8"/><path d="M10 9H8"/></svg>
                </span>
                <strong style="font-size: 1rem; color: #0f172a;">Driver Documents</strong>
                <small style="color: #64748b;">Keep your verification up to date.</small>
            </a>
        </div>
    </section>
<?php endif; ?>

<section id="driver-availability" class="driver-home-card">
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
        <span>Earnings</span>
        <strong data-driver-today><?php echo ridesync_format_money($state['today_earnings']); ?></strong>
        <small><?php echo ridesync_format_money($state['week_earnings']); ?> this week</small>
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

<nav class="panel-action-rail driver-command-rail" aria-label="Primary driver actions">
    <a class="panel-action-card is-primary" href="/ridesync/pages/driver_requests.php">
        <span>Dispatch</span>
        <strong>Queue</strong>
        <small>Requests and active trips.</small>
    </a>
    <a class="panel-action-card" href="/ridesync/pages/driver_earnings.php">
        <span>Settlement</span>
        <strong>Earnings</strong>
        <small>Trips and weekly totals.</small>
    </a>
    <a class="panel-action-card" href="/ridesync/pages/driver_profile.php">
        <span>Readiness</span>
        <strong>Profile</strong>
        <small>Documents and vehicle.</small>
    </a>
</nav>

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
