<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/admin_helper.php';

if (isset($_SESSION['admin_id'])) {
    header("Location: /ridesync/pages/admin_dashboard.php");
    exit();
}

$schemaReady = ridesync_admin_schema_ready($conn);
$needsSetup = $schemaReady && ridesync_admin_count($conn) === 0;

require_once __DIR__ . '/../includes/public_header.php';
?>

<section class="auth-shell auth-shell-admin">
    <aside class="auth-context-card">
        <span class="auth-kicker">Admin OS</span>
        <h1><?php echo $needsSetup ? 'Create the first secure admin.' : 'Enter the operations panel.'; ?></h1>
        <p>Access moderation, services, audit trails, reports, and operational controls.</p>
        <div class="auth-route-strip" aria-hidden="true">
            <span>Monitor</span>
            <i></i>
            <span>Control</span>
        </div>
    </aside>

    <div class="form-container auth-panel">
        <span class="auth-panel-eyebrow"><?php echo $needsSetup ? 'Initial setup' : 'Secure access'; ?></span>
        <h1><?php echo $needsSetup ? 'Create Admin Access' : 'Admin Login'; ?></h1>

        <?php ridesync_flash('admin_success', 'alert-success'); ?>
        <?php ridesync_flash('admin_error', 'alert-error'); ?>

        <?php if (!$schemaReady): ?>
            <div class="alert alert-error">Admin database tables are missing. Import the latest RideSync SQL file first.</div>
        <?php else: ?>
            <form action="/ridesync/actions/admin_auth_action.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="action_type" value="<?php echo $needsSetup ? 'setup' : 'login'; ?>">

                <?php if ($needsSetup): ?>
                    <div class="form-group">
                        <label for="name">Admin Name</label>
                        <input type="text" id="name" name="name" required maxlength="100" autocomplete="name">
                    </div>
                <?php endif; ?>

                <div class="form-group">
                    <label for="email">Admin Email</label>
                    <input type="email" id="email" name="email" required autocomplete="username" data-email-validate>
                    <div class="email-validation-message" data-email-message></div>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required minlength="8" autocomplete="<?php echo $needsSetup ? 'new-password' : 'current-password'; ?>">
                </div>

                <?php if ($needsSetup): ?>
                    <div class="form-group">
                        <label for="confirm_password">Confirm Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" required minlength="8" autocomplete="new-password">
                    </div>
                <?php endif; ?>

                <button type="submit" class="btn btn-primary auth-submit">
                    <?php echo $needsSetup ? 'Create Admin' : 'Login'; ?>
                </button>
            </form>
        <?php endif; ?>

        <div class="auth-link-row">
            <p class="auth-switch-text"><a href="/ridesync/index.php">Back to RideSync</a></p>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
