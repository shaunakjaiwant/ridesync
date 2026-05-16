<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/admin_helper.php';
require_once __DIR__ . '/../includes/email_helper.php';
require_once __DIR__ . '/../includes/http_helper.php';
require_once __DIR__ . '/../includes/rate_limit_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: /ridesync/pages/admin_login.php");
    exit();
}

ridesync_require_csrf('/ridesync/pages/admin_login.php', 'admin_error');

$action = $_POST['action_type'] ?? '';

if ($action === 'logout') {
    $_SESSION['admin_success'] = "Admin session ended.";
    ridesync_forget_authenticated_session('ended');
    header("Location: /ridesync/pages/admin_login.php");
    exit();
}

if (!ridesync_admin_schema_ready($conn)) {
    $_SESSION['admin_error'] = "Admin database tables are missing. Import the latest RideSync SQL file.";
    header("Location: /ridesync/pages/admin_login.php");
    exit();
}

if ($action === 'setup') {
    if (ridesync_admin_count($conn) > 0) {
        $_SESSION['admin_error'] = "Admin setup is already complete. Please login.";
        header("Location: /ridesync/pages/admin_login.php");
        exit();
    }

    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    $rateIdentity = ridesync_client_ip() . '|admin_setup|' . strtolower($email ?: 'anonymous');
    ridesync_enforce_rate_limit('auth:admin_setup', 3, 60 * 60, $rateIdentity, [
        'redirect' => '/ridesync/pages/admin_login.php',
        'flash_key' => 'admin_error',
        'message' => 'Too many admin setup attempts. Please wait before trying again.',
    ]);

    if ($name === '' || !ridesync_is_valid_email($email) || strlen($password) < 8 || $password !== $confirm) {
        $_SESSION['admin_error'] = "Enter a valid name, email, and matching password of at least 8 characters.";
        header("Location: /ridesync/pages/admin_login.php");
        exit();
    }

    if (strlen($name) > 100 || strlen($email) > 190) {
        $_SESSION['admin_error'] = "Admin name or email is too long.";
        header("Location: /ridesync/pages/admin_login.php");
        exit();
    }

    $hashed = password_hash($password, PASSWORD_DEFAULT);
    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO admin_users (name, email, password, role, status) VALUES (?, ?, ?, 'super_admin', 'active')"
    );
    mysqli_stmt_bind_param($stmt, "sss", $name, $email, $hashed);

    if (!mysqli_stmt_execute($stmt)) {
        $_SESSION['admin_error'] = "Could not create the admin account. Try another email.";
        header("Location: /ridesync/pages/admin_login.php");
        exit();
    }

    $adminId = (int) mysqli_insert_id($conn);
    session_regenerate_id(true);
    unset($_SESSION['user_id'], $_SESSION['user_name'], $_SESSION['driver_id'], $_SESSION['driver_name']);
    $_SESSION['admin_id'] = $adminId;
    $_SESSION['admin_name'] = $name;
    $_SESSION['admin_role'] = 'super_admin';
    ridesync_mark_authenticated_session('admin');
    ridesync_rate_limit_clear('auth:admin_setup', $rateIdentity);
    ridesync_admin_log($conn, $adminId, 'setup_admin', 'admin_user', $adminId, 'First admin account created.');

    header("Location: /ridesync/pages/admin_dashboard.php");
    exit();
}

if ($action === 'login') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $rateIdentity = ridesync_client_ip() . '|admin|' . strtolower($email ?: 'anonymous');
    ridesync_enforce_rate_limit('auth:admin_login', 6, 15 * 60, $rateIdentity, [
        'redirect' => '/ridesync/pages/admin_login.php',
        'flash_key' => 'admin_error',
        'message' => 'Too many admin login attempts. Please wait a few minutes and try again.',
    ]);

    if (!ridesync_is_valid_email($email) || $password === '') {
        $_SESSION['admin_error'] = "Enter a valid admin email and password.";
        header("Location: /ridesync/pages/admin_login.php");
        exit();
    }

    $stmt = mysqli_prepare($conn, "SELECT id, name, email, password, role, status FROM admin_users WHERE email = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "s", $email);
    mysqli_stmt_execute($stmt);
    $admin = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if (!$admin || !password_verify($password, $admin['password'])) {
        $_SESSION['admin_error'] = "Invalid admin email or password.";
        header("Location: /ridesync/pages/admin_login.php");
        exit();
    }

    if ($admin['status'] !== 'active') {
        $_SESSION['admin_error'] = "This admin account is inactive.";
        header("Location: /ridesync/pages/admin_login.php");
        exit();
    }

    session_regenerate_id(true);
    unset($_SESSION['user_id'], $_SESSION['user_name'], $_SESSION['driver_id'], $_SESSION['driver_name']);
    $_SESSION['admin_id'] = (int) $admin['id'];
    $_SESSION['admin_name'] = $admin['name'];
    $_SESSION['admin_role'] = $admin['role'];
    ridesync_mark_authenticated_session('admin');
    ridesync_rate_limit_clear('auth:admin_login', $rateIdentity);

    header("Location: /ridesync/pages/admin_dashboard.php");
    exit();
}

header("Location: /ridesync/pages/admin_login.php");
exit();
?>
