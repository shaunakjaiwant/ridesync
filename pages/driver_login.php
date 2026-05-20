<?php
require_once __DIR__ . '/../config/db.php';
if (isset($_SESSION['driver_id'])) {
    header("Location: /ridesync/pages/driver_dashboard.php");
    exit();
}
$_SESSION['selected_role'] = 'driver';
require_once __DIR__ . '/../includes/public_header.php';
?>

<section class="auth-shell auth-shell-driver">
    <aside class="auth-context-card">
        <span class="auth-kicker">Driver access</span>
        <h1>Go online when campus routes need you.</h1>
        <p>Open your driver workspace for direct requests, live availability, claimed rides, and earnings.</p>
        <div class="auth-route-strip" aria-hidden="true">
            <span>Online</span>
            <i></i>
            <span>Request</span>
        </div>
    </aside>

    <div class="form-container auth-panel">
        <span class="auth-panel-eyebrow">Driver workspace</span>
        <h1>Driver Login</h1>

        <?php ridesync_flash('driver_register_success', 'alert-success'); ?>
        <?php ridesync_flash('driver_auth_error', 'alert-error'); ?>

        <form action="/ridesync/actions/driver_auth_action.php" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <input type="hidden" name="action_type" value="login">

            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" required autocomplete="username" placeholder="driver@example.com">
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required autocomplete="current-password" placeholder="Your password">
            </div>

            <button type="submit" class="btn btn-primary auth-submit">Login</button>
        </form>

        <div class="auth-link-row">
            <p class="auth-switch-text">New driver? <a href="/ridesync/pages/driver_register.php">Sign up</a></p>
            <p class="auth-switch-text">Not a driver? <a href="/ridesync/pages/login.php?role=rider">Rider login</a></p>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
