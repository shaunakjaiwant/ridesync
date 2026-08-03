<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/cost_helper.php';
require_once __DIR__ . '/../includes/matching_helper.php';
require_once __DIR__ . '/../includes/intelligence_helper.php';
require_once __DIR__ . '/../includes/asset_helper.php';
require_once __DIR__ . '/../includes/rider_experience_helper.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: /ridesync/pages/login.php");
    exit();
}

$user_id = (int) $_SESSION['user_id'];
$searched = false;
$rides = [];

$filter_origin = trim($_GET['origin'] ?? '');
$filter_dest = trim($_GET['destination'] ?? '');
$filter_date = trim($_GET['travel_date'] ?? '');
$filter_time = trim($_GET['travel_time'] ?? '');
$origin_lat = trim($_GET['origin_lat'] ?? '');
$origin_lng = trim($_GET['origin_lng'] ?? '');
$destination_lat = trim($_GET['destination_lat'] ?? '');
$destination_lng = trim($_GET['destination_lng'] ?? '');
$route_distance_km = trim($_GET['route_distance_km'] ?? '');
$route_polyline = trim($_GET['route_polyline'] ?? '');

$searched = $filter_origin !== '' || $filter_dest !== '' || $filter_date !== '' || $filter_time !== ''
    || $origin_lat !== '' || $destination_lat !== '';

