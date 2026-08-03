<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/http_helper.php';
require_once __DIR__ . '/../includes/rate_limit_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: /ridesync/pages/profile.php");
    exit();
}

$accountType = isset($_SESSION['driver_id']) ? 'driver' : (isset($_SESSION['user_id']) ? 'rider' : '');
if ($accountType === '') {
    header("Location: /ridesync/pages/login.php");
    exit();
}

$userId = $accountType === 'driver' ? (int) $_SESSION['driver_id'] : (int) $_SESSION['user_id'];
$redirect = $accountType === 'driver' ? '/ridesync/pages/driver_profile.php' : '/ridesync/pages/profile.php';

ridesync_require_csrf($redirect, 'change_password_error');

$currentPassword = (string) ($_POST['current_password'] ?? '');
$newPassword = (string) ($_POST['new_password'] ?? '');
$confirmPassword = (string) ($_POST['confirm_password'] ?? '');

$rateIdentity = ridesync_client_ip() . '|change_password|' . $accountType . '|' . $userId;
ridesync_enforce_rate_limit('auth:change_password', 5, 15 * 60, $rateIdentity, [
    'redirect' => $redirect,
    'flash_key' => 'change_password_error',
    'message' => 'Too many password change attempts. Please wait a few minutes and try again.',
]);

if (strlen($newPassword) < 8) {
    $_SESSION['change_password_error'] = "New password must be at least 8 characters long.";
    header("Location: " . $redirect);
    exit();
}

if ($newPassword !== $confirmPassword) {
    $_SESSION['change_password_error'] = "New passwords do not match. Please re-enter matching passwords.";
    header("Location: " . $redirect);
    exit();
}

$table = $accountType === 'driver' ? 'driver_accounts' : 'users';
$stmt = mysqli_prepare($conn, "SELECT password FROM {$table} WHERE id = ? LIMIT 1");
if (!$stmt) {
    $_SESSION['change_password_error'] = "System error verifying current password.";
    header("Location: " . $redirect);
    exit();
}

mysqli_stmt_bind_param($stmt, "i", $userId);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$row = mysqli_fetch_assoc($res);
mysqli_stmt_close($stmt);

if (!$row || !password_verify($currentPassword, $row['password'])) {
    $_SESSION['change_password_error'] = "Current password is incorrect.";
    header("Location: " . $redirect);
    exit();
}

// Update Password
$newHashed = password_hash($newPassword, PASSWORD_DEFAULT);
$upStmt = mysqli_prepare($conn, "UPDATE {$table} SET password = ? WHERE id = ?");
if (!$upStmt) {
    $_SESSION['change_password_error'] = "Could not update password.";
    header("Location: " . $redirect);
    exit();
}

mysqli_stmt_bind_param($upStmt, "si", $newHashed, $userId);
$updated = mysqli_stmt_execute($upStmt);
mysqli_stmt_close($upStmt);

if ($updated) {
    $_SESSION['change_password_success'] = "Password changed successfully!";
} else {
    $_SESSION['change_password_error'] = "Could not save new password. Please try again.";
}

header("Location: " . $redirect);
exit();
