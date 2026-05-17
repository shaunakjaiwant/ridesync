<?php
require_once __DIR__ . '/config/db.php';

if (isset($_SESSION['user_id'])) {
    header("Location: /ridesync/pages/dashboard.php");
    exit();
}

if (isset($_SESSION['driver_id'])) {
    header("Location: /ridesync/pages/driver_dashboard.php");
    exit();
}

require_once 'includes/public_header.php';
?>

        <section class="public-landing">
            <section class="public-hero" aria-labelledby="publicHeroTitle">
                <div class="public-hero-copy">
                    <span class="public-kicker">Student ride coordination</span>
                    <h1 id="publicHeroTitle">RideSync keeps campus rides simple.</h1>
                    <p>Post a route, find a match, or connect with an available verified driver from one lightweight workspace.</p>
                    <div class="public-hero-actions">
                        <a href="/ridesync/pages/login.php?role=rider" class="btn btn-primary">Find a ride</a>
                        <a href="/ridesync/pages/driver_login.php" class="btn btn-secondary">Drive for RideSync</a>
                    </div>
                </div>

                <div class="public-route-preview" aria-label="RideSync route preview">
                    <div class="public-preview-brand">
                        <img src="/ridesync/logo-mark.png" alt="" class="logo-img" />
                        <div>
                            <strong>Live campus route</strong>
                            <span>Match, request, ride</span>
                        </div>
                    </div>
                    <div class="public-route-line" aria-hidden="true">
                        <span></span>
                        <i></i>
                        <span></span>
                    </div>
                    <div class="public-route-meta">
                        <span>SDMIT</span>
                        <strong>4 min</strong>
                        <span>Ujire</span>
                    </div>
                </div>
            </section>

            <section class="public-highlights" aria-label="RideSync highlights">
                <article>
                    <strong>Route matching</strong>
                    <span>Search by pickup, destination, time, and route fit.</span>
                </article>
                <article>
                    <strong>Driver fallback</strong>
                    <span>Use available drivers when shared rides are weak.</span>
                </article>
                <article>
                    <strong>Clear accounts</strong>
                    <span>Separate rider and driver workflows stay simple.</span>
                </article>
            </section>

            <section class="public-choice" aria-labelledby="publicChoiceTitle">
                <div class="page-header">
                    <span class="public-kicker">Choose workspace</span>
                    <h2 id="publicChoiceTitle">Start with the account you need.</h2>
                    <p>Riders and drivers use separate accounts so each panel stays focused.</p>
                </div>

                <div class="role-card-grid">
                    <a href="/ridesync/pages/login.php?role=rider" class="role-card rider-card">
                        <span class="role-icon">Ride</span>
                        <h2>Rider</h2>
                        <p>Find shared rides, send join requests, track notifications, and manage trips.</p>
                        <strong>Login or sign up</strong>
                    </a>

                    <a href="/ridesync/pages/driver_login.php" class="role-card driver-card">
                        <span class="role-icon">Drive</span>
                        <h2>Driver</h2>
                        <p>Go online, receive requests, claim posted rides, and keep earnings organized.</p>
                        <strong>Login or sign up</strong>
                    </a>
                </div>
            </section>
        </section>

<?php require_once 'includes/footer.php'; ?>
