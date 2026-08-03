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
                <strong>SDMIT to Ujire</strong>
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
                <div class="public-hero-actions" style="display: flex; flex-wrap: wrap; gap: 0.85rem; align-items: center;">
                    <a href="/ridesync/pages/login.php?role=rider" class="btn btn-primary">Find a ride</a>
                    <a href="/ridesync/pages/driver_login.php" class="btn btn-secondary">Drive with RideSync</a>
                    <button type="button" id="btnHowItWorks" class="btn btn-secondary" style="background: rgba(56, 189, 248, 0.12); border-color: rgba(56, 189, 248, 0.3); color: #38bdf8; font-weight: 600; display: inline-flex; align-items: center; gap: 0.4rem;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polygon points="10 8 16 12 10 16 10 8"/></svg>
                        See How It Works
                    </button>
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
                    <span>SDMIT</span>
                    <strong>Live</strong>
                    <span>Ujire</span>
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

<!-- Interactive 30-Second "How RideSync Works" Glassmorphic Tour Modal -->
<div id="tourModalOverlay" class="ridesync-tour-overlay" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; z-index:10000; background:rgba(15,23,42,0.88); backdrop-filter:blur(12px); align-items:center; justify-content:center; padding:20px; opacity:0; transition:opacity 0.25s ease;">
    <div class="ridesync-tour-modal" style="background:rgba(30,41,59,0.96); border:1px solid rgba(255,255,255,0.12); box-shadow:0 25px 50px rgba(0,0,0,0.6); border-radius:20px; max-width:540px; width:100%; padding:28px; color:#f8fafc; font-family:inherit; position:relative; transform:scale(0.95); transition:transform 0.25s ease;">
        
        <button type="button" id="btnCloseTourModal" style="position:absolute; top:20px; right:20px; background:rgba(255,255,255,0.08); border:none; color:#94a3b8; border-radius:50%; width:32px; height:32px; cursor:pointer; display:flex; align-items:center; justify-content:center; font-size:1.1rem; line-height:1;">&times;</button>

        <div style="margin-bottom:20px;">
            <span style="color:#38bdf8; font-size:0.78rem; text-transform:uppercase; font-weight:700; letter-spacing:0.05em; display:block;">Quick Tour &middot; 30 Seconds</span>
            <h3 style="font-size:1.35rem; font-weight:800; color:#f8fafc; margin:4px 0 0 0;">How RideSync Works</h3>
        </div>

        <!-- Slides Container -->
        <div id="tourSlidesContainer" style="min-height:210px;">
            <!-- Slide 1 -->
            <div class="tour-slide" data-slide="1" style="display:block;">
                <div style="background:rgba(56,189,248,0.1); border:1px solid rgba(56,189,248,0.2); border-radius:12px; padding:16px; margin-bottom:16px; display:flex; align-items:center; gap:14px;">
                    <div style="font-size:2rem; line-height:1;">🗺️</div>
                    <div>
                        <strong style="color:#38bdf8; font-size:1rem; display:block;">Step 1: Find or Post a Campus Route</strong>
                        <span style="color:#cbd5e1; font-size:0.88rem;">Enter your pickup, destination, travel time, and available seats.</span>
                    </div>
                </div>
                <p style="color:#94a3b8; font-size:0.9rem; line-height:1.5;">
                    Whether heading to SDMIT, Ujire, or Belthangady, RideSync continuously scans active campus trips to match you with compatible student routes.
                </p>
            </div>

            <!-- Slide 2 -->
            <div class="tour-slide" data-slide="2" style="display:none;">
                <div style="background:rgba(16,185,129,0.1); border:1px solid rgba(16,185,129,0.2); border-radius:12px; padding:16px; margin-bottom:16px; display:flex; align-items:center; gap:14px;">
                    <div style="font-size:2rem; line-height:1;">⚡</div>
                    <div>
                        <strong style="color:#10b981; font-size:1rem; display:block;">Step 2: Instant Driver & Rider Match</strong>
                        <span style="color:#cbd5e1; font-size:0.88rem;">Smart route fit matching with automatic driver fallback.</span>
                    </div>
                </div>
                <p style="color:#94a3b8; font-size:0.9rem; line-height:1.5;">
                    Send a 1-click join request to student drivers. If no shared ride matches your route, RideSync automatically dispatches verified campus drivers to pick you up.
                </p>
            </div>

            <!-- Slide 3 -->
            <div class="tour-slide" data-slide="3" style="display:none;">
                <div style="background:rgba(192,132,252,0.1); border:1px solid rgba(192,132,252,0.2); border-radius:12px; padding:16px; margin-bottom:16px; display:flex; align-items:center; gap:14px;">
                    <div style="font-size:2rem; line-height:1;">🛡️</div>
                    <div>
                        <strong style="color:#c084fc; font-size:1rem; display:block;">Step 3: Live Tracking & Emergency SOS</strong>
                        <span style="color:#cbd5e1; font-size:0.88rem;">24/7 Safety oversight and instant contact alerts.</span>
                    </div>
                </div>
                <p style="color:#94a3b8; font-size:0.9rem; line-height:1.5;">
                    Track live trip status in real-time. In any emergency, 1-tap SOS notifies administrators and your personal emergency contacts immediately.
                </p>
            </div>
        </div>

        <!-- Controls & Navigation -->
        <div style="display:flex; justify-content:space-between; align-items:center; margin-top:20px; padding-top:16px; border-top:1px solid rgba(255,255,255,0.08);">
            <div style="display:flex; gap:6px;" id="tourDots">
                <span class="tour-dot" data-dot="1" style="width:24px; height:8px; border-radius:4px; background:#38bdf8; transition:all 0.2s ease;"></span>
                <span class="tour-dot" data-dot="2" style="width:8px; height:8px; border-radius:4px; background:rgba(255,255,255,0.2); transition:all 0.2s ease;"></span>
                <span class="tour-dot" data-dot="3" style="width:8px; height:8px; border-radius:4px; background:rgba(255,255,255,0.2); transition:all 0.2s ease;"></span>
            </div>

            <div style="display:flex; gap:10px;">
                <button type="button" id="btnTourPrev" style="padding:8px 16px; background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.1); border-radius:8px; color:#cbd5e1; cursor:pointer; font-weight:600; font-size:0.85rem; display:none;">Previous</button>
                <button type="button" id="btnTourNext" style="padding:8px 18px; background:#2563eb; border:none; border-radius:8px; color:#fff; cursor:pointer; font-weight:600; font-size:0.85rem; box-shadow:0 4px 12px rgba(37,99,235,0.3);">Next &rarr;</button>
            </div>
        </div>

    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var btnOpen = document.getElementById('btnHowItWorks');
    var btnClose = document.getElementById('btnCloseTourModal');
    var overlay = document.getElementById('tourModalOverlay');
    var modal = overlay ? overlay.querySelector('.ridesync-tour-modal') : null;
    var btnPrev = document.getElementById('btnTourPrev');
    var btnNext = document.getElementById('btnTourNext');
    var currentSlide = 1;
    var totalSlides = 3;

    if (!btnOpen || !overlay || !modal) return;

    function showSlide(index) {
        currentSlide = index;
        var slides = overlay.querySelectorAll('.tour-slide');
        slides.forEach(function (slide) {
            slide.style.display = parseInt(slide.dataset.slide, 10) === currentSlide ? 'block' : 'none';
        });

        var dots = overlay.querySelectorAll('.tour-dot');
        dots.forEach(function (dot) {
            var dotIdx = parseInt(dot.dataset.dot, 10);
            if (dotIdx === currentSlide) {
                dot.style.width = '24px';
                dot.style.background = currentSlide === 1 ? '#38bdf8' : (currentSlide === 2 ? '#10b981' : '#c084fc');
            } else {
                dot.style.width = '8px';
                dot.style.background = 'rgba(255,255,255,0.2)';
            }
        });

        if (btnPrev) btnPrev.style.display = currentSlide > 1 ? 'inline-block' : 'none';
        if (btnNext) {
            if (currentSlide === totalSlides) {
                btnNext.textContent = 'Get Started →';
                btnNext.style.background = '#10b981';
            } else {
                btnNext.textContent = 'Next →';
                btnNext.style.background = '#2563eb';
            }
        }
    }

    function openModal() {
        showSlide(1);
        overlay.style.display = 'flex';
        requestAnimationFrame(function () {
            overlay.style.opacity = '1';
            modal.style.transform = 'scale(1)';
        });
        document.addEventListener('keydown', handleEsc);
    }

    function closeModal() {
        document.removeEventListener('keydown', handleEsc);
        overlay.style.opacity = '0';
        modal.style.transform = 'scale(0.95)';
        setTimeout(function () { overlay.style.display = 'none'; }, 250);
    }

    function handleEsc(e) {
        if (e.key === 'Escape') closeModal();
    }

    btnOpen.addEventListener('click', openModal);
    if (btnClose) btnClose.addEventListener('click', closeModal);
    overlay.addEventListener('click', function (e) {
        if (e.target === overlay) closeModal();
    });

    if (btnPrev) {
        btnPrev.addEventListener('click', function () {
            if (currentSlide > 1) showSlide(currentSlide - 1);
        });
    }

    if (btnNext) {
        btnNext.addEventListener('click', function () {
            if (currentSlide < totalSlides) {
                showSlide(currentSlide + 1);
            } else {
                closeModal();
                window.location.href = '/ridesync/pages/login.php?role=rider';
            }
        });
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>
