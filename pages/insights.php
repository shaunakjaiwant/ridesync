<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/cost_helper.php';
require_once __DIR__ . '/../includes/intelligence_helper.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: /ridesync/pages/login.php");
    exit();
}

$userId = (int) $_SESSION['user_id'];
$popularRoutes = ridesync_fetch_popular_routes($conn, 8);
$myDemandSignals = ridesync_fetch_user_demand_signals($conn, $userId, 6);
$greenScore = ridesync_green_score_for_user($conn, $userId);

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

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>Mobility Insights</h1>
    <p>Demand signals, popular routes, savings predictions, and green-score intelligence for RideSync.</p>
</div>

<?php ridesync_flash('match_success', 'alert-success'); ?>
<?php ridesync_flash('match_error', 'alert-error'); ?>

<section class="insight-metrics-grid">
    <div class="insight-metric">
        <span>Open Rides</span>
        <strong><?php echo (int) $openRides; ?></strong>
    </div>
    <div class="insight-metric">
        <span>Active Demand</span>
        <strong><?php echo (int) $activeDemand; ?></strong>
    </div>
    <div class="insight-metric">
        <span>Shared KM</span>
        <strong><?php echo number_format((float) $greenScore['shared_km'], 1); ?></strong>
    </div>
    <div class="insight-metric">
        <span>CO2 Saved</span>
        <strong><?php echo number_format((float) $greenScore['co2_kg'], 1); ?> kg</strong>
    </div>
</section>

<div class="insights-grid">
    <section class="insight-panel">
        <div class="insight-panel-header">
            <div>
                <span class="fare-kicker">Campus heatmap</span>
                <h2>Popular routes</h2>
            </div>
            <a href="/ridesync/pages/search_rides.php" class="btn btn-secondary btn-sm">Search</a>
        </div>

        <?php if (count($popularRoutes) === 0): ?>
            <p class="empty-state">No route intelligence yet. It appears as rides and demand signals are added.</p>
        <?php else: ?>
            <div class="route-heat-list">
                <?php foreach ($popularRoutes as $route): ?>
                    <article class="route-heat-item">
                        <div>
                            <strong><?php echo htmlspecialchars($route['origin']); ?> &rarr; <?php echo htmlspecialchars($route['destination']); ?></strong>
                            <span><?php echo (int) $route['rides']; ?> rides &middot; <?php echo (int) $route['demand']; ?> demand signals</span>
                        </div>
                        <div class="route-heat-score">
                            <span>Save up to</span>
                            <strong><?php echo formatCost($route['estimated_savings']); ?></strong>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="insight-panel">
        <div class="insight-panel-header">
            <div>
                <span class="fare-kicker">Your route radar</span>
                <h2>Demand signals</h2>
            </div>
            <a href="/ridesync/pages/search_rides.php" class="btn btn-primary btn-sm">Create Signal</a>
        </div>

        <?php if (count($myDemandSignals) === 0): ?>
            <p class="empty-state">No demand signals yet. Search a route and save it when no strong match exists.</p>
        <?php else: ?>
            <div class="route-heat-list">
                <?php foreach ($myDemandSignals as $signal): ?>
                    <article class="route-heat-item">
                        <div>
                            <strong><?php echo htmlspecialchars($signal['origin']); ?> &rarr; <?php echo htmlspecialchars($signal['destination']); ?></strong>
                            <span>
                                <?php echo $signal['travel_date'] ? date('M j', strtotime($signal['travel_date'])) : 'Any date'; ?>
                                <?php echo $signal['travel_time'] ? ' at ' . date('g:i A', strtotime($signal['travel_time'])) : ''; ?>
                            </span>
                        </div>
                        <div class="route-alert-actions">
                            <span class="status-badge status-<?php echo htmlspecialchars($signal['demand_status']); ?>"><?php echo ucfirst(htmlspecialchars($signal['demand_status'])); ?></span>
                            <?php if ($signal['demand_status'] === 'active'): ?>
                                <form method="POST" action="/ridesync/actions/demand_action.php" data-confirm-message="Cancel this route alert?">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                    <input type="hidden" name="action_type" value="cancel_signal">
                                    <input type="hidden" name="signal_id" value="<?php echo (int) $signal['id']; ?>">
                                    <input type="hidden" name="return_to" value="/ridesync/pages/insights.php">
                                    <button type="submit" class="btn btn-secondary btn-sm">Stop Alert</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
