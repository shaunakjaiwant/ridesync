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

<?php if ($ridesPosted === 0 && $requestsSent === 0): ?>
    <section class="quick-start-onboarding-banner" style="background: linear-gradient(135deg, #eff6ff 0%, #e0f2fe 100%); border: 1px solid #bae6fd; border-radius: 12px; padding: 1.5rem; margin-bottom: 1.75rem;">
        <div style="max-width: 600px; margin-bottom: 1rem;">
            <span style="font-size: 0.8rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #0284c7; display: flex; align-items: center; gap: 0.35rem;">
                <svg class="ui-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09z"/><path d="m12 15-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-3.05 11a22.35 22.35 0 0 1-3.95 2z"/><path d="M9 12H4s.55-3.03 2-4c1.62-1.08 5 0 5 0"/><path d="M12 15v5s3.03-.55 4-2c1.08-1.62 0-5 0-5"/></svg>
                Welcome to RideSync
            </span>
            <h2 style="font-size: 1.35rem; font-weight: 700; color: #0f172a; margin: 0.25rem 0 0.5rem 0;">Get your campus mobility moving</h2>
            <p style="font-size: 0.92rem; color: #334155; margin: 0; line-height: 1.5;">You haven't posted or requested any rides yet. Choose an action below or pick a popular campus route to get started.</p>
        </div>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem;">
            <a href="/ridesync/pages/search_rides.php" style="background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; padding: 1rem; text-decoration: none; color: inherit; display: flex; flex-direction: column; gap: 0.25rem;">
                <span style="font-size: 0.8rem; font-weight: 600; color: #2563eb; display: flex; align-items: center; gap: 0.35rem;">
                    Find a Ride
                    <svg class="ui-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                </span>
                <strong style="font-size: 1rem; color: #0f172a;">Search Campus Routes</strong>
                <small style="color: #64748b;">Find students heading your way right now.</small>
            </a>
            <a href="/ridesync/pages/post_ride.php" style="background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; padding: 1rem; text-decoration: none; color: inherit; display: flex; flex-direction: column; gap: 0.25rem;">
                <span style="font-size: 0.8rem; font-weight: 600; color: #059669; display: flex; align-items: center; gap: 0.35rem;">
                    Offer a Ride
                    <svg class="ui-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.5 2.8C2.05 10.9 2 11.2 2 11.5V16c0 .6.4 1 1 1h2"/><circle cx="7" cy="17" r="2"/><path d="M9 17h6"/><circle cx="17" cy="17" r="2"/></svg>
                </span>
                <strong style="font-size: 1rem; color: #0f172a;">Post Your First Ride</strong>
                <small style="color: #64748b;">Share your route and split travel costs.</small>
            </a>
            <a href="/ridesync/pages/post_ride.php?origin=SDMIT+Campus&destination=Ujire+Bus+Stand" style="background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; padding: 1rem; text-decoration: none; color: inherit; display: flex; flex-direction: column; gap: 0.25rem;">
                <span style="font-size: 0.8rem; font-weight: 600; color: #d97706; display: flex; align-items: center; gap: 0.35rem;">
                    Popular Route
                    <svg class="ui-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
                </span>
                <strong style="font-size: 1rem; color: #0f172a;">SDMIT &rarr; Ujire Bus Stand</strong>
                <small style="color: #64748b;">Pre-fill the most common campus route.</small>
            </a>
        </div>
    </section>
<?php endif; ?>

<nav class="panel-action-rail quick-actions" aria-label="Primary rider actions">
    <a class="panel-action-card is-primary" href="/ridesync/pages/post_ride.php">
        <span>Offer</span>
        <strong>Post Ride</strong>
        <small>Create a route.</small>
    </a>
    <a class="panel-action-card" href="/ridesync/pages/search_rides.php">
        <span>Find</span>
        <strong>Search Rides</strong>
        <small>Join an open route.</small>
    </a>
    <a class="panel-action-card" href="/ridesync/pages/my_rides.php">
        <span>Manage</span>
        <strong>My Trips</strong>
        <small>Review requests.</small>
    </a>
</nav>

<!-- Stats Cards -->
<div class="dashboard-stats">
    <div class="stat-box">
        <span class="stat-number"><?php echo $ridesPosted; ?></span>
        <span class="stat-label">Posted Trips</span>
        <small><?php echo $openRides; ?> open now</small>
    </div>
    <div class="stat-box">
        <span class="stat-number"><?php echo $requestsSent; ?></span>
        <span class="stat-label">Requests Sent</span>
    </div>
    <div class="stat-box stat-box-alert">
        <span class="stat-number"><?php echo $pendingIncoming; ?></span>
        <span class="stat-label">Need Approval</span>
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
            <ul class="mini-ride-list" style="list-style: none; padding: 0;">
                <?php while ($ride = mysqli_fetch_assoc($recentRides)): ?>
                    <li>
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
                    </li>
                <?php endwhile; ?>
            </ul>
            <a href="/ridesync/pages/my_rides.php" class="view-all-link">View all rides &rarr;</a>
        <?php endif; ?>
    </div>

    <!-- Right: Recent Match Requests -->
    <div class="dashboard-section">
        <h2>Your Recent Requests</h2>
        <?php if (mysqli_num_rows($recentMatches) === 0): ?>
            <p class="empty-state">No requests yet. <a href="/ridesync/pages/search_rides.php">Find a ride!</a></p>
        <?php else: ?>
            <ul class="mini-ride-list" style="list-style: none; padding: 0;">
                <?php while ($match = mysqli_fetch_assoc($recentMatches)): ?>
                    <li>
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
                    </li>
                <?php endwhile; ?>
            </ul>
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
