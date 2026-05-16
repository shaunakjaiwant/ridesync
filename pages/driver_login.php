<?php
require_once __DIR__ . '/../config/db.php';
if (isset($_SESSION['driver_id'])) {
    header("Location: /ridesync/pages/driver_dashboard.php");
    exit();
}
$_SESSION['selected_role'] = 'driver';
require_once __DIR__ . '/../includes/public_header.php';
?>

<div class="form-container">
    <h2>Driver Login</h2>

    <?php ridesync_flash('driver_register_success', 'alert-success'); ?>
    <?php ridesync_flash('driver_auth_error', 'alert-error'); ?>

    <form action="/ridesync/actions/driver_auth_action.php" method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="action_type" value="login">

        <div class="form-group">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" required placeholder="driver@example.com">
        </div>

        <div class="form-group">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required placeholder="Your password">
        </div>

        <button type="submit" class="btn btn-primary" style="width:100%;">Login</button>
    </form>

    <p style="text-align:center; margin-top:15px; color:#777;">
        New here? <a href="/ridesync/pages/driver_register.php" style="color:#4361ee;">Sign up as Driver</a>
    </p>
    <p style="text-align:center; margin-top:8px; color:#777;">
        Not a driver? <a href="/ridesync/pages/login.php?role=rider" style="color:#4361ee;">Switch to Rider</a>
    </p>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
