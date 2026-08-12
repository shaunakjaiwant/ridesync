<?php
define('RIDESYNC_ALLOW_DB_FAILURE', true);
require_once __DIR__ . '/config/db.php';

if ($conn && isset($_SESSION['user_id'])) {
    header("Location: /ridesync/pages/dashboard.php");
    exit();
}

if ($conn && isset($_SESSION['driver_id'])) {
    header("Location: /ridesync/pages/driver_dashboard.php");
    exit();
}

require_once 'includes/public_header.php';
?>

<section class="public-landing public-vibe-landing">
    <section class="public-hero public-vibe-hero" aria-labelledby="publicHeroTitle">
        <div class="public-hero-scene" aria-hidden="true">
            <img src="/ridesync/logo.png" alt="" class="public-scene-logo" />
            <div class="public-scene-map">
                <span class="public-map-node is-origin"></span>
                <span class="public-map-node is-campus"></span>
                <span class="public-map-node is-destination"></span>
                <span class="public-map-route"></span>
            </div>
            <div class="public-scene-card public-scene-card-primary">
                <span>Rider request</span>
                <strong>DEPT to DEST</strong>
                <small>2 seats matched</small>
            </div>
            <div class="public-scene-card public-scene-card-secondary">
                <span>Driver fallback</span>
                <strong>Available nearby</strong>
                <small>Verified account</small>
            </div>
            <div class="public-scene-status">
                <span></span>
                <strong>Live route sync</strong>
            </div>
        </div>

        <div class="public-hero-inner">
            <div class="public-hero-copy">
                <span class="public-kicker">Campus mobility, cleaned up</span>
                <h1 id="publicHeroTitle">RideSync turns scattered rides into one coordinated flow.</h1>
                <p>Post trips, find route matches, request verified drivers, and keep every ride moving from one focused workspace.</p>
                <div class="public-hero-actions">
                    <a href="/ridesync/pages/login.php?role=rider" class="btn btn-primary">Find a ride</a>
                    <a href="/ridesync/pages/driver_login.php" class="btn btn-secondary">Drive with RideSync</a>
                </div>
            </div>

            <div class="public-hero-panel" aria-label="RideSync live route preview">
                <div class="public-preview-brand">
                    <img src="/ridesync/logo-mark.png" alt="" class="logo-img" />
                    <div>
                        <strong>Campus route board</strong>
                        <span>Match, request, ride</span>
                    </div>
                </div>
                <div class="public-route-line" aria-hidden="true">
                    <span></span>
                    <i></i>
                    <span></span>
                </div>
                <div class="public-route-meta">
                    <span>DEPT</span>
                    <strong>Live</strong>
                    <span>DEST</span>
                </div>
                <dl class="public-hero-metrics">
                    <div>
                        <dt>Route fit</dt>
                        <dd>High</dd>
                    </div>
                    <div>
                        <dt>Fallback</dt>
                        <dd>Ready</dd>
                    </div>
                </dl>
            </div>
        </div>
    </section>

    <section class="public-highlights public-vibe-highlights" aria-label="RideSync highlights">
        <article>
            <span>01</span>
            <strong>Route matching</strong>
            <p>Search by pickup, destination, time, seats, and route fit before sending a request.</p>
        </article>
        <article>
            <span>02</span>
            <strong>Verified driver fallback</strong>
            <p>When shared rides are weak, RideSync can route the request toward available drivers.</p>
        </article>
        <article>
            <span>03</span>
            <strong>Operational visibility</strong>
            <p>Notifications, live status, reports, and admin oversight keep the system accountable.</p>
        </article>
    </section>

    <section class="public-choice public-vibe-choice" aria-labelledby="publicChoiceTitle">
        <div class="page-header">
            <span class="public-kicker">Choose workspace</span>
            <h2 id="publicChoiceTitle">Start with the side of RideSync you need.</h2>
            <p>Rider and driver flows stay separate so each dashboard stays fast, focused, and useful.</p>
        </div>

        <div class="role-card-grid public-role-grid">
            <a href="/ridesync/pages/login.php?role=rider" class="role-card rider-card">
                <span class="role-icon">Rider</span>
                <h2>Find or post a ride</h2>
                <p>Discover shared routes, send join requests, track notifications, and manage upcoming trips.</p>
                <strong>Open rider workspace &rarr;</strong>
            </a>

            <a href="/ridesync/pages/driver_login.php" class="role-card driver-card">
                <span class="role-icon">Driver</span>
                <h2>Accept campus requests</h2>
                <p>Go online, receive direct requests, claim posted rides, and keep earnings organized.</p>
                <strong>Open driver workspace &rarr;</strong>
            </a>
        </div>
    </section>

    <section class="public-flow" aria-labelledby="publicFlowTitle">
        <div>
            <span class="public-kicker">How it moves</span>
            <h2 id="publicFlowTitle">A cleaner loop for everyday campus travel.</h2>
        </div>
        <ol>
            <li>
                <strong>Plan</strong>
                <span>Set pickup, destination, date, time, seats, and route details.</span>
            </li>
            <li>
                <strong>Coordinate</strong>
                <span>RideSync compares matches, requests, driver availability, and notifications.</span>
            </li>
            <li>
                <strong>Complete</strong>
                <span>Track ride status, manage reports, and keep trip history organized.</span>
            </li>
        </ol>
    </section>
</section>

<?php require_once 'includes/footer.php'; ?>
