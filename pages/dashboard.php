<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/cost_helper.php';
require_once __DIR__ . '/../includes/intelligence_helper.php';
require_once __DIR__ . '/../includes/wallet_helper.php';

// Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: /ridesync/pages/login.php");
    exit;
}

require_once __DIR__ . '/../includes/header.php';

$userId = $_SESSION['user_id'];

// ---- FETCH STATS ----

// Dashboard counters in one round trip instead of four separate requests.
$stmt = mysqli_prepare($conn,
    "SELECT
        (SELECT COUNT(*) FROM rides WHERE user_id = ?) AS rides_posted,
        (SELECT COUNT(*) FROM rides WHERE user_id = ? AND status = 'open') AS open_rides,
        (SELECT COUNT(*) FROM matches WHERE matched_user_id = ?) AS requests_sent,
        (SELECT COUNT(*)
         FROM matches m
         JOIN rides r ON m.ride_id = r.id
         WHERE r.user_id = ? AND m.status = 'pending') AS pending_incoming"
);
mysqli_stmt_bind_param($stmt, "iiii", $userId, $userId, $userId, $userId);
mysqli_stmt_execute($stmt);
$dashboardStats = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: [];
$ridesPosted = (int) ($dashboardStats['rides_posted'] ?? 0);
$openRides = (int) ($dashboardStats['open_rides'] ?? 0);
$requestsSent = (int) ($dashboardStats['requests_sent'] ?? 0);
$pendingIncoming = (int) ($dashboardStats['pending_incoming'] ?? 0);

// ---- RECENT RIDES POSTED BY USER ----
$stmt = mysqli_prepare($conn,
    "SELECT id, origin, destination, travel_date, travel_time, seats_available, status
     FROM rides WHERE user_id = ? ORDER BY created_at DESC LIMIT 5"
);
mysqli_stmt_bind_param($stmt, "i", $userId);
mysqli_stmt_execute($stmt);
$recentRides = mysqli_stmt_get_result($stmt);

// ---- RECENT MATCH REQUESTS SENT BY USER ----
$stmt = mysqli_prepare($conn,
    "SELECT m.status AS match_status, r.origin, r.destination, r.travel_date, r.id AS ride_id,
            u.name AS poster_name
     FROM matches m
     JOIN rides r ON m.ride_id = r.id
     JOIN users u ON r.user_id = u.id
     WHERE m.matched_user_id = ?
     ORDER BY m.created_at DESC LIMIT 5"
);
mysqli_stmt_bind_param($stmt, "i", $userId);
mysqli_stmt_execute($stmt);
$recentMatches = mysqli_stmt_get_result($stmt);

$popularRoutes = ridesync_fetch_popular_routes($conn, 3);
$myDemandSignals = ridesync_fetch_user_demand_signals($conn, (int) $userId, 3);
$greenScore = ridesync_green_score_for_user($conn, (int) $userId);
$walletSummary = ridesync_wallet_summary($conn, (int) $userId);
?>

<div class="page-header">
    <h1>Welcome back, <?php echo htmlspecialchars($_SESSION['user_name']); ?></h1>
    <p>Your current rides, requests, and route signals.</p>
</div>

<nav class="panel-action-rail quick-actions" aria-label="Primary rider actions">
    <a class="panel-action-card is-primary" href="/ridesync/pages/post_ride.php">
        <span>Offer seats</span>
        <strong>Post Ride</strong>
        <small>Create a route for others to join.</small>
    </a>
    <a class="panel-action-card" href="/ridesync/pages/search_rides.php">
        <span>Find movement</span>
        <strong>Search Rides</strong>
        <small>Match with open campus routes.</small>
    </a>
    <a class="panel-action-card" href="/ridesync/pages/my_rides.php">
        <span>Manage</span>
        <strong>My Trips</strong>
        <small>Review requests and live status.</small>
    </a>
    <a class="panel-action-card" href="/ridesync/pages/insights.php">
        <span>Signals</span>
        <strong>Insights</strong>
        <small>See route demand and savings.</small>
    </a>
</nav>

<!-- Stats Cards -->
<div class="dashboard-stats">
    <div class="stat-box">
        <span class="stat-number"><?php echo $ridesPosted; ?></span>
        <span class="stat-label">Rides Posted</span>
    </div>
    <div class="stat-box">
        <span class="stat-number"><?php echo $openRides; ?></span>
        <span class="stat-label">Open Now</span>
    </div>
    <div class="stat-box">
        <span class="stat-number"><?php echo $requestsSent; ?></span>
        <span class="stat-label">Requests Sent</span>
    </div>
    <div class="stat-box stat-box-alert">
        <span class="stat-number"><?php echo $pendingIncoming; ?></span>
        <span class="stat-label">Awaiting Your Approval</span>
    </div>
    <div class="stat-box">
        <span class="stat-number"><?php echo formatCost($walletSummary['pending_due']); ?></span>
        <span class="stat-label">Fare Due</span>
    </div>
</div>

