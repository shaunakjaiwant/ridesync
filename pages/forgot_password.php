<?php
require_once __DIR__ . '/../config/db.php';

$requestedRole = trim((string) ($_GET['role'] ?? 'rider'));
if (!in_array($requestedRole, ['rider', 'driver'], true)) {
    $requestedRole = 'rider';
}

require_once __DIR__ . '/../includes/public_header.php';
?>

<section class="auth-shell auth-shell-rider">
    <aside class="auth-context-card">
        <span class="auth-kicker">Account recovery</span>
        <h1>Reset your password safely.</h1>
        <p>Enter your registered email address to receive secure account recovery instructions.</p>
        <div class="auth-route-strip" aria-hidden="true">
            <span>Recovery</span>
            <i></i>
            <span>Reset</span>
        </div>
    </aside>

    <div class="form-container auth-panel">
        <div class="auth-role-tabs" style="display: flex; border-bottom: 2px solid #e2e8f0; margin-bottom: 1.5rem; gap: 0.5rem;">
            <a href="/ridesync/pages/forgot_password.php?role=rider" class="auth-role-tab <?php echo $requestedRole === 'rider' ? 'is-active' : ''; ?>" style="padding: 0.6rem 1rem; font-weight: <?php echo $requestedRole === 'rider' ? '600' : '500'; ?>; text-decoration: none; border-bottom: <?php echo $requestedRole === 'rider' ? '3px solid #2563eb' : 'none'; ?>; color: <?php echo $requestedRole === 'rider' ? '#2563eb' : '#64748b'; ?>;">Rider Account</a>
            <a href="/ridesync/pages/forgot_password.php?role=driver" class="auth-role-tab <?php echo $requestedRole === 'driver' ? 'is-active' : ''; ?>" style="padding: 0.6rem 1rem; font-weight: <?php echo $requestedRole === 'driver' ? '600' : '500'; ?>; text-decoration: none; border-bottom: <?php echo $requestedRole === 'driver' ? '3px solid #2563eb' : 'none'; ?>; color: <?php echo $requestedRole === 'driver' ? '#2563eb' : '#64748b'; ?>;">Driver Account</a>
        </div>

        <span class="auth-panel-eyebrow">Password recovery</span>
        <h1>Forgot Password</h1>

        <?php ridesync_flash('forgot_password_error', 'alert-error'); ?>
        <?php ridesync_flash('forgot_password_success', 'alert-success'); ?>

        <form action="/ridesync/actions/forgot_password_action.php" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
            <input type="hidden" name="account_type" value="<?php echo htmlspecialchars($requestedRole); ?>">

            <div class="form-group">
                <label for="email">Registered Email Address</label>
                <input type="email" id="email" name="email" required autocomplete="email" placeholder="you@university.edu">
            </div>

            <button type="submit" class="btn btn-primary auth-submit">Send Password Reset Instructions</button>
        </form>

        <div class="auth-link-row" style="margin-top: 1.5rem;">
            <p class="auth-switch-text">Remembered password? <a href="<?php echo $requestedRole === 'driver' ? '/ridesync/pages/driver_login.php' : '/ridesync/pages/login.php'; ?>">Return to Login</a></p>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
