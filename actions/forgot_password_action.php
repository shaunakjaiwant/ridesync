<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/email_helper.php';
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
$rateIdentity = ridesync_client_ip() . '|forgot_password|' . ($email ?: 'anonymous');
ridesync_enforce_rate_limit('auth:forgot_password', 5, 15 * 60, $rateIdentity, [
    'redirect' => $redirect,
    'flash_key' => 'forgot_password_error',
    'message' => 'Too many recovery requests. Please wait a few minutes and try again.',
]);

if ($email === '' || !ridesync_is_valid_email($email)) {
    $_SESSION['forgot_password_error'] = "Please enter a valid registered email address.";
    header("Location: " . $redirect);
    exit();
}

$table = $accountType === 'driver' ? 'driver_accounts' : 'users';
$stmt = mysqli_prepare($conn, "SELECT id, name FROM {$table} WHERE email = ? LIMIT 1");
if ($stmt) {
    mysqli_stmt_bind_param($stmt, "s", $email);
    mysqli_stmt_execute($stmt);
    $user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
} else {
    $user = null;
}

$devNotice = '';
if ($user) {
    $otpRes = ridesync_create_password_reset_otp($conn, $accountType, (int) $user['id'], $email);
    if (!empty($otpRes['cooldown'])) {
        $_SESSION['forgot_password_error'] = $otpRes['error'];
        $_SESSION['otp_reset_state'] = ['step' => 2, 'email' => $email, 'account_type' => $accountType];
        header("Location: " . $redirect);
        exit();
    }
    if (!empty($otpRes['raw_otp'])) {
        $devNotice = " <span style='display:block; margin-top:0.5rem; padding:0.4rem 0.6rem; background:#eff6ff; border:1px solid #bfdbfe; border-radius:6px; font-size:0.9rem; color:#1e40af;'><strong>[LOCAL DEV OTP CODE]:</strong> " . htmlspecialchars($otpRes['raw_otp']) . "</span>";
    }
}

// Always show generic response to prevent account enumeration
$_SESSION['otp_reset_state'] = ['step' => 2, 'email' => $email, 'account_type' => $accountType];
$_SESSION['forgot_password_success'] = "If an account with that email address exists, a 6-digit OTP code has been sent." . $devNotice;

header("Location: " . $redirect);
exit();

