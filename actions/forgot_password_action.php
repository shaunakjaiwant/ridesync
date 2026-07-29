<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/email_helper.php';
require_once __DIR__ . '/../includes/http_helper.php';
require_once __DIR__ . '/../includes/rate_limit_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: /ridesync/pages/forgot_password.php");
    exit();
}

$accountType = trim((string) ($_POST['account_type'] ?? 'rider'));
$redirect = '/ridesync/pages/forgot_password.php?role=' . urlencode($accountType);

ridesync_require_csrf($redirect, 'forgot_password_error');

$email = trim($_POST['email'] ?? '');
$rateIdentity = ridesync_client_ip() . '|forgot_password|' . strtolower($email ?: 'anonymous');
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

if ($user) {
    $token = bin2hex(random_bytes(24));
    $_SESSION['password_reset_token_' . $accountType . '_' . $user['id']] = [
        'token' => $token,
        'expires_at' => time() + 3600,
        'email' => $email,
        'account_type' => $accountType,
        'user_id' => (int) $user['id'],
    ];

    $resetUrl = "/ridesync/pages/reset_password.php?token=" . $token . "&role=" . urlencode($accountType) . "&id=" . $user['id'];
    $_SESSION['forgot_password_success'] = "Password reset instructions created! Click <a href='" . htmlspecialchars($resetUrl) . "' style='font-weight: 600; text-decoration: underline;'>Reset Password Now</a> to set your new password.";
} else {
    $_SESSION['forgot_password_success'] = "If an account with that email exists, password reset instructions have been generated.";
}

header("Location: " . $redirect);
exit();
