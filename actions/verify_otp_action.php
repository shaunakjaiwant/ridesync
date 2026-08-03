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
$otp = trim($_POST['otp'] ?? '');

$rateIdentity = ridesync_client_ip() . '|verify_otp|' . ($email ?: 'anonymous');
ridesync_enforce_rate_limit('auth:verify_otp', 10, 15 * 60, $rateIdentity, [
    'redirect' => $redirect,
    'flash_key' => 'forgot_password_error',
    'message' => 'Too many OTP verification attempts. Please wait a few minutes and try again.',
]);

$verifyRes = ridesync_verify_password_reset_otp($conn, $accountType, $email, $otp);

if (!$verifyRes['ok']) {
    $_SESSION['forgot_password_error'] = $verifyRes['error'];
    $_SESSION['otp_reset_state'] = [
        'step' => 2,
        'email' => $email,
        'account_type' => $accountType,
    ];
    header("Location: " . $redirect);
    exit();
}

// OTP Verified! Advance to Step 3 (Set New Password)
$_SESSION['otp_reset_state'] = [
    'step' => 3,
    'email' => $email,
    'account_type' => $accountType,
    'reset_id' => $verifyRes['reset_id'],
];
$_SESSION['forgot_password_success'] = "OTP verified successfully! Please choose a new password.";

header("Location: " . $redirect);
exit();
