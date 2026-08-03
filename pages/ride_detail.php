<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/cost_helper.php';
require_once __DIR__ . '/../includes/matching_helper.php';
require_once __DIR__ . '/../includes/asset_helper.php';
require_once __DIR__ . '/../includes/rider_experience_helper.php';
require_once __DIR__ . '/../includes/eco_helper.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: /ridesync/pages/login.php");
    exit;
}

ridesync_enable_map_assets();
require_once __DIR__ . '/../includes/header.php';

$userId = (int) $_SESSION['user_id'];
$rideId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($rideId <= 0) {
    echo '<div class="alert alert-error">Invalid ride.</div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

$stmt = mysqli_prepare($conn,
    "SELECT r.*, u.name AS poster_name, u.email AS poster_email, u.college AS poster_college
     FROM rides r
     JOIN users u ON r.user_id = u.id
     WHERE r.id = ?"
);
mysqli_stmt_bind_param($stmt, "i", $rideId);
mysqli_stmt_execute($stmt);
$ride = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if (!$ride) {
    echo '<div class="alert alert-error">Ride not found.</div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

$isOwner = ((int) $ride['user_id'] === $userId);

$stmt = mysqli_prepare($conn,
    "SELECT id, status, match_score, pickup_distance_km, drop_distance_km, route_overlap_percent, time_score, match_source
     FROM matches WHERE ride_id = ? AND matched_user_id = ?"
);
mysqli_stmt_bind_param($stmt, "ii", $rideId, $userId);
mysqli_stmt_execute($stmt);
$existingMatch = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
$canPollLiveStatus = $isOwner || !empty($existingMatch);

$stmt = mysqli_prepare($conn,
    "SELECT u.id, u.name, u.college
     FROM matches m
     JOIN users u ON m.matched_user_id = u.id
     WHERE m.ride_id = ? AND m.status = 'accepted'"
);
mysqli_stmt_bind_param($stmt, "i", $rideId);
mysqli_stmt_execute($stmt);
$acceptedRiderRows = [];
$acceptedRidersResult = mysqli_stmt_get_result($stmt);
while ($rider = mysqli_fetch_assoc($acceptedRidersResult)) {
    $acceptedRiderRows[] = $rider;
}

$acceptedCount = count($acceptedRiderRows);
$ratingByReviewedUser = [];
if (ridesync_table_exists($conn, 'user_ratings')) {
    $stmt = mysqli_prepare($conn,
        "SELECT reviewed_user_id, rating
         FROM user_ratings
         WHERE ride_id = ? AND reviewer_user_id = ?"
    );
    mysqli_stmt_bind_param($stmt, "ii", $rideId, $userId);
    mysqli_stmt_execute($stmt);
    $ratingsResult = mysqli_stmt_get_result($stmt);
    while ($ratingRow = mysqli_fetch_assoc($ratingsResult)) {
        $ratingByReviewedUser[(int) $ratingRow['reviewed_user_id']] = (int) $ratingRow['rating'];
    }
}
$stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM matches WHERE ride_id = ? AND status = 'pending'");
mysqli_stmt_bind_param($stmt, "i", $rideId);
mysqli_stmt_execute($stmt);
$pendingCount = (int) (mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['total'] ?? 0);
ridesync_ensure_live_status($conn, $rideId, 'searching');

$stmt = mysqli_prepare($conn,
    "SELECT ls.*, d.name AS driver_name
     FROM ride_live_status ls
     LEFT JOIN driver_accounts d ON d.id = ls.driver_id
     WHERE ls.ride_id = ?
     LIMIT 1"
);
mysqli_stmt_bind_param($stmt, "i", $rideId);
mysqli_stmt_execute($stmt);
$liveState = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: [];
$liveStatus = $liveState['live_status'] ?? 'searching';

if ($ride['status'] === 'cancelled') {
    $liveStatus = 'cancelled';
} elseif ($acceptedCount > 0 && $liveStatus === 'searching') {
    $liveStatus = 'matched';
}
$isJoinableLiveStatus = !in_array($liveStatus, ['active', 'completed', 'cancelled'], true);

$hasAssignedDriver = !empty($liveState['driver_id']);
$canStartTrip = $isOwner
    && ($acceptedCount > 0 || $hasAssignedDriver)
    && $ride['status'] !== 'cancelled'
    && in_array($liveStatus, ['matched', 'driver_assigned'], true);
$canCompleteTrip = $isOwner
    && ($acceptedCount > 0 || $hasAssignedDriver)
    && $ride['status'] !== 'cancelled'
    && $liveStatus === 'active';

$timelineSteps = [
    'searching' => 'Ride Created',
    'matched' => 'Passengers Matched',
    'driver_assigned' => 'Driver Assigned',
    'active' => 'Trip Started',
    'completed' => 'Completed',
];
$timelineKeys = array_keys($timelineSteps);
$currentTimelineIndex = array_search($liveStatus, $timelineKeys, true);
if ($currentTimelineIndex === false) {
    $currentTimelineIndex = 0;
}

$fareParticipants = $acceptedCount + 1;
if (!$isOwner && (!$existingMatch || $existingMatch['status'] !== 'accepted')) {
    $fareParticipants++;
}
$fareBreakdown = calculateDynamicFareBreakdown($ride['origin'], $ride['destination'], max(1, $fareParticipants), $ride['route_distance_km'] ?? null);
$hasMapRoute = $ride['origin_lat'] !== null && $ride['origin_lng'] !== null
    && $ride['destination_lat'] !== null && $ride['destination_lng'] !== null;

$travelDate = date('l, F j, Y', strtotime($ride['travel_date']));
$travelTime = date('g:i A', strtotime($ride['travel_time']));
$travelTimestamp = strtotime($ride['travel_date'] . ' ' . $ride['travel_time']);
$isPast = $travelTimestamp && $travelTimestamp < (time() - 900);
$canRateRide = $liveStatus === 'completed' || $isPast;
$reportTargets = [];
if ($isOwner) {
    foreach ($acceptedRiderRows as $rider) {
        $reportTargets[] = [
            'id' => (int) $rider['id'],
            'label' => $rider['name'] . ' (' . $rider['college'] . ')',
        ];
    }
} elseif ($existingMatch && $existingMatch['status'] === 'accepted') {
    $reportTargets[] = [
        'id' => (int) $ride['user_id'],
        'label' => $ride['poster_name'] . ' (ride owner)',
    ];
}
$canReportRide = count($reportTargets) > 0;
$trustUserIds = array_merge([(int) $ride['user_id']], array_column($acceptedRiderRows, 'id'));
$userTrustSummaries = ridesync_fetch_user_trust_summaries($conn, $trustUserIds);
$posterTrust = $userTrustSummaries[(int) $ride['user_id']] ?? ridesync_default_user_trust_summary();
?>

<div class="page-header">
    <a href="/ridesync/pages/dashboard.php" class="back-link" data-history-back>&larr; Back</a>
    <h1>Ride Details</h1>
</div>

<?php ridesync_flash('success', 'alert-success'); ?>
<?php ridesync_flash('error', 'alert-error'); ?>
<?php ridesync_flash('match_success', 'alert-success'); ?>
<?php ridesync_flash('match_error', 'alert-error'); ?>

<div class="ride-detail-card">
    <div class="ride-detail-route">
        <div class="route-point">
            <span class="route-dot origin-dot"></span>
            <div>
                <small>From</small>
                <strong><?php echo htmlspecialchars($ride['origin']); ?></strong>
            </div>
        </div>
        <div class="route-line"></div>
        <div class="route-point">
            <span class="route-dot dest-dot"></span>
            <div>
                <small>To</small>
                <strong><?php echo htmlspecialchars($ride['destination']); ?></strong>
            </div>
        </div>
    </div>

    <?php if ($hasMapRoute): ?>
        <section class="ride-map-detail-card">
            <div class="map-picker-header">
                <div>
                    <span class="map-kicker">Route map</span>
                    <h2>Exact departure and destination</h2>
                    <p><?php echo $ride['route_distance_km'] ? number_format((float) $ride['route_distance_km'], 2) . ' km route distance' : 'Route distance estimated from selected pins'; ?></p>
                </div>
                <span class="fare-live-badge">Map verified</span>
            </div>
            <div class="ride-map ride-detail-map"
                 data-ride-map
                 data-origin="<?php echo htmlspecialchars($ride['origin']); ?>"
                 data-destination="<?php echo htmlspecialchars($ride['destination']); ?>"
                 data-origin-lat="<?php echo htmlspecialchars($ride['origin_lat']); ?>"
                 data-origin-lng="<?php echo htmlspecialchars($ride['origin_lng']); ?>"
                 data-destination-lat="<?php echo htmlspecialchars($ride['destination_lat']); ?>"
                 data-destination-lng="<?php echo htmlspecialchars($ride['destination_lng']); ?>"></div>
        </section>
    <?php endif; ?>

    <div class="ride-detail-info">
        <div class="info-item">
            <span class="info-label">Date</span>
            <span class="info-value"><?php echo $travelDate; ?></span>
        </div>
        <div class="info-item">
            <span class="info-label">Time</span>
            <span class="info-value"><?php echo $travelTime; ?></span>
        </div>
        <div class="info-item">
            <span class="info-label">Seats Available</span>
            <span class="info-value"><?php echo (int) $ride['seats_available']; ?></span>
        </div>
        <div class="info-item">
            <span class="info-label">Status</span>
            <span class="badge badge-<?php echo htmlspecialchars($ride['status']); ?>"><?php echo ucfirst(htmlspecialchars($ride['status'])); ?></span>
        </div>
    </div>

    <section class="live-status-card"<?php echo $canPollLiveStatus ? ' data-live-ride="' . (int) $rideId . '"' : ''; ?>>
        <div class="live-status-header">
            <div>
                <span class="fare-kicker">Live ride state</span>
                <h3 data-live-status-label><?php echo ucwords(str_replace('_', ' ', $liveStatus)); ?></h3>
                <p data-live-note>
                    <?php if (!empty($liveState['note'])): ?>
                        <?php echo htmlspecialchars($liveState['note']); ?>
                    <?php elseif ($liveStatus === 'driver_assigned' && !empty($liveState['driver_name'])): ?>
                        <?php echo htmlspecialchars($liveState['driver_name']); ?> is assigned to this posted ride.
                    <?php elseif ($liveStatus === 'matched'): ?>
                        Passenger matched and seat reserved.
                    <?php else: ?>
                        Ride is visible for smart matching.
                    <?php endif; ?>
                </p>
            </div>
            <div class="live-counts">
                <span><strong data-live-accepted><?php echo (int) $acceptedCount; ?></strong> accepted</span>
                <span><strong data-live-pending><?php echo (int) $pendingCount; ?></strong> pending</span>
                <span><strong data-live-seats><?php echo (int) $ride['seats_available']; ?></strong> seats left</span>
                <?php if (!empty($liveState['driver_name'])): ?>
                    <span><strong><?php echo htmlspecialchars($liveState['driver_name']); ?></strong> driver</span>
                <?php endif; ?>
            </div>
        </div>

        <div class="ride-timeline" data-live-steps>
            <?php foreach ($timelineSteps as $key => $label): ?>
                <?php
                $index = array_search($key, $timelineKeys, true);
                $state = $index < $currentTimelineIndex ? 'done' : ($index === $currentTimelineIndex ? 'current' : 'upcoming');
                ?>
                <div class="timeline-step timeline-<?php echo $state; ?>" data-step="<?php echo htmlspecialchars($key); ?>">
                    <span></span>
                    <strong><?php echo htmlspecialchars($label); ?></strong>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($canStartTrip || $canCompleteTrip): ?>
            <div class="live-status-actions">
                <span>Ride owner controls</span>
                <?php if ($canStartTrip): ?>
                    <form action="/ridesync/actions/ride_status_action.php" method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="ride_id" value="<?php echo (int) $rideId; ?>">
                        <input type="hidden" name="live_status" value="active">
                        <button type="submit" class="btn btn-primary btn-sm">Start Trip</button>
                    </form>
                <?php endif; ?>
                <?php if ($canCompleteTrip): ?>
                    <form action="/ridesync/actions/ride_status_action.php" method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="ride_id" value="<?php echo (int) $rideId; ?>">
                        <input type="hidden" name="live_status" value="completed">
                        <button type="submit" class="btn btn-primary btn-sm">Complete Trip</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($existingMatch && $existingMatch['match_score'] !== null): ?>
        <section class="smart-match-detail-card">
            <div>
                <span class="fare-kicker">Your smart match</span>
                <h3><?php echo number_format((float) $existingMatch['match_score'], 1); ?>% compatibility</h3>
                <p>
                    <?php echo (int) $existingMatch['route_overlap_percent']; ?>% route overlap
                    &middot; pickup <?php echo $existingMatch['pickup_distance_km'] !== null ? number_format((float) $existingMatch['pickup_distance_km'], 1) . ' km' : 'text scored'; ?>
                    &middot; drop <?php echo $existingMatch['drop_distance_km'] !== null ? number_format((float) $existingMatch['drop_distance_km'], 1) . ' km' : 'text scored'; ?>
                </p>
            </div>
            <div class="smart-score-bar"><i style="width: <?php echo (float) $existingMatch['match_score']; ?>%;"></i></div>
        </section>
    <?php endif; ?>

    <div class="ride-detail-poster">
        <h3>Posted by</h3>
        <p><strong><?php echo htmlspecialchars($ride['poster_name']); ?></strong></p>
        <div class="trust-badge-row">
            <?php if ($posterTrust['verified']): ?>
                <span class="trust-badge trust-badge-verified">Verified student</span>
            <?php endif; ?>
            <?php if ((int) $posterTrust['rating_count'] > 0): ?>
                <span class="trust-badge trust-badge-verified" title="Verified ratings from completed trips">★ <?php echo number_format((float) $posterTrust['rating_average'], 1); ?>/5 Verified Trip</span>
            <?php else: ?>
                <span class="trust-badge trust-badge-soft">New rider</span>
            <?php endif; ?>
        </div>
        <p><?php echo htmlspecialchars($ride['poster_college']); ?></p>
        <?php if ($existingMatch && $existingMatch['status'] === 'accepted'): ?>
            <p>Email: <?php echo htmlspecialchars($ride['poster_email']); ?></p>
        <?php endif; ?>
    </div>

    <?php if ($isOwner || ($existingMatch && $existingMatch['status'] === 'accepted')): ?>
        <section class="safety-card" style="border: 2px solid #ef4444; background: #fff5f5; border-radius: 12px; padding: 1.25rem; margin-top: 1.5rem;" data-live-tracking-ride="<?php echo (int) $rideId; ?>">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem;">
                <div>
                    <span class="fare-kicker" style="color: #dc2626; font-weight: 700; font-size: 0.8rem; text-transform: uppercase;">Safety & Emergency Tools</span>
                    <h3 style="margin: 0.25rem 0 0.25rem 0; color: #991b1b; font-size: 1.2rem;">SOS & Emergency Response</h3>
                    <span class="badge" data-live-gps-badge style="font-size: 0.75rem; background: #fee2e2; color: #991b1b;">GPS: Ready</span>
                </div>
                <div class="safety-actions" style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                    <form action="/ridesync/actions/sos_action.php" method="POST" style="display:inline;"
                          data-confirm-message="TRIGGER EMERGENCY SOS ALERT? Platform administrators will be notified immediately with your location.">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="ride_id" value="<?php echo (int) $rideId; ?>">
                        <input type="hidden" name="action" value="trigger">
                        <button type="submit" class="btn btn-danger" style="background: #dc2626; color: #fff; font-weight: 700; padding: 0.5rem 1rem;">
                            🚨 In-App SOS Alert
                        </button>
                    </form>

                    <a href="tel:112" class="btn btn-danger" style="background: #b91c1c; color: #fff; text-decoration: none; font-weight: 700; padding: 0.5rem 1rem;">
                        📞 Call Local Emergency (112)
                    </a>

                    <button type="button"
                            class="btn btn-secondary btn-sm"
                            data-share-trip
                            data-share-title="RideSync trip"
                            data-share-text="RideSync trip: <?php echo htmlspecialchars($ride['origin']); ?> to <?php echo htmlspecialchars($ride['destination']); ?>">
                        Share Trip
                    </button>
                </div>
            </div>

            <div style="margin-top: 0.85rem; padding: 0.65rem 0.85rem; background: #fef2f2; border: 1px solid #fca5a5; border-radius: 6px; font-size: 0.82rem; color: #7f1d1d; line-height: 1.45;">
                <strong>Notice:</strong> Tapping <em>In-App SOS Alert</em> transmits your live location to RideSync platform administrators for immediate safety tracking. It is <strong>not a replacement for official emergency services</strong>. If you are in immediate physical danger, call local emergency services (112 / 911) directly.
            </div>
        </section>
    <?php endif; ?>

    <?php if ($isOwner || ($existingMatch && $existingMatch['status'] === 'accepted')): ?>
        <div class="ride-detail-riders">
            <h3>Accepted Riders (<?php echo $acceptedCount; ?>)</h3>
            <?php if (count($acceptedRiderRows) === 0): ?>
                <p class="empty-state">No riders accepted yet.</p>
            <?php else: ?>
                <ul class="rider-list">
                    <?php foreach ($acceptedRiderRows as $rider): ?>
                        <?php $riderTrust = $userTrustSummaries[(int) $rider['id']] ?? ridesync_default_user_trust_summary(); ?>
                        <li>
                            <strong><?php echo htmlspecialchars($rider['name']); ?></strong>
                            &mdash; <?php echo htmlspecialchars($rider['college']); ?>
                            <div class="trust-badge-row">
                                <?php if ($riderTrust['verified']): ?>
                                    <span class="trust-badge trust-badge-verified">Verified student</span>
                                <?php endif; ?>
                                <?php if ((int) $riderTrust['rating_count'] > 0): ?>
                                    <span class="trust-badge trust-badge-verified" title="Verified ratings from completed trips">★ <?php echo number_format((float) $riderTrust['rating_average'], 1); ?>/5 Verified Trip</span>
                                <?php else: ?>
                                    <span class="trust-badge trust-badge-soft">New rider</span>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    <?php endif; ?>
                    <path class="fare-route fare-route-personal" d="M34 146 C82 88 121 117 160 88" />
                    <path class="fare-route fare-route-shared" d="M160 88 C211 39 264 66 326 36" />
                    <path class="fare-route fare-route-detour" d="M116 122 C143 156 196 145 226 106" />
                </svg>
                <span class="fare-pin fare-pin-origin" title="Origin">A</span>
                <span class="fare-pin fare-pin-pickup" title="Pickup">P</span>
                <span class="fare-pin fare-pin-rider" title="Shared rider">R</span>
                <span class="fare-pin fare-pin-drop" title="Drop">D</span>
                <div class="fare-map-legend">
                    <span><i class="legend-shared"></i>Shared route</span>
                    <span><i class="legend-personal"></i>Your route</span>
                    <span><i class="legend-detour"></i>Detour</span>
                </div>
            </div>
        </div>

        <div class="fare-summary-grid">
            <div class="fare-metric">
                <span>Total Ride Value</span>
                <strong><?php echo formatCost($fareBreakdown['total_ride_cost']); ?></strong>
            </div>
            <div class="fare-metric fare-metric-primary">
                <span>Your Final Fare</span>
                <strong><?php echo formatCost($fareBreakdown['final_fare']); ?></strong>
            </div>
            <div class="fare-metric">
                <span>Shared Savings</span>
                <strong>-<?php echo formatCost($fareBreakdown['savings_amount']); ?></strong>
            </div>
            <div class="fare-metric">
                <span>Detour + Time</span>
                <strong>+<?php echo formatCost($fareBreakdown['detour_charge'] + $fareBreakdown['time_adjustment']); ?></strong>
            </div>
        </div>

        <div class="fare-explainer-grid">
            <details open>
                <summary>Distance based calculation</summary>
                <p><?php echo number_format($fareBreakdown['direct_distance_km'], 1); ?> km route &times; <?php echo formatFareRate(); ?>/km = <?php echo formatCost($fareBreakdown['base_route_fare']); ?> base ride value. Of that, <?php echo number_format($fareBreakdown['shared_distance_km'], 1); ?> km is shared and <?php echo number_format($fareBreakdown['personal_distance_km'], 1); ?> km is personal.</p>
            </details>
            <details>
                <summary>Detour and time adjustment</summary>
                <p>Your fare includes <?php echo formatCost($fareBreakdown['detour_charge']); ?> for your share of a <?php echo number_format($fareBreakdown['detour_km'], 1); ?> km pickup/drop detour and <?php echo formatCost($fareBreakdown['time_adjustment']); ?> for about <?php echo (int) $fareBreakdown['time_added_minutes']; ?> minutes added to the route.</p>
            </details>
            <details>
                <summary>Fair split logic</summary>
                <p><?php echo (int) $fareBreakdown['overlap_percent']; ?>% route overlap is split across <?php echo (int) $fareBreakdown['participants']; ?> rider<?php echo (int) $fareBreakdown['participants'] === 1 ? '' : 's'; ?>. Your synced estimate is base route share + detour share + time share, rounded consistently across the app.</p>
            </details>
        </div>

        <div class="fare-trust-row">
            <span>AI optimized route</span>
            <span>Transparent pricing enabled</span>
            <span>Real-time fare ready</span>
        </div>

        <div class="fare-slider-card">
            <label for="fareCompare">Solo ride vs shared ride</label>
            <input id="fareCompare" type="range" min="1" max="6" value="<?php echo (int) $fareBreakdown['participants']; ?>"
                   data-fare-slider
                   data-total="<?php echo (int) $fareBreakdown['total_ride_cost']; ?>"
                   data-solo="<?php echo (int) $fareBreakdown['solo_cost']; ?>"
                   data-detour="<?php echo (int) $fareBreakdown['detour_charge']; ?>"
                   data-time="<?php echo (int) $fareBreakdown['time_adjustment']; ?>"
                   data-distance="<?php echo htmlspecialchars((string) $fareBreakdown['direct_distance_km']); ?>"
                   data-rate="<?php echo htmlspecialchars((string) $fareBreakdown['rate_per_km']); ?>"
                   data-time-rate="<?php echo htmlspecialchars((string) $fareBreakdown['time_rate_per_minute']); ?>"
                   data-seed="<?php echo (int) ridesync_route_seed($ride['origin'], $ride['destination']); ?>">
            <div class="fare-slider-output">
                <span><strong data-fare-slider-output><?php echo formatCost($fareBreakdown['final_fare']); ?></strong> estimated share</span>
                <span data-fare-slider-savings><?php echo (int) $fareBreakdown['savings_percent']; ?>% saved</span>
            </div>
        </div>
    </section>

    <?php
    $rideDistanceForEco = (float) ($ride['route_distance_km'] ?? 0);
    if ($rideDistanceForEco <= 0) {
        $rideDistanceForEco = ridesync_estimate_route_distance($ride['origin'], $ride['destination']);
    }
    $rideEco = ridesync_calculate_ride_eco_impact($rideDistanceForEco, max(1, $acceptedCount + 1));
    ?>
    <section class="eco-impact-card" style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 12px; padding: 1.25rem; margin-top: 1.5rem;">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
            <div>
                <span class="fare-kicker" style="color: #16a34a; font-weight: 700; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em;">Eco-Impact Estimator</span>
                <h3 style="margin: 0.25rem 0 0.25rem 0; color: #14532d; font-size: 1.2rem;">Environmental Impact of this Ride</h3>
                <p style="margin: 0; color: #166534; font-size: 0.9rem;">
                    Sharing this trip with <?php echo max(1, $acceptedCount + 1); ?> rider<?php echo ($acceptedCount + 1) === 1 ? '' : 's'; ?> avoids an estimated 
                    <strong><?php echo $rideEco['formatted']; ?></strong> vs. everyone driving solo.
                </p>
                <small style="color: #15803d; display: block; margin-top: 0.35rem; font-size: 0.8rem;">
                    *Assumes average petrol vehicle emissions (~120g CO2/km). Equivalent to planting <?php echo number_format($rideEco['trees_equivalent'], 2); ?> tree-days of CO2 absorption.
                </small>
            </div>
            <div style="background: #ffffff; border: 1px solid #86efac; border-radius: 8px; padding: 0.75rem 1.25rem; text-align: center;">
                <span style="font-size: 0.75rem; color: #166534; font-weight: 600; text-transform: uppercase;">CO2 Saved</span>
                <div style="font-size: 1.5rem; font-weight: 800; color: #15803d;"><?php echo number_format($rideEco['co2_saved_kg'], 1); ?> kg</div>
            </div>
        </div>
    </section>

    <div class="ride-detail-actions">
        <?php if ($isOwner): ?>
            <p class="owner-badge">This is your ride</p>
            <a href="/ridesync/pages/my_rides.php" class="btn btn-secondary">Manage in My Rides</a>

        <?php elseif ($isPast): ?>
            <p class="past-badge">This ride date has passed.</p>

        <?php elseif ($ride['status'] === 'closed'): ?>
            <p class="closed-badge">This ride is closed.</p>

        <?php elseif ($ride['status'] !== 'open'): ?>
            <p class="closed-badge">This ride is not available.</p>

        <?php elseif ($existingMatch): ?>
            <?php if ($existingMatch['status'] === 'pending'): ?>
                <p class="pending-badge">Your request is pending.</p>
                <form action="/ridesync/actions/match_action.php" method="POST" class="inline-form">
                    <input type="hidden" name="action" value="cancel">
                    <input type="hidden" name="match_id" value="<?php echo (int) $existingMatch['id']; ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="return_to" value="/ridesync/pages/ride_detail.php?id=<?php echo (int) $ride['id']; ?>">
                    <button type="submit" class="btn btn-danger btn-sm" data-confirm-message="Cancel your request?">Cancel Request</button>
                </form>
            <?php elseif ($existingMatch['status'] === 'accepted'): ?>
                <p class="accepted-badge">You're on this ride!</p>
            <?php elseif ($existingMatch['status'] === 'rejected'): ?>
                <p class="rejected-badge">Your request was declined.</p>
            <?php endif; ?>

        <?php elseif ((int) $ride['seats_available'] > 0 && $isJoinableLiveStatus): ?>
            <form action="/ridesync/actions/match_action.php" method="POST" class="inline-form">
                <input type="hidden" name="action" value="request">
                <input type="hidden" name="ride_id" value="<?php echo (int) $ride['id']; ?>">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="return_to" value="/ridesync/pages/ride_detail.php?id=<?php echo (int) $ride['id']; ?>">
                <button type="submit" class="btn btn-primary">Request to Join</button>
            </form>

        <?php else: ?>
            <p class="full-badge"><?php echo $isJoinableLiveStatus ? 'No seats available.' : 'This ride is already in progress or finished.'; ?></p>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
