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

<section class="auth-shell auth-shell-rider">
    <aside class="auth-context-card">
        <span class="auth-kicker">Rider signup</span>
        <h1>Build your campus ride profile once.</h1>
        <p>Create a rider account for route search, trip posts, join requests, and notifications.</p>
        <div class="auth-route-strip" aria-hidden="true">
            <span>Post</span>
            <i></i>
            <span>Match</span>
        </div>
    </aside>

    <div class="form-container auth-panel auth-panel-register">
        <div class="auth-role-tabs" style="display: flex; border-bottom: 2px solid #e2e8f0; margin-bottom: 1.5rem; gap: 0.5rem;">
            <a href="/ridesync/pages/register.php" class="auth-role-tab is-active" style="padding: 0.6rem 1rem; font-weight: 600; text-decoration: none; border-bottom: 3px solid #2563eb; color: #2563eb;">Rider Account</a>
            <a href="/ridesync/pages/driver_register.php" class="auth-role-tab" style="padding: 0.6rem 1rem; font-weight: 500; text-decoration: none; color: #64748b;">Driver Account</a>
        </div>
        <span class="auth-panel-eyebrow">New rider</span>
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

            <button type="submit" class="btn btn-primary auth-submit">Sign Up</button>
        </form>

        <div class="auth-link-row">
            <p class="auth-switch-text">Already registered? <a href="/ridesync/pages/login.php">Login</a></p>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
