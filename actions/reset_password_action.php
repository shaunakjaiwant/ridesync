<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/http_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: /ridesync/pages/forgot_password.php");
    exit();
}

$token = trim((string) ($_POST['token'] ?? ''));
$accountType = trim((string) ($_POST['account_type'] ?? 'rider'));
$userId = (int) ($_POST['user_id'] ?? 0);
$redirect = "/ridesync/pages/reset_password.php?token=" . urlencode($token) . "&role=" . urlencode($accountType) . "&id=" . $userId;

ridesync_require_csrf($redirect, 'reset_password_error');

$sessionKey = 'password_reset_token_' . $accountType . '_' . $userId;
$resetData = $_SESSION[$sessionKey] ?? null;

$tokenValid = is_array($resetData)
    && isset($resetData['token'], $resetData['expires_at'])
    && hash_equals($resetData['token'], $token)
    && $resetData['expires_at'] >= time();

if (!$tokenValid) {
    $_SESSION['reset_password_error'] = "Password reset link has expired or is invalid.";
    header("Location: /ridesync/pages/forgot_password.php?role=" . urlencode($accountType));
    exit();
}

$newPassword = $_POST['new_password'] ?? '';
$confirmPassword = $_POST['confirm_password'] ?? '';

if (strlen($newPassword) < 8) {
    $_SESSION['reset_password_error'] = "Password must be at least 8 characters.";
    header("Location: " . $redirect);
    exit();
}

if ($newPassword !== $confirmPassword) {
    $_SESSION['reset_password_error'] = "Passwords do not match.";
    header("Location: " . $redirect);
    exit();
}

$hashed = password_hash($newPassword, PASSWORD_DEFAULT);
$table = $accountType === 'driver' ? 'driver_accounts' : 'users';

$stmt = mysqli_prepare($conn, "UPDATE {$table} SET password = ? WHERE id = ?");
if (!$stmt) {
    $_SESSION['reset_password_error'] = "Something went wrong. Please try again.";
    header("Location: " . $redirect);
    exit();
}

mysqli_stmt_bind_param($stmt, "si", $hashed, $userId);
$updated = mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);

if ($updated) {
    unset($_SESSION[$sessionKey]);
    if ($accountType === 'driver') {
        $_SESSION['driver_register_success'] = "Password updated successfully! Log in with your new password.";
        header("Location: /ridesync/pages/driver_login.php");
    } else {
        $_SESSION['register_success'] = "Password updated successfully! Log in with your new password.";
        header("Location: /ridesync/pages/login.php");
    }
    exit();
}

$_SESSION['reset_password_error'] = "Could not update password. Please try again.";
header("Location: " . $redirect);
exit();
