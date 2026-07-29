<?php
require_once __DIR__ . '/../config/db.php';

$token = trim((string) ($_GET['token'] ?? ''));
$accountType = trim((string) ($_GET['role'] ?? 'rider'));
$userId = (int) ($_GET['id'] ?? 0);

$sessionKey = 'password_reset_token_' . $accountType . '_' . $userId;
$resetData = $_SESSION[$sessionKey] ?? null;

$tokenValid = is_array($resetData)
    && isset($resetData['token'], $resetData['expires_at'])
    && hash_equals($resetData['token'], $token)
    && $resetData['expires_at'] >= time();

require_once __DIR__ . '/../includes/public_header.php';
?>

<section class="auth-shell auth-shell-rider">
    <aside class="auth-context-card">
        <span class="auth-kicker">Set new password</span>
        <h1>Choose a new secure password.</h1>
        <p>Your password must be at least 8 characters long.</p>
        <div class="auth-route-strip" aria-hidden="true">
            <span>Secure</span>
            <i></i>
            <span>Update</span>
        </div>
    </aside>

    <div class="form-container auth-panel">
        <span class="auth-panel-eyebrow">Account recovery</span>
        <h1>Reset Password</h1>

        <?php ridesync_flash('reset_password_error', 'alert-error'); ?>

        <?php if (!$tokenValid): ?>
            <div class="alert alert-error">
                Password reset link is invalid or has expired. Please request a new link.
            </div>
            <div class="auth-link-row" style="margin-top: 1rem;">
                <a href="/ridesync/pages/forgot_password.php?role=<?php echo htmlspecialchars($accountType); ?>" class="btn btn-primary">Request New Reset Link</a>
            </div>
        <?php else: ?>
            <form action="/ridesync/actions/reset_password_action.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                <input type="hidden" name="account_type" value="<?php echo htmlspecialchars($accountType); ?>">
                <input type="hidden" name="user_id" value="<?php echo $userId; ?>">

                <div class="form-group">
                    <label for="new_password">New Password</label>
                    <input type="password" id="new_password" name="new_password" required minlength="8" autocomplete="new-password" placeholder="Min 8 characters">
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm New Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" required minlength="8" autocomplete="new-password" placeholder="Re-enter new password">
                </div>

                <button type="submit" class="btn btn-primary auth-submit">Update Password</button>
            </form>
        <?php endif; ?>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
