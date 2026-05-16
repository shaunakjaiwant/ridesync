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

<div class="form-container">
    <h2><?php echo $needsSetup ? 'Create Admin Access' : 'Admin Login'; ?></h2>

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
                    <input type="text" id="name" name="name" required maxlength="100">
                </div>
            <?php endif; ?>

            <div class="form-group">
                <label for="email">Admin Email</label>
                <input type="email" id="email" name="email" required data-email-validate>
                <div class="email-validation-message" data-email-message></div>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required minlength="8">
            </div>

            <?php if ($needsSetup): ?>
                <div class="form-group">
                    <label for="confirm_password">Confirm Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" required minlength="8">
                </div>
            <?php endif; ?>

            <button type="submit" class="btn btn-primary" style="width:100%;">
                <?php echo $needsSetup ? 'Create Admin' : 'Login'; ?>
            </button>
        </form>
    <?php endif; ?>

    <p style="text-align:center; margin-top:15px; color:#777;">
        <a href="/ridesync/index.php" style="color:#4361ee;">Back to RideSync</a>
    </p>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