<!-- Two-column layout -->
<div class="dashboard-grid">

    <!-- Left: Recent Rides -->
    <div class="dashboard-section">
        <h2>Your Recent Rides</h2>
        <?php if (mysqli_num_rows($recentRides) === 0): ?>
            <p class="empty-state">No rides posted yet. <a href="/ridesync/pages/post_ride.php">Post one!</a></p>
        <?php else: ?>
            <div class="mini-ride-list">
                <?php while ($ride = mysqli_fetch_assoc($recentRides)): ?>
                    <a href="/ridesync/pages/ride_detail.php?id=<?php echo $ride['id']; ?>" class="mini-ride-item">
                        <div class="mini-ride-route">
                            <strong><?php echo htmlspecialchars($ride['origin']); ?></strong>
                            <span class="arrow">&rarr;</span>
                            <strong><?php echo htmlspecialchars($ride['destination']); ?></strong>
                        </div>
                        <div class="mini-ride-meta">
                            <span><?php echo date('M j', strtotime($ride['travel_date'])); ?> at <?php echo date('g:i A', strtotime($ride['travel_time'])); ?></span>
                            <span class="badge badge-<?php echo $ride['status']; ?>">
                                <?php echo ucfirst($ride['status']); ?>
                            </span>
                        </div>
                    </a>
                <?php endwhile; ?>
            </div>
            <a href="/ridesync/pages/my_rides.php" class="view-all-link">View all rides &rarr;</a>
        <?php endif; ?>
    </div>

    <!-- Right: Recent Match Requests -->
    <div class="dashboard-section">
        <h2>Your Recent Requests</h2>
        <?php if (mysqli_num_rows($recentMatches) === 0): ?>
            <p class="empty-state">No requests yet. <a href="/ridesync/pages/search_rides.php">Find a ride!</a></p>
        <?php else: ?>
            <div class="mini-ride-list">
                <?php while ($match = mysqli_fetch_assoc($recentMatches)): ?>
                    <a href="/ridesync/pages/ride_detail.php?id=<?php echo $match['ride_id']; ?>" class="mini-ride-item">
                        <div class="mini-ride-route">
                            <strong><?php echo htmlspecialchars($match['origin']); ?></strong>
                            <span class="arrow">&rarr;</span>
                            <strong><?php echo htmlspecialchars($match['destination']); ?></strong>
                        </div>
                        <div class="mini-ride-meta">
                            <span>By <?php echo htmlspecialchars($match['poster_name']); ?></span>
                            <span class="badge badge-<?php echo $match['match_status']; ?>">
                                <?php echo ucfirst($match['match_status']); ?>
                            </span>
                        </div>
                    </a>
                <?php endwhile; ?>
            </div>
            <a href="/ridesync/pages/my_matches.php" class="view-all-link">View all requests &rarr;</a>
        <?php endif; ?>
    </div>

</div>

<!-- Intelligence Layer -->
<section class="dashboard-intelligence">
    <div class="dashboard-section">
        <div class="insight-panel-header">
            <div>
                <span class="fare-kicker">RideSync intelligence</span>
                <h2>Route opportunities</h2>
            </div>
            <a href="/ridesync/pages/insights.php" class="btn btn-secondary btn-sm">Insights</a>
        </div>

        <?php if (count($popularRoutes) === 0): ?>
            <p class="empty-state">Popular routes appear after rides or demand signals are created.</p>
        <?php else: ?>
            <div class="route-heat-list compact">
                <?php foreach ($popularRoutes as $route): ?>
                    <article class="route-heat-item">
                        <div>
                            <strong><?php echo htmlspecialchars($route['origin']); ?> &rarr; <?php echo htmlspecialchars($route['destination']); ?></strong>
                            <span><?php echo (int) $route['rides']; ?> rides &middot; <?php echo (int) $route['demand']; ?> waiting</span>
                        </div>
                        <div class="route-heat-score">
                            <span>Potential save</span>
                            <strong><?php echo formatCost($route['estimated_savings']); ?></strong>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="dashboard-section">
        <div class="insight-panel-header">
            <div>
                <span class="fare-kicker">Your impact</span>
                <h2>Green score</h2>
            </div>
        </div>
        <div class="green-score-card">
            <div>
                <strong><?php echo number_format((float) $greenScore['shared_km'], 1); ?> km</strong>
                <span>Shared distance</span>
            </div>
            <div>
                <strong><?php echo number_format((float) $greenScore['co2_kg'], 1); ?> kg</strong>
                <span>Estimated CO2 saved</span>
            </div>
        </div>

        <?php if (count($myDemandSignals) > 0): ?>
            <div class="route-demand-mini">
                <h3>Your active demand</h3>
                <?php foreach ($myDemandSignals as $signal): ?>
                    <p><?php echo htmlspecialchars($signal['origin']); ?> &rarr; <?php echo htmlspecialchars($signal['destination']); ?></p>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="empty-state">No demand signals yet. Search a route and tap Notify Me.</p>
        <?php endif; ?>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
