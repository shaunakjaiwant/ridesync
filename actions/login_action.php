<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/email_helper.php';
require_once __DIR__ . '/../includes/http_helper.php';
require_once __DIR__ . '/../includes/rate_limit_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: /ridesync/pages/login.php");
    exit();
}

ridesync_require_csrf('/ridesync/pages/login.php', 'login_error');

$email    = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';
$rateIdentity = ridesync_client_ip() . '|rider|' . strtolower($email ?: 'anonymous');
ridesync_enforce_rate_limit('auth:rider_login', 8, 15 * 60, $rateIdentity, [
    'redirect' => '/ridesync/pages/login.php',
    'flash_key' => 'login_error',
    'message' => 'Too many login attempts. Please wait a few minutes and try again.',
]);

if ($email === '' || $password === '') {
    $_SESSION['login_error'] = "Please fill in both fields.";
    header("Location: /ridesync/pages/login.php");
    exit();
}

if (!ridesync_is_valid_email($email)) {
    $_SESSION['login_error'] = "Please enter a valid email address.";
    header("Location: /ridesync/pages/login.php");
    exit();
}

$sql = "SELECT id, name, password, COALESCE(status, 'active') AS status FROM users WHERE email = ?";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "s", $email);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if ($row = mysqli_fetch_assoc($result)) {
    if (password_verify($password, $row['password'])) {
        if ($row['status'] === 'suspended') {
            $_SESSION['login_error'] = "Your rider account has been suspended by RideSync administration. Please contact support.";
            header("Location: /ridesync/pages/login.php");
            exit();
        }

        ridesync_rate_limit_clear('auth:rider_login', $rateIdentity);
        session_regenerate_id(true);
        unset($_SESSION['driver_id'], $_SESSION['driver_name'], $_SESSION['admin_id'], $_SESSION['admin_name'], $_SESSION['admin_role']);
        $_SESSION['selected_role'] = 'rider';
        $_SESSION['user_id']   = $row['id'];
        $_SESSION['user_name'] = $row['name'];
        ridesync_mark_authenticated_session('rider');
        header("Location: /ridesync/pages/dashboard.php");
        exit();
    }
}

$_SESSION['login_error'] = "Invalid email or password.";
header("Location: /ridesync/pages/login.php");
exit();
?>
