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
    <h2>Login to Ride</h2>

    <?php ridesync_flash('register_success', 'alert-success'); ?>
    <?php ridesync_flash('login_error', 'alert-error'); ?>

    <form action="/ridesync/actions/login_action.php" method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

        <div class="form-group">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" required placeholder="you@university.edu">
        </div>

        <div class="form-group">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required placeholder="Your password">
        </div>

        <button type="submit" class="btn btn-primary" style="width:100%;">Login</button>
    </form>

    <p style="text-align:center; margin-top:15px; color:#777;">
        Don't have an account? <a href="/ridesync/pages/register.php" style="color:#4361ee;">Sign up here</a>
    </p>
    <p style="text-align:center; margin-top:8px; color:#777;">
        Want to drive? <a href="/ridesync/pages/driver_login.php" style="color:#4361ee;">Switch to Driver</a>
    </p>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
