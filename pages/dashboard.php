<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/cost_helper.php';
require_once __DIR__ . '/../includes/intelligence_helper.php';
require_once __DIR__ . '/../includes/wallet_helper.php';
require_once __DIR__ . '/../includes/eco_helper.php';

// Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: /ridesync/pages/login.php");
    exit;
}

require_once __DIR__ . '/../includes/header.php';

$userId = $_SESSION['user_id'];
$monthlyEco = ridesync_get_user_monthly_eco_impact($conn, (int) $userId);

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

<div class="rider-hero-deck">
    <div class="rider-hero-content">
        <div class="rider-kicker-pill">
            <span class="pulse-dot"></span>
            <span>Rider Workspace</span>
        </div>
        <h1>Welcome back, <?php echo htmlspecialchars($_SESSION['user_name']); ?> 👋</h1>
        <p>Coordinate campus trips, find verified route matches, and track your travel impact.</p>
    </div>
    <div class="rider-hero-action-slot">
        <a href="/ridesync/pages/post_ride.php" class="btn btn-primary btn-glow">
            <svg class="ui-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Offer New Ride
        </a>
    </div>
</div>

<?php if ($ridesPosted === 0 && $requestsSent === 0): ?>
    <section class="quick-start-onboarding-banner">
        <div class="onboarding-banner-info">
            <span class="onboarding-badge">
                <svg class="ui-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09z"/><path d="m12 15-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-3.05 11a22.35 22.35 0 0 1-3.95 2z"/><path d="M9 12H4s.55-3.03 2-4c1.62-1.08 5 0 5 0"/><path d="M12 15v5s3.03-.55 4-2c1.08-1.62 0-5 0-5"/></svg>
                Welcome to RideSync
            </span>
            <h2>Get your campus mobility moving</h2>
            <p>You haven't posted or requested any rides yet. Choose an action below or pick a popular campus route to get started.</p>
        </div>
        <div class="onboarding-cards-grid">
            <a href="/ridesync/pages/search_rides.php" class="onboarding-card">
                <span class="card-kicker kicker-blue">
                    Find a Ride
                    <svg class="ui-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                </span>
                <strong>Search Campus Routes</strong>
                <small>Find students heading your way right now.</small>
            </a>
            <a href="/ridesync/pages/post_ride.php" class="onboarding-card">
                <span class="card-kicker kicker-green">
                    Offer a Ride
                    <svg class="ui-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.5 2.8C2.05 10.9 2 11.2 2 11.5V16c0 .6.4 1 1 1h2"/><circle cx="7" cy="17" r="2"/><path d="M9 17h6"/><circle cx="17" cy="17" r="2"/></svg>
                </span>
                <strong>Post Your First Ride</strong>
                <small>Share your route and split travel costs.</small>
            </a>
            <a href="/ridesync/pages/post_ride.php?origin=SDMIT+Campus&destination=Ujire+Bus+Stand" class="onboarding-card">
                <span class="card-kicker kicker-amber">
                    Popular Route
                    <svg class="ui-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
                </span>
                <strong>SDMIT &rarr; Ujire Bus Stand</strong>
                <small>Pre-fill the most common campus route.</small>
            </a>
        </div>
    </section>
<?php endif; ?>

<nav class="panel-action-rail quick-actions" aria-label="Primary rider actions">
    <a class="panel-action-card is-primary" href="/ridesync/pages/post_ride.php">
        <div class="action-card-header">
            <span class="action-icon-badge">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
            </span>
            <span>Offer</span>
        </div>
        <strong>Post Ride</strong>
        <small>Share a route & split costs.</small>
    </a>
    <a class="panel-action-card" href="/ridesync/pages/search_rides.php">
        <div class="action-card-header">
            <span class="action-icon-badge accent-blue">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            </span>
            <span>Find</span>
        </div>
        <strong>Search Rides</strong>
        <small>Join an open student trip.</small>
    </a>
    <a class="panel-action-card" href="/ridesync/pages/my_rides.php">
        <div class="action-card-header">
            <span class="action-icon-badge accent-purple">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            </span>
            <span>Manage</span>
        </div>
        <strong>My Trips</strong>
        <small>Review matches & requests.</small>
    </a>
</nav>

<!-- Stats Deck -->
<div class="dashboard-stats">
    <div class="stat-box stat-accent-blue">
        <div class="stat-header">
            <span class="stat-icon-wrapper">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.5 2.8C2.05 10.9 2 11.2 2 11.5V16c0 .6.4 1 1 1h2"/><circle cx="7" cy="17" r="2"/><path d="M9 17h6"/><circle cx="17" cy="17" r="2"/></svg>
            </span>
            <span class="stat-label">Posted Trips</span>
        </div>
        <span class="stat-number"><?php echo $ridesPosted; ?></span>
        <small><?php echo $openRides; ?> open right now</small>
    </div>
    <div class="stat-box stat-accent-cyan">
        <div class="stat-header">
            <span class="stat-icon-wrapper">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4 20-7z"/></svg>
            </span>
            <span class="stat-label">Requests Sent</span>
        </div>
        <span class="stat-number"><?php echo $requestsSent; ?></span>
        <small>Outbound match queries</small>
    </div>
    <div class="stat-box stat-accent-amber stat-box-alert">
        <div class="stat-header">
            <span class="stat-icon-wrapper">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
            </span>
            <span class="stat-label">Need Approval</span>
        </div>
        <span class="stat-number"><?php echo $pendingIncoming; ?></span>
        <small>Incoming rider requests</small>
    </div>
    <div class="stat-box stat-accent-emerald">
        <div class="stat-header">
            <span class="stat-icon-wrapper">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
            </span>
            <span class="stat-label">Fare Due</span>
        </div>
        <span class="stat-number"><?php echo formatCost($walletSummary['pending_due']); ?></span>
        <small>Settlements pending</small>
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
                <strong><?php echo number_format((float) max((float) $greenScore['co2_kg'], (float) $monthlyEco['co2_saved_kg']), 1); ?> kg</strong>
                <span>Estimated CO2 saved</span>
            </div>
        </div>
        <p style="font-size: 0.85rem; color: #059669; font-weight: 600; margin-top: 0.5rem; background: #ecfdf5; padding: 0.5rem 0.75rem; border-radius: 6px; border: 1px solid #a7f3d0;">
            <svg class="ui-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align: middle; margin-right: 0.25rem;"><path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z"/><path d="M2 21c0-3 1.85-5.36 5.08-6C9.5 14.52 12 13 13 12"/></svg>
            You've saved an estimated <?php echo number_format((float) max((float) $greenScore['co2_kg'], (float) $monthlyEco['co2_saved_kg']), 1); ?> kg CO2 this month by sharing rides!
        </p>

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
