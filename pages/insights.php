<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/cost_helper.php';
require_once __DIR__ . '/../includes/view_helper.php';
require_once __DIR__ . '/../includes/intelligence_helper.php';
require_once __DIR__ . '/../includes/rider_experience_helper.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: /ridesync/pages/login.php");
    exit();
}

$userId = (int) $_SESSION['user_id'];
$popularRoutes = ridesync_fetch_popular_routes($conn, 8);
$myDemandSignals = ridesync_fetch_user_demand_signals($conn, $userId, 6);
$greenScore = ridesync_green_score_for_user($conn, $userId);
$routeShortcuts = ridesync_build_rider_route_shortcuts($conn, $userId, 3);

$activeDemand = 0;
if (ridesync_table_exists($conn, 'route_demand_signals')) {
    $result = mysqli_query($conn,
        "SELECT COUNT(*) AS total
         FROM route_demand_signals
         WHERE demand_status = 'active'
           AND (travel_date IS NULL OR TIMESTAMP(travel_date, COALESCE(travel_time, '23:59:59')) >= DATE_SUB(NOW(), INTERVAL 15 MINUTE))"
    );
    $activeDemand = (int) (mysqli_fetch_assoc($result)['total'] ?? 0);
}

$openRides = 0;
$result = mysqli_query($conn,
    "SELECT COUNT(*) AS total
     FROM rides
     WHERE status = 'open'
       AND TIMESTAMP(travel_date, travel_time) >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
);
if ($result) {
    $openRides = (int) (mysqli_fetch_assoc($result)['total'] ?? 0);
}

$sharedKm = (float) $greenScore['shared_km'];
$co2Saved = (float) $greenScore['co2_kg'];
$fuelSavedLiters = round($sharedKm * 0.08, 1);
$treesEquivalent = max(0, (int) round($co2Saved / 21));

require_once __DIR__ . '/../includes/header.php';
?>

