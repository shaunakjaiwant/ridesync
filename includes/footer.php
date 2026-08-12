<?php
require_once __DIR__ . '/asset_helper.php';
$needsMapAssets = ridesync_page_needs_map_assets();
$needsMapPicker = ridesync_page_needs_map_picker();
$scriptNonce = htmlspecialchars(ridesync_csp_nonce(), ENT_QUOTES, 'UTF-8');
?>
</main>

<?php if (isset($_SESSION['user_id'])): ?>
<nav class="mobile-bottom-nav rider-mobile-nav" aria-label="Mobile Navigation">
    <a href="/ridesync/pages/dashboard.php" class="mobile-nav-item <?php echo ($currentPage ?? '') === 'dashboard.php' ? 'is-active' : ''; ?>">
        <svg class="ui-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>
        <span>Dashboard</span>
    </a>
    <a href="/ridesync/pages/search_rides.php" class="mobile-nav-item <?php echo ($currentPage ?? '') === 'search_rides.php' ? 'is-active' : ''; ?>">
        <svg class="ui-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
        <span>Search</span>
    </a>
    <a href="/ridesync/pages/post_ride.php" class="mobile-nav-item mobile-nav-action <?php echo ($currentPage ?? '') === 'post_ride.php' ? 'is-active' : ''; ?>">
        <span class="mobile-action-pill">
            <svg class="ui-icon" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 8v8"/><path d="M8 12h8"/></svg>
        </span>
        <span>Post Ride</span>
    </a>
    <a href="/ridesync/pages/my_rides.php" class="mobile-nav-item <?php echo (($currentPage ?? '') === 'my_rides.php' || ($currentPage ?? '') === 'my_matches.php') ? 'is-active' : ''; ?>">
        <svg class="ui-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.5 2.8C2.05 10.9 2 11.2 2 11.5V16c0 .6.4 1 1 1h2"/><circle cx="7" cy="17" r="2"/><path d="M9 17h6"/><circle cx="17" cy="17" r="2"/></svg>
        <span>Trips</span>
    </a>
    <a href="/ridesync/pages/notifications.php?actor_type=user" class="mobile-nav-item <?php echo ($currentPage ?? '') === 'notifications.php' ? 'is-active' : ''; ?>">
        <svg class="ui-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
        <span>Alerts</span>
        <?php if (!empty($unreadNotifications)): ?>
            <span class="nav-badge nav-badge-pulse"><?php echo min(99, $unreadNotifications); ?></span>
        <?php endif; ?>
    </a>
</nav>
<?php elseif (isset($_SESSION['driver_id'])): ?>
<nav class="mobile-bottom-nav driver-mobile-nav" aria-label="Mobile Driver Navigation">
    <a href="/ridesync/pages/driver_dashboard.php" class="mobile-nav-item <?php echo ($currentDriverPage ?? '') === 'driver_dashboard.php' ? 'is-active' : ''; ?>">
        <svg class="ui-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>
        <span>Cockpit</span>
    </a>
    <a href="/ridesync/pages/driver_requests.php" class="mobile-nav-item <?php echo ($currentDriverPage ?? '') === 'driver_requests.php' ? 'is-active' : ''; ?>">
        <svg class="ui-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4"/><path d="M12 16h4"/><path d="M8 11h.01"/><path d="M8 16h.01"/></svg>
        <span>Queue</span>
    </a>
    <a href="/ridesync/pages/driver_earnings.php" class="mobile-nav-item <?php echo ($currentDriverPage ?? '') === 'driver_earnings.php' ? 'is-active' : ''; ?>">
        <svg class="ui-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
        <span>Earnings</span>
    </a>
    <a href="/ridesync/pages/notifications.php?actor_type=driver" class="mobile-nav-item <?php echo ($currentDriverPage ?? '') === 'notifications.php' ? 'is-active' : ''; ?>">
        <svg class="ui-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
        <span>Alerts</span>
        <?php if (!empty($unreadNotifications)): ?>
            <span class="nav-badge nav-badge-pulse"><?php echo min(99, $unreadNotifications); ?></span>
        <?php endif; ?>
    </a>
    <a href="/ridesync/pages/driver_profile.php" class="mobile-nav-item <?php echo ($currentDriverPage ?? '') === 'driver_profile.php' ? 'is-active' : ''; ?>">
        <svg class="ui-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        <span>Profile</span>
    </a>
</nav>
<?php endif; ?>

<footer class="site-footer">
    <p>&copy; <?php echo date('Y'); ?> RideSync. Built for students, by students.</p>
</footer>

<?php if ($needsMapAssets): ?>
<script nonce="<?php echo $scriptNonce; ?>" src="/ridesync/assets/vendor/leaflet/1.9.4/leaflet.js?v=<?php echo ridesync_script_version('assets/vendor/leaflet/1.9.4/leaflet.js'); ?>"></script>
<?php endif; ?>
<script nonce="<?php echo $scriptNonce; ?>" src="/ridesync/js/script.js?v=<?php echo ridesync_script_version('js/script.js'); ?>"></script>
<script nonce="<?php echo $scriptNonce; ?>" src="/ridesync/js/confirm_dialog.js?v=<?php echo ridesync_script_version('js/confirm_dialog.js'); ?>"></script>
<script nonce="<?php echo $scriptNonce; ?>" src="/ridesync/js/button_loading.js?v=<?php echo ridesync_script_version('js/button_loading.js'); ?>"></script>
<script nonce="<?php echo $scriptNonce; ?>" src="/ridesync/js/password_toggle.js?v=<?php echo ridesync_script_version('js/password_toggle.js'); ?>"></script>
<?php if ($needsMapPicker): ?>
<script nonce="<?php echo $scriptNonce; ?>" src="/ridesync/js/map_picker.js?v=<?php echo ridesync_script_version('js/map_picker.js'); ?>"></script>
<?php endif; ?>
<script nonce="<?php echo $scriptNonce; ?>" src="/ridesync/js/live_tracking.js?v=<?php echo ridesync_script_version('js/live_tracking.js'); ?>"></script>
</body>
</html>
