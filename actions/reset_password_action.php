<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/http_helper.php';
require_once __DIR__ . '/../includes/rate_limit_helper.php';
require_once __DIR__ . '/../includes/otp_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: /ridesync/pages/forgot_password.php");
    exit();
}

$accountType = trim((string) ($_POST['account_type'] ?? 'rider'));
$accountType = in_array($accountType, ['rider', 'driver'], true) ? $accountType : 'rider';
$redirect = '/ridesync/pages/forgot_password.php?role=' . urlencode($accountType);

ridesync_require_csrf($redirect, 'forgot_password_error');

$email = strtolower(trim($_POST['email'] ?? ''));
$newPassword = (string) ($_POST['new_password'] ?? '');
$confirmPassword = (string) ($_POST['confirm_password'] ?? '');

$rateIdentity = ridesync_client_ip() . '|reset_password|' . ($email ?: 'anonymous');
ridesync_enforce_rate_limit('auth:reset_password', 5, 15 * 60, $rateIdentity, [
    'redirect' => $redirect,
    'flash_key' => 'forgot_password_error',
    'message' => 'Too many password reset attempts. Please wait a few minutes and try again.',
]);

if (strlen($newPassword) < 8) {
    $_SESSION['forgot_password_error'] = "Password must be at least 8 characters long.";
    $_SESSION['otp_reset_state'] = ['step' => 3, 'email' => $email, 'account_type' => $accountType];
    header("Location: " . $redirect);
    exit();
}

if ($newPassword !== $confirmPassword) {
    $_SESSION['forgot_password_error'] = "Passwords do not match. Please enter matching passwords.";
    $_SESSION['otp_reset_state'] = ['step' => 3, 'email' => $email, 'account_type' => $accountType];
    header("Location: " . $redirect);
    exit();
}

$completeRes = ridesync_complete_password_reset($conn, $accountType, $email, $newPassword);

if (!$completeRes['ok']) {
    $_SESSION['forgot_password_error'] = $completeRes['error'];
    $_SESSION['otp_reset_state'] = ['step' => 3, 'email' => $email, 'account_type' => $accountType];
    header("Location: " . $redirect);
    exit();
}

unset($_SESSION['otp_reset_state']);

if ($accountType === 'driver') {
    $_SESSION['driver_register_success'] = "Password updated successfully! Please log in with your new password.";
    header("Location: /ridesync/pages/driver_login.php");
} else {
    $_SESSION['register_success'] = "Password updated successfully! Please log in with your new password.";
    header("Location: /ridesync/pages/login.php");
}
exit();