<div class="insight-page" id="mobility-insights-page">

    <!-- Hero Deck -->
    <div class="insight-hero-deck" id="insights-hero">
        <div class="insight-hero-content">
            <div class="rider-kicker-pill">
                <span class="pulse-dot"></span>
                <span>Mobility Intelligence</span>
            </div>
            <h1>Mobility Insights</h1>
            <p>Live campus demand signals, popular corridors, fare savings predictions, and green pooling metrics for RideSync.</p>
        </div>
        <div class="insight-hero-actions">
            <a href="/ridesync/pages/search_rides.php" class="btn-secondary-glass" id="hero-btn-search">
                <svg class="ui-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                <span>Search Rides</span>
            </a>
            <a href="/ridesync/pages/post_ride.php" class="btn btn-primary btn-glow" id="hero-btn-post">
                <svg class="ui-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                <span>Offer Ride</span>
            </a>
        </div>
    </div>

    <?php ridesync_flash('match_success', 'alert-success'); ?>
    <?php ridesync_flash('match_error', 'alert-error'); ?>

    <!-- Metrics KPI Grid -->
    <section class="insight-metrics-grid" aria-label="Campus Mobility Key Performance Indicators">
        <div class="insight-stat-card stat-accent-blue" id="metric-open-rides">
            <div class="stat-header">
                <span class="stat-icon-wrapper icon-blue">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.5 2.8C2.05 10.9 2 11.2 2 11.5V16c0 .6.4 1 1 1h2"/><circle cx="7" cy="17" r="2"/><path d="M9 17h6"/><circle cx="17" cy="17" r="2"/></svg>
                </span>
                <span class="stat-label">Open Rides</span>
            </div>
            <strong class="stat-number"><?php echo (int) $openRides; ?></strong>
            <span class="stat-meta">Active routes ready to pool</span>
        </div>

        <div class="insight-stat-card stat-accent-amber" id="metric-active-demand">
            <div class="stat-header">
                <span class="stat-icon-wrapper icon-amber">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="2"/><path d="M16.24 7.76a6 6 0 0 1 0 8.49m-8.48-.01a6 6 0 0 1 0-8.49m11.31-2.82a10 10 0 0 1 0 14.14m-14.14 0a10 10 0 0 1 0-14.14"/></svg>
                </span>
                <span class="stat-label">Active Demand</span>
            </div>
            <strong class="stat-number"><?php echo (int) $activeDemand; ?></strong>
            <span class="stat-meta">Riders broadcasting radar alerts</span>
        </div>

        <div class="insight-stat-card stat-accent-purple" id="metric-shared-distance">
            <div class="stat-header">
                <span class="stat-icon-wrapper icon-purple">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg>
                </span>
                <span class="stat-label">Shared Distance</span>
            </div>
            <strong class="stat-number"><?php echo number_format($sharedKm, 1); ?> km</strong>
            <span class="stat-meta">Pooled travel distance</span>
        </div>

        <div class="insight-stat-card stat-accent-emerald" id="metric-co2-saved">
            <div class="stat-header">
                <span class="stat-icon-wrapper icon-emerald">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z"/><path d="M2 21c0-3 1.85-5.36 5.08-6C9.5 14.52 12 13 13 12"/></svg>
                </span>
                <span class="stat-label">CO2 Saved</span>
            </div>
            <strong class="stat-number"><?php echo number_format($co2Saved, 1); ?> kg</strong>
            <span class="stat-meta">Campus emissions prevented</span>
        </div>
    </section>

    <!-- Main Insights Grid -->
    <div class="insights-grid">

        <!-- Left Panel: Popular Routes (Campus Heatmap) -->
        <section class="insight-panel" id="panel-popular-routes">
            <div class="insight-panel-header">
                <div>
                    <span class="fare-kicker">Campus heatmap</span>
                    <h2>Popular routes</h2>
                </div>
                <a href="/ridesync/pages/search_rides.php" class="btn btn-secondary btn-sm" id="btn-explore-routes">
                    <svg class="ui-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                    <span>Search</span>
                </a>
            </div>

            <?php if (count($popularRoutes) === 0): ?>
                <div class="insight-empty-card" id="empty-popular-routes">
                    <div class="empty-icon-bubble">
                        <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"/></svg>
                    </div>
                    <h3>No route intelligence yet</h3>
                    <p>Corridor insights and heatmaps appear automatically as campus rides and demand signals are registered.</p>
                    <div class="empty-actions">
                        <a href="/ridesync/pages/post_ride.php" class="btn btn-primary btn-sm">Offer a Campus Ride</a>
                        <a href="/ridesync/pages/search_rides.php" class="btn btn-secondary btn-sm">Search Rides</a>
                    </div>
                </div>
            <?php else: ?>
                <div class="route-heat-list" id="popular-routes-list">
                    <?php foreach ($popularRoutes as $idx => $route): 
                        $searchUrl = '/ridesync/pages/search_rides.php?origin=' . urlencode($route['origin']) . '&destination=' . urlencode($route['destination']);
                        $postUrl = '/ridesync/pages/post_ride.php?origin=' . urlencode($route['origin']) . '&destination=' . urlencode($route['destination']);
                    ?>
                        <article class="route-heat-item insight-route-card" id="popular-route-<?php echo $idx; ?>">
                            <div class="route-heat-main">
                                <div class="route-heat-title">
                                    <span class="route-point" title="Origin">
                                        <span class="route-pin route-pin-start"></span>
                                        <?php echo ridesync_render_short_address($route['origin'], 26, 'short-address'); ?>
                                    </span>
                                    <span class="route-arrow-bridge" aria-hidden="true">&rarr;</span>
                                    <span class="route-point" title="Destination">
                                        <span class="route-pin route-pin-end"></span>
                                        <?php echo ridesync_render_short_address($route['destination'], 26, 'short-address'); ?>
                                    </span>
                                </div>
                                <div class="route-heat-meta-row">
                                    <span class="meta-chip meta-chip-rides">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.5 2.8C2.05 10.9 2 11.2 2 11.5V16c0 .6.4 1 1 1h2"/><circle cx="7" cy="17" r="2"/><path d="M9 17h6"/><circle cx="17" cy="17" r="2"/></svg>
                                        <?php echo (int) $route['rides']; ?> <?php echo (int) $route['rides'] === 1 ? 'ride' : 'rides'; ?>
                                    </span>
                                    <span class="meta-chip meta-chip-demand">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12h2"/><path d="M20 12h2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M12 2v2"/><path d="M12 20v2"/><circle cx="12" cy="12" r="4"/></svg>
                                        <?php echo (int) $route['demand']; ?> waiting
                                    </span>
                                    <?php if ((float) $route['avg_distance'] > 0): ?>
                                        <span class="meta-chip meta-chip-distance">
                                            ~<?php echo number_format((float) $route['avg_distance'], 1); ?> km
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="route-heat-side">
                                <div class="route-heat-score">
                                    <span class="score-label">Save up to</span>
                                    <strong class="score-value"><?php echo formatCost($route['estimated_savings']); ?></strong>
                                </div>
                                <div class="route-heat-actions">
                                    <a href="<?php echo htmlspecialchars($searchUrl); ?>" class="btn-primary-soft" title="Search rides on this corridor">
                                        Find Ride
                                    </a>
                                    <a href="<?php echo htmlspecialchars($postUrl); ?>" class="btn-secondary-soft" title="Offer a ride on this corridor">
                                        Offer
                                    </a>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <!-- Right Panel: Demand Signals (Your Route Radar) -->
        <section class="insight-panel" id="panel-demand-signals">
            <div class="insight-panel-header">
                <div>
                    <span class="fare-kicker">Your route radar</span>
                    <h2>Demand signals</h2>
                </div>
                <a href="/ridesync/pages/search_rides.php" class="btn btn-primary btn-sm" id="btn-create-signal">
                    <svg class="ui-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    <span>Create Signal</span>
                </a>
            </div>

            <?php if (count($myDemandSignals) === 0): ?>
                <div class="insight-empty-card" id="empty-demand-signals">
                    <div class="empty-icon-bubble bubble-radar">
                        <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="2"/><path d="M16.24 7.76a6 6 0 0 1 0 8.49m-8.48-.01a6 6 0 0 1 0-8.49m11.31-2.82a10 10 0 0 1 0 14.14m-14.14 0a10 10 0 0 1 0-14.14"/></svg>
                    </div>
                    <h3>No demand signals active</h3>
                    <p>Search a route and save an alert when no strong match exists. Drivers and riders will see your radar signal.</p>
                    <div class="empty-actions">
                        <a href="/ridesync/pages/search_rides.php" class="btn btn-primary btn-sm">Search &amp; Set Signal</a>
                    </div>
                </div>
            <?php else: ?>
                <div class="route-heat-list" id="demand-signals-list">
                    <?php foreach ($myDemandSignals as $sIdx => $signal): 
                        $statusClass = 'status-' . htmlspecialchars($signal['demand_status']);
                        $searchUrl = '/ridesync/pages/search_rides.php?origin=' . urlencode($signal['origin']) . '&destination=' . urlencode($signal['destination'])
                            . ($signal['travel_date'] ? '&travel_date=' . urlencode($signal['travel_date']) : '')
                            . ($signal['travel_time'] ? '&travel_time=' . urlencode($signal['travel_time']) : '');
                    ?>
                        <article class="route-heat-item insight-signal-card <?php echo $statusClass; ?>" id="demand-signal-<?php echo $sIdx; ?>">
                            <div class="route-heat-main">
                                <div class="route-heat-title">
                                    <span class="route-point" title="Origin">
                                        <span class="route-pin route-pin-start"></span>
                                        <?php echo ridesync_render_short_address($signal['origin'], 26, 'short-address'); ?>
                                    </span>
                                    <span class="route-arrow-bridge" aria-hidden="true">&rarr;</span>
                                    <span class="route-point" title="Destination">
                                        <span class="route-pin route-pin-end"></span>
                                        <?php echo ridesync_render_short_address($signal['destination'], 26, 'short-address'); ?>
                                    </span>
                                </div>
                                <div class="route-heat-meta-row">
                                    <span class="meta-chip meta-chip-time">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                        <?php echo $signal['travel_date'] ? date('M j, Y', strtotime($signal['travel_date'])) : 'Any date'; ?>
                                        <?php echo $signal['travel_time'] ? ' · ' . date('g:i A', strtotime($signal['travel_time'])) : ''; ?>
                                    </span>
                                    <span class="status-pill status-pill-<?php echo htmlspecialchars($signal['demand_status']); ?>">
                                        <?php if ($signal['demand_status'] === 'active'): ?>
                                            <span class="radar-dot"></span>
                                        <?php endif; ?>
                                        <?php echo ucfirst(htmlspecialchars($signal['demand_status'])); ?>
                                    </span>
                                </div>
                            </div>
                            <div class="route-signal-actions">
                                <a href="<?php echo htmlspecialchars($searchUrl); ?>" class="btn-secondary-soft" title="Check for new rides on this route">
                                    Check
                                </a>
                                <?php if ($signal['demand_status'] === 'active'): ?>
                                    <form method="POST" action="/ridesync/actions/demand_action.php" data-confirm-message="Cancel this route alert?" style="margin: 0;">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                        <input type="hidden" name="action_type" value="cancel_signal">
                                        <input type="hidden" name="signal_id" value="<?php echo (int) $signal['id']; ?>">
                                        <input type="hidden" name="return_to" value="/ridesync/pages/insights.php">
                                        <button type="submit" class="btn-danger-soft" title="Stop alert">
                                            Stop
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

    </div>

    <!-- Campus Eco Impact & Intelligence Showcase -->
    <section class="insight-eco-deck" id="insights-eco-deck" aria-label="Campus Environmental Impact">
        <div class="eco-deck-info">
            <span class="eco-kicker-pill">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z"/><path d="M2 21c0-3 1.85-5.36 5.08-6C9.5 14.52 12 13 13 12"/></svg>
                <span>Campus Eco Impact</span>
            </span>
            <h3>Smart Mobility &amp; Sustainability</h3>
            <p>Every shared ride removes single-occupancy vehicles from university parking, curbs campus congestion, and cuts atmospheric CO₂ emissions.</p>
            <div class="eco-stats-pills">
                <div class="eco-stat-pill">
                    <strong><?php echo number_format($co2Saved, 1); ?> kg</strong>
                    <span>CO₂ Prevented</span>
                </div>
                <div class="eco-stat-pill">
                    <strong>~<?php echo $fuelSavedLiters; ?> L</strong>
                    <span>Fuel Saved</span>
                </div>
                <div class="eco-stat-pill">
                    <strong>~<?php echo $treesEquivalent; ?></strong>
                    <span>Tree-Year Eq.</span>
                </div>
            </div>
        </div>
        <div class="eco-deck-shortcuts">
            <h4>Quick Campus Route Shortcuts</h4>
            <?php if (count($routeShortcuts) === 0): ?>
                <p style="margin: 0; font-size: 0.82rem; color: #a7f3d0;">Search campus routes above to build your frequent trip shortcuts.</p>
            <?php else: ?>
                <div class="shortcuts-chip-list">
                    <?php foreach ($routeShortcuts as $sc): 
                        $shortcutUrl = '/ridesync/pages/search_rides.php?origin=' . urlencode($sc['origin']) . '&destination=' . urlencode($sc['destination']);
                    ?>
                        <a href="<?php echo htmlspecialchars($shortcutUrl); ?>" class="shortcut-route-chip" title="Search <?php echo htmlspecialchars($sc['origin']); ?> to <?php echo htmlspecialchars($sc['destination']); ?>">
                            <span><?php echo htmlspecialchars($sc['short_origin']); ?> &rarr; <?php echo htmlspecialchars($sc['short_destination']); ?></span>
                            <small style="opacity: 0.8; font-size: 0.74rem;">Search &rarr;</small>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