$stmt = mysqli_prepare($conn, "SELECT college FROM users WHERE id = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$viewer = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
$viewerCollege = $viewer['college'] ?? '';

$searchContext = [
    'origin' => $filter_origin,
    'destination' => $filter_dest,
    'origin_lat' => $origin_lat,
    'origin_lng' => $origin_lng,
    'destination_lat' => $destination_lat,
    'destination_lng' => $destination_lng,
    'route_polyline' => $route_polyline,
    'travel_date' => $filter_date,
    'travel_time' => $filter_time,
];

$sql = "SELECT rides.*,
               rr.encoded_polyline,
               users.name AS poster_name,
               users.college AS poster_college,
               users.gender AS poster_gender,
               (SELECT status FROM matches
                WHERE matches.ride_id = rides.id
                AND matches.matched_user_id = ?
                LIMIT 1) AS request_status,
               (SELECT COUNT(*) FROM matches
                WHERE matches.ride_id = rides.id
                AND matches.status = 'accepted') AS accepted_count
        FROM rides
        JOIN users ON rides.user_id = users.id
        LEFT JOIN ride_routes rr ON rr.ride_id = rides.id
        LEFT JOIN ride_live_status ls ON ls.ride_id = rides.id
        WHERE rides.user_id != ?
          AND rides.status = 'open'
          AND TIMESTAMP(rides.travel_date, rides.travel_time) >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
          AND (ls.live_status IS NULL OR ls.live_status NOT IN ('active', 'completed', 'cancelled'))";

$params = [$user_id, $user_id];
$types = "ii";

if ($filter_date !== '') {
    $sql .= " AND rides.travel_date = ?";
    $params[] = $filter_date;
    $types .= "s";
}

$sql .= " ORDER BY rides.travel_date ASC, rides.travel_time ASC LIMIT 100";

$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

while ($row = mysqli_fetch_assoc($result)) {
    $match = ridesync_calculate_match_score($conn, $row, $searchContext, $viewerCollege);
    $row['smart_match'] = $match;

    $textMatches = true;
    if (!$searched) {
        $rides[] = $row;
        continue;
    }

    if ($filter_origin !== '') {
        $textMatches = $textMatches && (
            stripos($row['origin'], $filter_origin) !== false
            || stripos($filter_origin, $row['origin']) !== false
            || $match['pickup_score'] >= 52
        );
    }

    if ($filter_dest !== '') {
        $textMatches = $textMatches && (
            stripos($row['destination'], $filter_dest) !== false
            || stripos($filter_dest, $row['destination']) !== false
            || $match['drop_score'] >= 52
        );
    }

    if ($match['score'] >= 38 || $textMatches) {
        $rides[] = $row;
    }
}

usort($rides, function ($a, $b) {
    $scoreCompare = ($b['smart_match']['score'] <=> $a['smart_match']['score']);
    if ($scoreCompare !== 0) {
        return $scoreCompare;
    }

    return strcmp($a['travel_date'] . $a['travel_time'], $b['travel_date'] . $b['travel_time']);
});

$posterTrustSummaries = ridesync_fetch_user_trust_summaries($conn, array_column($rides, 'user_id'));
$bestScore = count($rides) > 0 ? (float) $rides[0]['smart_match']['score'] : 0;
$showDriverFallback = $searched && $bestScore < 70;
$bestDriver = $showDriverFallback ? ridesync_find_online_driver($conn, $searchContext) : null;
$fallbackDistance = ridesync_float_or_null($route_distance_km);

if ($fallbackDistance === null || $fallbackDistance <= 0) {
    if (ridesync_float_or_null($origin_lat) !== null && ridesync_float_or_null($origin_lng) !== null
        && ridesync_float_or_null($destination_lat) !== null && ridesync_float_or_null($destination_lng) !== null) {
        $fallbackDistance = ridesync_haversine_km($origin_lat, $origin_lng, $destination_lat, $destination_lng);
    } elseif ($filter_origin !== '' && $filter_dest !== '') {
        $fallbackDistance = ridesync_estimate_route_distance($filter_origin, $filter_dest);
    } else {
        $fallbackDistance = 4;
    }
}

$driverFare = ridesync_driver_fare_estimate($fallbackDistance);
$driverFareBreakdown = calculateDynamicFareBreakdown($filter_origin, $filter_dest, 1, $fallbackDistance);
$matchingDemandCount = $searched ? ridesync_count_matching_demand($conn, $searchContext, $user_id) : 0;
$waitSuggestion = $searched ? ridesync_smart_wait_suggestion($fallbackDistance, $bestScore, $matchingDemandCount, count($rides)) : null;
$returnTo = $_SERVER['REQUEST_URI'] ?? '/ridesync/pages/search_rides.php';
$routeShortcuts = ridesync_build_rider_route_shortcuts($conn, $user_id, 6);

ridesync_enable_map_assets();
require_once __DIR__ . '/../includes/header.php';
?>

<div class="search-page smart-search-page">
    <div class="page-header">
        <h1>Smart Match Search</h1>
        <p>Route-aware matching ranks community rides first, then offers an online driver if pooling is weak.</p>
    </div>

    <?php ridesync_flash('match_success', 'alert-success'); ?>
    <?php ridesync_flash('match_error', 'alert-error'); ?>

    <?php if (count($routeShortcuts) > 0): ?>
        <section class="smart-shortcut-panel smart-shortcut-panel-compact">
            <div class="smart-shortcut-head">
                <span class="fare-kicker">Recommended for you</span>
                <h2>Search from recent routes</h2>
            </div>
            <div class="smart-shortcut-grid">
                <?php foreach ($routeShortcuts as $shortcut): ?>
                    <a class="smart-route-chip" href="/ridesync/pages/search_rides.php?<?php echo htmlspecialchars(ridesync_route_query($shortcut)); ?>">
                        <span><?php echo htmlspecialchars($shortcut['label']); ?></span>
                        <strong><?php echo htmlspecialchars($shortcut['origin']); ?> &rarr; <?php echo htmlspecialchars($shortcut['destination']); ?></strong>
                        <small><?php echo htmlspecialchars($shortcut['meta']); ?></small>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <div class="card search-filter-card smart-filter-card">
        <form method="GET" action="" class="search-form">
            <div class="smart-search-grid">
                <div class="form-group">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.35rem;">
                        <label for="origin" style="margin-bottom: 0;">Pickup</label>
                        <button type="button" class="btn btn-secondary btn-sm" data-use-current-location-departure style="font-size: 0.8rem; padding: 0.2rem 0.5rem; display: inline-flex; align-items: center; gap: 0.3rem;">
                            <svg class="ui-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
                            Near Me
                        </button>
                    </div>
                    <input type="text" id="origin" name="origin"
                           placeholder="e.g. SDMIT Campus"
                           value="<?php echo htmlspecialchars($filter_origin); ?>">
                </div>
                <div class="form-group">
                    <label for="destination">Destination</label>
                    <input type="text" id="destination" name="destination"
                           placeholder="e.g. Mangaluru"
                           value="<?php echo htmlspecialchars($filter_dest); ?>">
                </div>
                <div class="form-group">
                    <label for="travel_date">Date</label>
                    <input type="date" id="travel_date" name="travel_date"
                           min="<?php echo date('Y-m-d'); ?>"
                           value="<?php echo htmlspecialchars($filter_date); ?>">
                </div>
                <div class="form-group">
                    <label for="travel_time">Time</label>
                    <input type="time" id="travel_time" name="travel_time"
                           value="<?php echo htmlspecialchars($filter_time); ?>">
                </div>
            </div>

            <section class="map-picker-card compact-map-picker" data-map-picker>
                <div class="map-picker-header">
                    <div>
                        <span class="map-kicker">Smart route input</span>
                        <h2>Set exact pickup and destination</h2>
                        <p>Location suggestions, current location, and map pins improve match accuracy.</p>
                    </div>
                    <button type="button" class="btn btn-secondary btn-sm" data-use-current-location>Use my location</button>
                </div>

                <div class="map-picker-tools">
                    <button type="button" class="btn btn-secondary btn-sm is-active" data-map-mode="origin">Pickup</button>
                    <button type="button" class="btn btn-secondary btn-sm" data-map-mode="destination">Destination</button>
                    <button type="button" class="btn btn-secondary btn-sm" data-map-search-origin>Find pickup</button>
                    <button type="button" class="btn btn-secondary btn-sm" data-map-search-destination>Find destination</button>
                    <button type="button" class="btn btn-secondary btn-sm" data-map-swap>Swap</button>
                    <button type="button" class="btn btn-secondary btn-sm" data-map-clear>Clear</button>
                </div>

                <div id="smartSearchMap" class="ride-map" data-map-canvas role="region" aria-label="Interactive map for choosing pickup and destination"></div>

                <div class="map-picker-status">
                    <span data-map-status>Set pickup and destination for stronger matching.</span>
                    <div class="map-distance-summary">
                        <strong data-route-distance><?php echo $route_distance_km !== '' ? htmlspecialchars($route_distance_km) . ' km route' : 'Distance not set'; ?></strong>
                        <strong class="map-fare-highlight" data-route-fare><?php echo $route_distance_km !== '' ? ridesync_fare_preview_label((float) $route_distance_km) : 'Fare estimate appears here'; ?></strong>
                    </div>
                </div>

                <input type="hidden" name="origin_lat" value="<?php echo htmlspecialchars($origin_lat); ?>" data-origin-lat>
                <input type="hidden" name="origin_lng" value="<?php echo htmlspecialchars($origin_lng); ?>" data-origin-lng>
                <input type="hidden" name="destination_lat" value="<?php echo htmlspecialchars($destination_lat); ?>" data-destination-lat>
                <input type="hidden" name="destination_lng" value="<?php echo htmlspecialchars($destination_lng); ?>" data-destination-lng>
                <input type="hidden" name="route_distance_km" value="<?php echo htmlspecialchars($route_distance_km); ?>" data-route-distance-input>
                <input type="hidden" name="route_polyline" value="<?php echo htmlspecialchars($route_polyline); ?>" data-route-polyline-input>
            </section>

            <div class="smart-search-actions">
                <button type="submit" class="btn btn-primary">Find Smart Matches</button>
                <a href="/ridesync/pages/search_rides.php" class="btn btn-secondary">Reset</a>
            </div>
        </form>
    </div>

    <?php if ($searched): ?>
        <div class="smart-engine-summary">
            <div>
                <span class="fare-kicker">Matching engine</span>
                <strong><?php echo count($rides); ?> community option<?php echo count($rides) === 1 ? '' : 's'; ?></strong>
                <p>Score uses 35% route overlap, 25% pickup closeness, 20% destination closeness, 10% time fit, and 10% trust.</p>
            </div>
            <div class="smart-engine-score">
                <span>Best score</span>
                <strong><?php echo number_format($bestScore, 1); ?>%</strong>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($searched && $filter_origin !== '' && $filter_dest !== ''): ?>
        <section class="smart-wait-card">
            <div>
                <span class="fare-kicker">Smart wait timer</span>
                <h2><?php echo $waitSuggestion && $waitSuggestion['should_wait'] ? 'Pool may improve soon' : 'Strong match available'; ?></h2>
                <p>
                    <?php echo htmlspecialchars($waitSuggestion['message'] ?? 'RideSync is watching this route.'); ?>
                    <?php if ($matchingDemandCount > 0): ?>
                        <?php echo (int) $matchingDemandCount; ?> other demand signal<?php echo $matchingDemandCount === 1 ? '' : 's'; ?> already exist for this route.
                    <?php endif; ?>
                </p>
            </div>
            <form action="/ridesync/actions/demand_action.php" method="POST" class="demand-signal-form">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="origin" value="<?php echo htmlspecialchars($filter_origin); ?>">
                <input type="hidden" name="destination" value="<?php echo htmlspecialchars($filter_dest); ?>">
                <input type="hidden" name="travel_date" value="<?php echo htmlspecialchars($filter_date); ?>">
                <input type="hidden" name="travel_time" value="<?php echo htmlspecialchars($filter_time); ?>">
                <input type="hidden" name="origin_lat" value="<?php echo htmlspecialchars($origin_lat); ?>">
                <input type="hidden" name="origin_lng" value="<?php echo htmlspecialchars($origin_lng); ?>">
                <input type="hidden" name="destination_lat" value="<?php echo htmlspecialchars($destination_lat); ?>">
                <input type="hidden" name="destination_lng" value="<?php echo htmlspecialchars($destination_lng); ?>">
                <input type="hidden" name="route_distance_km" value="<?php echo htmlspecialchars((string) $fallbackDistance); ?>">
                <input type="hidden" name="route_polyline" value="<?php echo htmlspecialchars($route_polyline); ?>">
                <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($returnTo); ?>">
                <button type="submit" class="btn btn-primary">Notify Me</button>
                <a href="/ridesync/pages/insights.php" class="btn btn-secondary">View Route Heat</a>
            </form>
        </section>
    <?php endif; ?>

    <?php if ($showDriverFallback): ?>
        <section class="hybrid-fallback-card">
            <div>
                <span class="fare-kicker">Hybrid fallback</span>
                <h2><?php echo $bestDriver ? 'Online driver available' : 'No online driver right now'; ?></h2>
                <p>
                    <?php if ($bestDriver): ?>
                        Community pooling is weak for this search, so RideSync can send this request to <?php echo htmlspecialchars($bestDriver['name']); ?>.
                    <?php else: ?>
                        Community pooling is weak and no driver is currently online. Keep this route posted or try again later.
                    <?php endif; ?>
                </p>
            </div>

            <?php if ($bestDriver): ?>
                <div class="hybrid-driver-panel">
                    <div>
                        <strong><?php echo htmlspecialchars($bestDriver['name']); ?></strong>
                        <span><?php echo htmlspecialchars($bestDriver['vehicle_type'] ?? 'Vehicle'); ?> <?php echo htmlspecialchars($bestDriver['vehicle_number'] ?? ''); ?></span>
                        <span><?php echo $bestDriver['distance_km'] !== null ? number_format((float) $bestDriver['distance_km'], 1) . ' km away' : 'ETA about ' . (int) $bestDriver['eta_minutes'] . ' min'; ?></span>
                    </div>
                    <div class="hybrid-fare">
                        <span>Estimated fare</span>
                        <strong><?php echo formatCost($driverFare); ?></strong>
                    </div>
                </div>
                <div class="hybrid-pricing-note">
                    <span>Distance synced</span>
                    <strong><?php echo number_format($driverFareBreakdown['direct_distance_km'], 1); ?> km &times; <?php echo formatFareRate(); ?>/km</strong>
                    <span>Same estimate is sent to the driver request.</span>
                </div>

                <form action="/ridesync/actions/driver_request_action.php" method="POST" class="hybrid-request-form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="driver_id" value="<?php echo (int) $bestDriver['id']; ?>">
                    <input type="hidden" name="pickup" value="<?php echo htmlspecialchars($filter_origin); ?>">
                    <input type="hidden" name="drop_location" value="<?php echo htmlspecialchars($filter_dest); ?>">
                    <input type="hidden" name="route_distance_km" value="<?php echo htmlspecialchars((string) $fallbackDistance); ?>">
                    <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($returnTo); ?>">
                    <button type="submit" class="btn btn-primary" <?php echo ($filter_origin === '' || $filter_dest === '') ? 'disabled' : ''; ?>>Request Driver</button>
                </form>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if (count($rides) > 0): ?>
        <p class="search-results-count"><?php echo count($rides); ?> smart result<?php echo count($rides) !== 1 ? 's' : ''; ?> found</p>

        <div class="ride-cards smart-ride-cards">
            <?php foreach ($rides as $ride): ?>
                <?php
                $match = $ride['smart_match'];
                $riderCount = min(6, max(2, (int) ($ride['accepted_count'] ?? 0) + 2));
                $quickFare = calculateDynamicFareBreakdown($ride['origin'], $ride['destination'], $riderCount, $ride['route_distance_km'] ?? null);
                $posterTrust = $posterTrustSummaries[(int) $ride['user_id']] ?? ridesync_default_user_trust_summary();
                ?>
                <div class="ride-card smart-ride-card">
                    <?php
                    $requestStatus = $ride['request_status'] ?? null;
                    $requestLabel = [
                        'pending' => 'Requested',
                        'accepted' => 'Accepted',
                        'rejected' => 'Declined',
                    ][$requestStatus] ?? null;
                    ?>
                    <div class="smart-card-top">
                        <div>
                            <div class="ride-card-route">
                                <?php echo htmlspecialchars($ride['origin']); ?> &rarr; <?php echo htmlspecialchars($ride['destination']); ?>
                            </div>
                            <div class="ride-card-details">
                                <?php echo date('M j, Y', strtotime($ride['travel_date'])); ?>
                                &nbsp;&middot;&nbsp;
                                <?php echo date('g:i A', strtotime($ride['travel_time'])); ?>
                            </div>
                        </div>
                        <div class="match-score-ring" style="--score-deg: <?php echo (float) $match['score'] * 3.6; ?>deg;">
                            <strong><?php echo number_format((float) $match['score'], 0); ?>%</strong>
                            <span>match</span>
                        </div>
                    </div>

                    <div class="ride-card-details">
                        <?php echo htmlspecialchars($ride['poster_name']); ?>
                        &nbsp;&middot;&nbsp;
                        <?php echo htmlspecialchars($ride['poster_college']); ?>
                    </div>
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
                    <div class="ride-card-details">
                        <?php echo (int) $ride['seats_available']; ?> seat<?php echo (int) $ride['seats_available'] !== 1 ? 's' : ''; ?> available
                    </div>

                    <div class="smart-match-breakdown">
                        <span><?php echo htmlspecialchars($match['label']); ?></span>
                        <div class="smart-score-bar"><i style="width: <?php echo (float) $match['score']; ?>%;"></i></div>
                        <small>
                            <?php echo (int) $match['route_overlap_percent']; ?>% overlap
                            &middot; pickup <?php echo $match['pickup_distance_km'] !== null ? number_format((float) $match['pickup_distance_km'], 1) . ' km' : 'text scored'; ?>
                            &middot; drop <?php echo $match['drop_distance_km'] !== null ? number_format((float) $match['drop_distance_km'], 1) . ' km' : 'text scored'; ?>
                        </small>
                    </div>

                    <div class="fare-preview-card">
                        <div>
                            <span>Estimated fair share</span>
                            <strong><?php echo formatCost($quickFare['final_fare']); ?></strong>
                        </div>
                        <p>
                            <?php echo number_format($quickFare['direct_distance_km'], 1); ?> km &times; <?php echo formatFareRate(); ?>/km
                            &middot; <?php echo (int) $quickFare['overlap_percent']; ?>% route overlap
                            &middot; saves <?php echo (int) $quickFare['savings_percent']; ?>%
                        </p>
                    </div>

                    <div class="ride-card-footer">
                        <a href="/ridesync/pages/ride_detail.php?id=<?php echo (int) $ride['id']; ?>" class="btn btn-secondary btn-sm">Details</a>
                        <?php if ($requestStatus !== null): ?>
                            <span class="status-badge status-<?php echo htmlspecialchars($requestStatus); ?>"><?php echo htmlspecialchars($requestLabel ?? 'Requested'); ?></span>
                        <?php else: ?>
                            <form action="/ridesync/actions/match_action.php" method="POST" style="display:inline;">
                                <input type="hidden" name="ride_id" value="<?php echo (int) $ride['id']; ?>">
                                <input type="hidden" name="action" value="request">
                                <input type="hidden" name="match_score" value="<?php echo htmlspecialchars((string) $match['score']); ?>">
                                <input type="hidden" name="pickup_distance_km" value="<?php echo htmlspecialchars((string) ($match['pickup_distance_km'] ?? '')); ?>">
                                <input type="hidden" name="drop_distance_km" value="<?php echo htmlspecialchars((string) ($match['drop_distance_km'] ?? '')); ?>">
                                <input type="hidden" name="route_overlap_percent" value="<?php echo (int) $match['route_overlap_percent']; ?>">
                                <input type="hidden" name="time_score" value="<?php echo (int) $match['time_score']; ?>">
                                <input type="hidden" name="match_source" value="<?php echo htmlspecialchars($match['source']); ?>">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($returnTo); ?>">
                                <button type="submit" class="btn btn-primary btn-sm">Request to Join</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

    <?php else: ?>
        <div class="card search-empty-card" style="text-align: center; padding: 2.5rem 1.5rem; background: rgba(15, 23, 42, 0.6); border: 1px dashed rgba(56, 189, 248, 0.3); border-radius: 16px;">
            <div style="width: 48px; height: 48px; margin: 0 auto 1rem; border-radius: 50%; background: rgba(56, 189, 248, 0.1); color: #38bdf8; display: flex; align-items: center; justify-content: center;">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
            </div>
            <h3 style="font-size: 1.15rem; font-weight: 700; margin-bottom: 0.5rem; color: #f8fafc;">
                <?php echo $searched ? 'No community rides match this route right now' : 'No upcoming rides available right now'; ?>
            </h3>
            <p class="text-muted" style="max-width: 460px; margin: 0 auto 1.25rem; font-size: 0.9rem; line-height: 1.5;">
                Don't worry! You can set an instant Route Watch alert and RideSync will notify you as soon as a driver or rider posts a matching trip.
            </p>
            <?php if ($searched && $filter_origin !== '' && $filter_dest !== ''): ?>
                <form action="/ridesync/actions/demand_action.php" method="POST" style="display: inline-block;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="origin" value="<?php echo htmlspecialchars($filter_origin); ?>">
                    <input type="hidden" name="destination" value="<?php echo htmlspecialchars($filter_dest); ?>">
                    <input type="hidden" name="travel_date" value="<?php echo htmlspecialchars($filter_date); ?>">
                    <input type="hidden" name="travel_time" value="<?php echo htmlspecialchars($filter_time); ?>">
                    <input type="hidden" name="origin_lat" value="<?php echo htmlspecialchars($origin_lat); ?>">
                    <input type="hidden" name="origin_lng" value="<?php echo htmlspecialchars($origin_lng); ?>">
                    <input type="hidden" name="destination_lat" value="<?php echo htmlspecialchars($destination_lat); ?>">
                    <input type="hidden" name="destination_lng" value="<?php echo htmlspecialchars($destination_lng); ?>">
                    <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($returnTo); ?>">
                    <button type="submit" class="btn btn-primary btn-glow" style="display: inline-flex; align-items: center; gap: 0.5rem;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                        Notify Me When a Ride Opens
                    </button>
                </form>
            <?php else: ?>
                <a href="/ridesync/pages/post_ride.php" class="btn btn-primary btn-glow">Offer a New Ride</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
