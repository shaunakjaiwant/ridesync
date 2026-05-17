<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/driver_account_helper.php';
require_once __DIR__ . '/../includes/driver_opportunity_helper.php';

ridesync_require_driver_login();
$driverId = (int) $_SESSION['driver_id'];
$state = ridesync_fetch_driver_state($conn, $driverId);
$isVerifiedDriver = ridesync_driver_is_verified($state);
$communityRideTotal = ridesync_count_driver_community_rides($conn, $driverId);
$communityRides = ridesync_fetch_driver_community_rides($conn, $driverId, 30);

$stmt = mysqli_prepare($conn,
    "SELECT rr.*, u.name AS rider_name, u.college AS rider_college
     FROM driver_ride_requests rr
     LEFT JOIN users u ON u.id = rr.rider_user_id
     WHERE rr.driver_id = ? AND rr.request_status = 'pending'
     ORDER BY rr.requested_at ASC"
);
mysqli_stmt_bind_param($stmt, "i", $driverId);
mysqli_stmt_execute($stmt);
$requests = mysqli_stmt_get_result($stmt);
$directRequestCount = mysqli_num_rows($requests);

$stmt = mysqli_prepare($conn,
    "SELECT rr.*, u.name AS rider_name, u.college AS rider_college
     FROM driver_ride_requests rr
     LEFT JOIN users u ON u.id = rr.rider_user_id
     WHERE rr.driver_id = ? AND rr.request_status = 'accepted'
     ORDER BY rr.responded_at ASC, rr.requested_at ASC"
);
mysqli_stmt_bind_param($stmt, "i", $driverId);
mysqli_stmt_execute($stmt);
$activeRequests = mysqli_stmt_get_result($stmt);
$activeRequestCount = mysqli_num_rows($activeRequests);

require_once __DIR__ . '/../includes/driver_header.php';
?>

<div class="driver-page-header">
    <div>
        <span class="driver-kicker">Driver Workbench</span>
        <h1>Requests & posted rides</h1>
        <p>Direct rider requests and user-posted community rides appear together here.</p>
    </div>
    <span class="driver-status-pill driver-status-<?php echo htmlspecialchars($state['availability']); ?>">
        <?php echo $state['availability'] === 'online' ? 'Online' : 'Offline'; ?>
    </span>
</div>

<?php ridesync_flash('driver_success', 'alert-success'); ?>
<?php ridesync_flash('driver_error', 'alert-error'); ?>

<section class="driver-panel">
    <div class="driver-panel-header">
        <div>
            <span class="driver-kicker">Direct Requests</span>
            <h2><?php echo (int) $directRequestCount; ?> incoming request<?php echo $directRequestCount === 1 ? '' : 's'; ?></h2>
            <p>These are riders who selected you directly from the smart driver fallback.</p>
        </div>
    </div>

    <?php if ($directRequestCount === 0): ?>
        <p class="driver-empty">No direct requests right now.</p>
    <?php else: ?>
        <div class="driver-request-grid">
            <?php while ($request = mysqli_fetch_assoc($requests)): ?>
                <article class="driver-request-card">
                    <span class="driver-kicker">New request</span>
                    <h2><?php echo htmlspecialchars($request['pickup']); ?> <span class="route-arrow">&rarr;</span> <?php echo htmlspecialchars($request['drop_location']); ?></h2>
                    <?php if (!empty($request['rider_name'])): ?>
                        <p>Rider: <strong><?php echo htmlspecialchars($request['rider_name']); ?></strong> &middot; <?php echo htmlspecialchars($request['rider_college'] ?? ''); ?></p>
                    <?php endif; ?>
                    <p>Estimated fare: <strong><?php echo formatCost($request['estimated_fare']); ?></strong></p>
                    <?php if (!empty($request['route_distance_km']) && !empty($request['fare_rate_per_km'])): ?>
                        <p><?php echo number_format((float) $request['route_distance_km'], 1); ?> km &times; <?php echo formatFareRate($request['fare_rate_per_km']); ?>/km</p>
                    <?php endif; ?>
                    <p>Requested <?php echo date('g:i A', strtotime($request['requested_at'])); ?></p>
                    <div class="driver-request-actions">
                        <form action="/ridesync/actions/driver_account_action.php" method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="action_type" value="respond_request">
                            <input type="hidden" name="request_id" value="<?php echo (int) $request['id']; ?>">
                            <input type="hidden" name="decision" value="accepted">
                            <button type="submit" class="btn btn-primary">Accept</button>
                        </form>
                        <form action="/ridesync/actions/driver_account_action.php" method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="action_type" value="respond_request">
                            <input type="hidden" name="request_id" value="<?php echo (int) $request['id']; ?>">
                            <input type="hidden" name="decision" value="rejected">
                            <button type="submit" class="btn btn-danger">Reject</button>
                        </form>
                    </div>
                </article>
            <?php endwhile; ?>
        </div>
    <?php endif; ?>
</section>

