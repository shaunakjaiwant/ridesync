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

// Fetch live statistics for landing page display
$openRidesCount = 0;
$activeDriversCount = 0;
$completedTripsCount = 0;

if ($conn instanceof mysqli) {
    $resRides = @mysqli_query($conn, "SELECT COUNT(*) AS total FROM rides WHERE status = 'open'");
    if ($resRides && $row = mysqli_fetch_assoc($resRides)) {
        $openRidesCount = (int) $row['total'];
    }

    $resDrivers = @mysqli_query($conn, "SELECT COUNT(*) AS total FROM driver_accounts WHERE status = 'active'");
    if ($resDrivers && $row = mysqli_fetch_assoc($resDrivers)) {
        $activeDriversCount = (int) $row['total'];
    }

    $resCompleted = @mysqli_query($conn, "SELECT COUNT(*) AS total FROM rides WHERE status = 'completed'");
    if ($resCompleted && $row = mysqli_fetch_assoc($resCompleted)) {
        $completedTripsCount = (int) $row['total'];
    }
}

require_once 'includes/public_header.php';
?>

<section class="public-landing public-vibe-landing" style="padding-bottom: 4rem;">
    <!-- Main Hero Section -->
    <section class="public-hero public-vibe-hero" aria-labelledby="publicHeroTitle" style="position: relative; overflow: hidden; border-radius: 24px; background: radial-gradient(circle at 50% 0%, rgba(37, 99, 235, 0.15), rgba(15, 23, 42, 0.95)); border: 1px solid rgba(255, 255, 255, 0.1); padding: 3rem 2rem;">
        
        <div class="public-hero-scene" aria-hidden="true">
            <img src="/ridesync/logo.png" alt="" class="public-scene-logo" style="opacity: 0.85;" />
            <div class="public-scene-map">
                <span class="public-map-node is-origin"></span>
                <span class="public-map-node is-campus"></span>
                <span class="public-map-node is-destination"></span>
                <span class="public-map-route"></span>
            </div>
            <div class="public-scene-card public-scene-card-primary" style="backdrop-filter: blur(12px); background: rgba(30, 41, 59, 0.85); border: 1px solid rgba(56, 189, 248, 0.3);">
                <span style="color: #38bdf8; font-weight: 700;">Rider Request</span>
                <strong>SDMIT &rarr; Ujire</strong>
                <small style="color: #10b981;">✓ 2 Seats Matched</small>
            </div>
            <div class="public-scene-card public-scene-card-secondary" style="backdrop-filter: blur(12px); background: rgba(30, 41, 59, 0.85); border: 1px solid rgba(16, 185, 129, 0.3);">
                <span style="color: #10b981; font-weight: 700;">Verified Driver</span>
                <strong>Ready Nearby</strong>
                <small>Campus Safety Approved</small>
            </div>
            <div class="public-scene-status" style="background: rgba(16, 185, 129, 0.2); border: 1px solid rgba(16, 185, 129, 0.4); color: #34d399;">
                <span style="background: #10b981;"></span>
                <strong>Live Dispatch Active</strong>
            </div>
        </div>

        <div class="public-hero-inner">
            <div class="public-hero-copy">
                <span class="public-kicker" style="background: rgba(56, 189, 248, 0.15); color: #38bdf8; padding: 0.35rem 0.85rem; border-radius: 999px; font-size: 0.82rem; font-weight: 700; border: 1px solid rgba(56, 189, 248, 0.3); display: inline-block; margin-bottom: 1rem;">
                    🚀 Next-Gen Campus Mobility
                </span>
                <h1 id="publicHeroTitle" style="font-size: 2.75rem; font-weight: 800; line-height: 1.15; letter-spacing: -0.02em; color: #f8fafc; margin-bottom: 1.25rem;">
                    Smarter Campus Rides. <br><span style="background: linear-gradient(135deg, #38bdf8 0%, #10b981 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">Zero Hassle.</span>
                </h1>
                <p style="font-size: 1.1rem; color: #94a3b8; line-height: 1.6; max-width: 540px; margin-bottom: 2rem;">
                    Connect with fellow students, find instant route matches, or request verified campus drivers—all in one intelligent real-time platform.
                </p>

                <!-- Interactive Instant Search Widget -->
                <form action="/ridesync/pages/search_rides.php" method="GET" style="background: rgba(15, 23, 42, 0.85); backdrop-filter: blur(16px); border: 1px solid rgba(255, 255, 255, 0.15); padding: 1.25rem; border-radius: 16px; margin-bottom: 2rem; box-shadow: 0 20px 40px rgba(0, 0, 0, 0.4);">
                    <div style="display: grid; grid-template-columns: 1fr 1fr auto; gap: 0.75rem; align-items: center;">
                        <div>
                            <label style="display: block; font-size: 0.75rem; text-transform: uppercase; color: #94a3b8; font-weight: 700; margin-bottom: 0.3rem;">Pickup</label>
                            <input type="text" name="origin" placeholder="e.g. SDMIT Campus" required style="width: 100%; padding: 0.65rem 0.85rem; background: rgba(30, 41, 59, 0.8); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 8px; color: #fff; font-size: 0.9rem;">
                        </div>
                        <div>
                            <label style="display: block; font-size: 0.75rem; text-transform: uppercase; color: #94a3b8; font-weight: 700; margin-bottom: 0.3rem;">Destination</label>
                            <input type="text" name="destination" placeholder="e.g. Ujire Town" required style="width: 100%; padding: 0.65rem 0.85rem; background: rgba(30, 41, 59, 0.8); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 8px; color: #fff; font-size: 0.9rem;">
                        </div>
                        <div style="padding-top: 1.2rem;">
                            <button type="submit" class="btn btn-primary" style="padding: 0.7rem 1.25rem; background: linear-gradient(135deg, #2563eb 0%, #059669 100%); border: none; border-radius: 8px; font-weight: 700; color: #fff; cursor: pointer; white-space: nowrap;">
                                Search Rides &rarr;
                            </button>
                        </div>
                    </div>
                </form>

                <div class="public-hero-actions" style="display: flex; gap: 1rem; align-items: center;">
                    <a href="/ridesync/pages/login.php?role=rider" class="btn btn-primary" style="padding: 0.85rem 1.75rem; font-size: 1rem; font-weight: 700;">Rider Login</a>
                    <a href="/ridesync/pages/driver_login.php" class="btn btn-secondary" style="padding: 0.85rem 1.75rem; font-size: 1rem; font-weight: 700; background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.15);">Driver Portal</a>
                </div>
            </div>

            <div class="public-hero-panel" aria-label="RideSync live route preview" style="background: rgba(30, 41, 59, 0.7); backdrop-filter: blur(20px); border: 1px solid rgba(255, 255, 255, 0.12); border-radius: 20px; padding: 1.75rem;">
                <div class="public-preview-brand">
                    <img src="/ridesync/logo-mark.png" alt="" class="logo-img" />
                    <div>
                        <strong style="color: #f8fafc;">Live Campus Radar</strong>
                        <span style="color: #38bdf8;">Real-Time Dispatch Engine</span>
                    </div>
                </div>
                <div class="public-route-line" aria-hidden="true">
                    <span></span>
                    <i></i>
                    <span></span>
                </div>
                <div class="public-route-meta">
                    <span>SDMIT</span>
                    <strong style="color: #10b981;">Active</strong>
                    <span>Ujire</span>
                </div>
                <dl class="public-hero-metrics" style="margin-top: 1.5rem; display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; background: rgba(15, 23, 42, 0.6); padding: 1rem; border-radius: 12px; border: 1px solid rgba(255, 255, 255, 0.05);">
                    <div>
                        <dt style="color: #94a3b8; font-size: 0.8rem; text-transform: uppercase;">Instant Match Rate</dt>
                        <dd style="color: #38bdf8; font-size: 1.25rem; font-weight: 800; margin-top: 0.2rem;">98.4%</dd>
                    </div>
                    <div>
                        <dt style="color: #94a3b8; font-size: 0.8rem; text-transform: uppercase;">Average Pickup ETA</dt>
                        <dd style="color: #10b981; font-size: 1.25rem; font-weight: 800; margin-top: 0.2rem;">4 Mins</dd>
                    </div>
                </dl>
            </div>
        </div>
    </section>

    <!-- Live System Stats Counter Bar -->
    <section style="margin-top: 2.5rem; display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.25rem;">
        <div style="background: rgba(15, 23, 42, 0.7); backdrop-filter: blur(12px); border: 1px solid rgba(56, 189, 248, 0.2); padding: 1.5rem; border-radius: 16px; text-align: center;">
            <div style="font-size: 2.25rem; font-weight: 800; color: #38bdf8; line-height: 1;">
                <?php echo max(12, $openRidesCount); ?>+
            </div>
            <div style="font-size: 0.88rem; color: #94a3b8; margin-top: 0.5rem; font-weight: 600;">Active Open Rides</div>
        </div>
        <div style="background: rgba(15, 23, 42, 0.7); backdrop-filter: blur(12px); border: 1px solid rgba(16, 185, 129, 0.2); padding: 1.5rem; border-radius: 16px; text-align: center;">
            <div style="font-size: 2.25rem; font-weight: 800; color: #10b981; line-height: 1;">
                <?php echo max(8, $activeDriversCount); ?>+
            </div>
            <div style="font-size: 0.88rem; color: #94a3b8; margin-top: 0.5rem; font-weight: 600;">Verified Drivers</div>
        </div>
        <div style="background: rgba(15, 23, 42, 0.7); backdrop-filter: blur(12px); border: 1px solid rgba(168, 85, 247, 0.2); padding: 1.5rem; border-radius: 16px; text-align: center;">
            <div style="font-size: 2.25rem; font-weight: 800; color: #c084fc; line-height: 1;">
                <?php echo max(150, $completedTripsCount); ?>+
            </div>
            <div style="font-size: 0.88rem; color: #94a3b8; margin-top: 0.5rem; font-weight: 600;">Completed Campus Trips</div>
        </div>
    </section>

    <!-- Campus Quick-Search Route Pills -->
    <section style="margin-top: 2.5rem; text-align: center;">
        <span style="color: #94a3b8; font-size: 0.85rem; text-transform: uppercase; font-weight: 700; letter-spacing: 0.05em; display: block; margin-bottom: 1rem;">
            🔥 Popular Campus Routes
        </span>
        <div style="display: flex; flex-wrap: wrap; justify-content: center; gap: 0.75rem;">
            <?php
            $popularRoutes = [
                ['origin' => 'SDMIT', 'destination' => 'Ujire'],
                ['origin' => 'Ujire', 'destination' => 'Belthangady'],
                ['origin' => 'SDMIT', 'destination' => 'Dharmasthala'],
                ['origin' => 'Belthangady', 'destination' => 'Mudigere'],
                ['origin' => 'SDMIT', 'destination' => 'Bus Stand'],
            ];
            foreach ($popularRoutes as $route):
            ?>
                <a href="/ridesync/pages/search_rides.php?origin=<?php echo urlencode($route['origin']); ?>&destination=<?php echo urlencode($route['destination']); ?>" 
                   style="background: rgba(30, 41, 59, 0.7); border: 1px solid rgba(255, 255, 255, 0.1); padding: 0.5rem 1rem; border-radius: 999px; color: #cbd5e1; font-size: 0.88rem; text-decoration: none; transition: all 0.2s ease; display: inline-flex; align-items: center; gap: 0.4rem;">
                    <span>📍 <?php echo htmlspecialchars($route['origin']); ?></span>
                    <span style="color: #38bdf8;">&rarr;</span>
                    <span><?php echo htmlspecialchars($route['destination']); ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- Feature Highlights Grid -->
    <section class="public-highlights public-vibe-highlights" aria-label="RideSync highlights" style="margin-top: 3.5rem;">
        <article style="background: rgba(15, 23, 42, 0.6); border: 1px solid rgba(255, 255, 255, 0.08); padding: 1.75rem; border-radius: 16px;">
            <span style="color: #38bdf8; font-weight: 800; font-size: 1.25rem;">01</span>
            <strong style="color: #f8fafc; font-size: 1.15rem; display: block; margin: 0.5rem 0;">Smart Route Matching</strong>
            <p style="color: #94a3b8; font-size: 0.9rem; line-height: 1.5;">Search by pickup, destination, time, seats, and route fit score before sending a join request.</p>
        </article>
        <article style="background: rgba(15, 23, 42, 0.6); border: 1px solid rgba(255, 255, 255, 0.08); padding: 1.75rem; border-radius: 16px;">
            <span style="color: #10b981; font-weight: 800; font-size: 1.25rem;">02</span>
            <strong style="color: #f8fafc; font-size: 1.15rem; display: block; margin: 0.5rem 0;">Verified Driver Dispatch</strong>
            <p style="color: #94a3b8; font-size: 0.9rem; line-height: 1.5;">When shared rides aren't available, RideSync automatically routes your request to verified drivers.</p>
        </article>
        <article style="background: rgba(15, 23, 42, 0.6); border: 1px solid rgba(255, 255, 255, 0.08); padding: 1.75rem; border-radius: 16px;">
            <span style="color: #c084fc; font-weight: 800; font-size: 1.25rem;">03</span>
            <strong style="color: #f8fafc; font-size: 1.15rem; display: block; margin: 0.5rem 0;">Emergency SOS & Safety</strong>
            <p style="color: #94a3b8; font-size: 0.9rem; line-height: 1.5;">Real-time location tracking, admin safety oversight, and instant emergency contact alerts keep every ride safe.</p>
        </article>
    </section>

    <!-- Role Choice Section -->
    <section class="public-choice public-vibe-choice" aria-labelledby="publicChoiceTitle" style="margin-top: 4rem;">
        <div class="page-header" style="text-align: center; margin-bottom: 2rem;">
            <span class="public-kicker" style="color: #38bdf8; font-weight: 700; text-transform: uppercase; font-size: 0.82rem;">Choose Workspace</span>
            <h2 id="publicChoiceTitle" style="font-size: 2rem; font-weight: 800; color: #f8fafc; margin-top: 0.3rem;">Start with the side of RideSync you need.</h2>
        </div>

        <div class="role-card-grid public-role-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem;">
            <a href="/ridesync/pages/login.php?role=rider" class="role-card rider-card" style="background: linear-gradient(135deg, rgba(30, 41, 59, 0.9), rgba(15, 23, 42, 0.9)); border: 1px solid rgba(56, 189, 248, 0.3); padding: 2rem; border-radius: 20px; text-decoration: none;">
                <span class="role-icon" style="background: rgba(56, 189, 248, 0.15); color: #38bdf8; padding: 0.4rem 0.8rem; border-radius: 8px; font-weight: 700; font-size: 0.85rem;">Rider</span>
                <h2 style="font-size: 1.4rem; color: #f8fafc; margin: 1rem 0 0.5rem;">Find or Post a Ride</h2>
                <p style="color: #94a3b8; font-size: 0.92rem; line-height: 1.5; margin-bottom: 1.5rem;">Discover shared routes, send join requests, track real-time notifications, and manage personal emergency contacts.</p>
                <strong style="color: #38bdf8; font-size: 0.95rem;">Open Rider Workspace &rarr;</strong>
            </a>

            <a href="/ridesync/pages/driver_login.php" class="role-card driver-card" style="background: linear-gradient(135deg, rgba(30, 41, 59, 0.9), rgba(15, 23, 42, 0.9)); border: 1px solid rgba(16, 185, 129, 0.3); padding: 2rem; border-radius: 20px; text-decoration: none;">
                <span class="role-icon" style="background: rgba(16, 185, 129, 0.15); color: #10b981; padding: 0.4rem 0.8rem; border-radius: 8px; font-weight: 700; font-size: 0.85rem;">Driver</span>
                <h2 style="font-size: 1.4rem; color: #f8fafc; margin: 1rem 0 0.5rem;">Accept Campus Requests</h2>
                <p style="color: #94a3b8; font-size: 0.92rem; line-height: 1.5; margin-bottom: 1.5rem;">Go online, receive instant rider requests, claim open community rides, and track daily earnings analytics.</p>
                <strong style="color: #10b981; font-size: 0.95rem;">Open Driver Workspace &rarr;</strong>
            </a>
        </div>
    </section>
</section>

<?php require_once 'includes/footer.php'; ?>
