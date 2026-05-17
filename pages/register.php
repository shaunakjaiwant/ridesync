<?php
require_once __DIR__ . '/../config/db.php';
if (isset($_SESSION['user_id'])) {
    header("Location: /ridesync/pages/dashboard.php");
    exit();
}
$_SESSION['selected_role'] = 'rider';
require_once __DIR__ . '/../includes/public_header.php';
require_once __DIR__ . '/../includes/college_suggestions.php';
?>

<div class="form-container">
    <h1>Create an Account</h1>

    <?php ridesync_flash('register_error', 'alert-error'); ?>

    <form action="/ridesync/actions/register_action.php" method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

        <div class="form-group">
            <label for="name">Full Name</label>
            <input type="text" id="name" name="name" required autocomplete="name" placeholder="John Doe">
        </div>

        <div class="form-group">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" required autocomplete="username" placeholder="you@gmail.com">
        </div>

        <div class="form-group">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required minlength="8" autocomplete="new-password" placeholder="Min 8 characters">
        </div>

        <div class="form-group">
            <label for="confirm_password">Confirm Password</label>
            <input type="password" id="confirm_password" name="confirm_password" required autocomplete="new-password" placeholder="Re-enter password">
        </div>

        <div class="form-group">
            <label for="college">College / University</label>
            <input type="text" id="college" name="college" list="college-suggestions" required placeholder="SDMIT">
            <?php ridesync_render_college_datalist(); ?>
        </div>

        <div class="form-group">
            <label for="gender">Gender</label>
            <select id="gender" name="gender" required>
                <option value="">-- Select --</option>
                <option value="Male">Male</option>
                <option value="Female">Female</option>
                <option value="Other">Other</option>
            </select>
        </div>

        <button type="submit" class="btn btn-primary" style="width:100%;">Sign Up</button>
    </form>

    <p class="auth-switch-text">
        Already have an account? <a href="/ridesync/pages/login.php">Login here</a>
    </p>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
