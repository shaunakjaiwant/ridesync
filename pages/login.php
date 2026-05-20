<?php
require_once __DIR__ . '/../config/db.php';
if (isset($_SESSION['user_id'])) {
    header("Location: /ridesync/pages/dashboard.php");
    exit();
}
$_SESSION['selected_role'] = 'rider';
require_once __DIR__ . '/../includes/public_header.php';
?>

<section class="auth-shell auth-shell-rider">
    <aside class="auth-context-card">
        <span class="auth-kicker">Rider access</span>
        <h1>Find the next campus ride faster.</h1>
        <p>Jump back into route matches, ride requests, notifications, and trip tracking.</p>
        <div class="auth-route-strip" aria-hidden="true">
            <span>Campus</span>
            <i></i>
            <span>Ride</span>
        </div>
    </aside>

    <div class="form-container auth-panel">
        <span class="auth-panel-eyebrow">Welcome back</span>
        <h1>Login to Ride</h1>

        <?php ridesync_flash('register_success', 'alert-success'); ?>
        <?php ridesync_flash('login_error', 'alert-error'); ?>

        <form action="/ridesync/actions/login_action.php" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" required autocomplete="username" placeholder="you@university.edu">
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required autocomplete="current-password" placeholder="Your password">
            </div>

            <button type="submit" class="btn btn-primary auth-submit">Login</button>
        </form>

        <div class="auth-link-row">
            <p class="auth-switch-text">No account? <a href="/ridesync/pages/register.php">Sign up</a></p>
            <p class="auth-switch-text">Want to drive? <a href="/ridesync/pages/driver_login.php">Driver login</a></p>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
