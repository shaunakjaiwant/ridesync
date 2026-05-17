<?php
require_once __DIR__ . '/../config/db.php';
if (isset($_SESSION['user_id'])) {
    header("Location: /ridesync/pages/dashboard.php");
    exit();
}
$_SESSION['selected_role'] = 'rider';
require_once __DIR__ . '/../includes/public_header.php';
?>

<div class="form-container">
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

        <button type="submit" class="btn btn-primary" style="width:100%;">Login</button>
    </form>

    <p class="auth-switch-text">
        Don't have an account? <a href="/ridesync/pages/register.php">Sign up here</a>
    </p>
    <p class="auth-switch-text">
        Want to drive? <a href="/ridesync/pages/driver_login.php">Switch to Driver</a>
    </p>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