<section class="driver-panel">
    <div class="driver-panel-header">
        <div>
            <span class="driver-kicker">Active Direct Trips</span>
            <h2><?php echo (int) $activeRequestCount; ?> accepted trip<?php echo $activeRequestCount === 1 ? '' : 's'; ?></h2>
            <p>Complete accepted direct requests here so earnings and ride history stay accurate.</p>
        </div>
    </div>

    <?php if ($activeRequestCount === 0): ?>
        <p class="driver-empty">No accepted direct trips waiting for completion.</p>
    <?php else: ?>
        <div class="driver-request-grid">
            <?php while ($request = mysqli_fetch_assoc($activeRequests)): ?>
                <article class="driver-request-card">
                    <span class="driver-kicker">Accepted trip</span>
                    <h2><?php echo htmlspecialchars($request['pickup']); ?> <span class="route-arrow">&rarr;</span> <?php echo htmlspecialchars($request['drop_location']); ?></h2>
                    <?php if (!empty($request['rider_name'])): ?>
                        <p>Rider: <strong><?php echo htmlspecialchars($request['rider_name']); ?></strong> &middot; <?php echo htmlspecialchars($request['rider_college'] ?? ''); ?></p>
                    <?php endif; ?>
                    <p>Estimated fare: <strong><?php echo formatCost($request['estimated_fare']); ?></strong></p>
                    <?php if (!empty($request['route_distance_km']) && !empty($request['fare_rate_per_km'])): ?>
                        <p><?php echo number_format((float) $request['route_distance_km'], 1); ?> km &times; <?php echo formatFareRate($request['fare_rate_per_km']); ?>/km</p>
                    <?php endif; ?>
                    <p>Accepted <?php echo !empty($request['responded_at']) ? date('g:i A', strtotime($request['responded_at'])) : date('g:i A', strtotime($request['requested_at'])); ?></p>
                    <div class="driver-request-actions">
                        <form action="/ridesync/actions/driver_account_action.php" method="POST" data-confirm-message="Mark this direct trip completed?">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="action_type" value="complete_direct_request">
                            <input type="hidden" name="request_id" value="<?php echo (int) $request['id']; ?>">
                            <button type="submit" class="btn btn-primary">Complete Trip</button>
                        </form>
                    </div>
                </article>
            <?php endwhile; ?>
        </div>
    <?php endif; ?>
</section>

<section class="driver-panel driver-community-panel" id="community-rides">
    <div class="driver-panel-header">
        <div>
            <span class="driver-kicker">User Posted Rides</span>
            <h2><?php echo (int) $communityRideTotal; ?> community ride<?php echo $communityRideTotal === 1 ? '' : 's'; ?> available</h2>
            <p>Rides posted by students/users are shown here so drivers can support them without waiting for a separate request.</p>
        </div>
    </div>

    <?php if (!$isVerifiedDriver): ?>
        <div class="driver-notice-card">
            <strong>Verification required</strong>
            <span>You can view posted rides now, but admin approval is required before driving one.</span>
        </div>
    <?php elseif ($state['availability'] !== 'online'): ?>
        <div class="driver-notice-card">
            <strong>You are offline</strong>
            <span>Go online from the Home tab to claim a posted ride.</span>
        </div>
    <?php endif; ?>

    <?php if (count($communityRides) === 0): ?>
        <p class="driver-empty">No open user-posted rides are waiting right now.</p>
    <?php else: ?>
        <div class="driver-opportunity-list">
            <?php foreach ($communityRides as $ride): ?>
                <?php
                    $isAssignedToDriver = (int) ($ride['assigned_driver_id'] ?? 0) === $driverId;
                    $etaMinutes = ridesync_driver_community_eta($ride, $state);
                    $fareEstimate = ridesync_driver_community_fare($ride);
                    $distanceKm = ridesync_float_or_null($ride['route_distance_km'] ?? null);
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
                            <?php if ($distanceKm !== null): ?>
                                &middot; <?php echo number_format($distanceKm, 1); ?> km
                            <?php endif; ?>
                        </p>
                        <p>
                            Posted by <strong><?php echo htmlspecialchars($ride['poster_name'] ?? 'RideSync user'); ?></strong>
                            <?php if (!empty($ride['poster_college'])): ?>
                                &middot; <?php echo htmlspecialchars($ride['poster_college']); ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="driver-opportunity-side">
                        <span>Estimated ride value</span>
                        <strong><?php echo formatCost($fareEstimate); ?></strong>
                        <span>
                            <?php echo $etaMinutes !== null ? $etaMinutes . ' min pickup ETA' : 'ETA appears after location update'; ?>
                        </span>

                        <?php if ($isAssignedToDriver): ?>
                            <button type="button" class="btn btn-secondary" disabled>Already Assigned</button>
                        <?php elseif (!$isVerifiedDriver): ?>
                            <button type="button" class="btn btn-secondary" disabled>Verification Needed</button>
                        <?php elseif ($state['availability'] !== 'online'): ?>
                            <a href="/ridesync/pages/driver_dashboard.php" class="btn btn-secondary">Go Online</a>
                        <?php else: ?>
                            <form action="/ridesync/actions/driver_account_action.php" method="POST">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <input type="hidden" name="action_type" value="claim_community_ride">
                                <input type="hidden" name="ride_id" value="<?php echo (int) $ride['id']; ?>">
                                <input type="hidden" name="return_to" value="/ridesync/pages/driver_requests.php#community-rides">
                                <button type="submit" class="btn btn-primary">Drive This Ride</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php require_once __DIR__ . '/../includes/driver_footer.php'; ?>
