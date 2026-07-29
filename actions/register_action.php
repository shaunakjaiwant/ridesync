<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/email_helper.php';
require_once __DIR__ . '/../includes/http_helper.php';
require_once __DIR__ . '/../includes/rate_limit_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: /ridesync/pages/register.php");
    exit();
}

ridesync_require_csrf('/ridesync/pages/register.php', 'register_error');

// Field names match EXACTLY: name, email, password, confirm_password, college, gender
$name             = trim($_POST['name'] ?? '');
$email            = trim($_POST['email'] ?? '');
$password         = $_POST['password'] ?? '';
$confirm_password = $_POST['confirm_password'] ?? '';
$college          = trim($_POST['college'] ?? '');
$gender           = trim($_POST['gender'] ?? '');
$rateIdentity = ridesync_client_ip() . '|rider_register|' . strtolower($email ?: 'anonymous');
ridesync_enforce_rate_limit('auth:rider_register', 4, 60 * 60, $rateIdentity, [
    'redirect' => '/ridesync/pages/register.php',
    'flash_key' => 'register_error',
    'message' => 'Too many account creation attempts. Please wait before trying again.',
]);

// All fields required
if ($name === '' || $email === '' || $password === '' || $confirm_password === '' || $college === '' || $gender === '') {
    $_SESSION['register_error'] = "All fields are required.";
    header("Location: /ridesync/pages/register.php");
    exit();
}

if (strlen($name) > 100 || strlen($email) > 190 || strlen($college) > 150) {
    $_SESSION['register_error'] = "Name, email, or college value is too long.";
    header("Location: /ridesync/pages/register.php");
    exit();
}

if (!ridesync_is_valid_email($email)) {
    $_SESSION['register_error'] = "Please enter a valid email address.";
    header("Location: /ridesync/pages/register.php");
    exit();
}

if (!in_array($gender, ['Male', 'Female', 'Other'], true)) {
    $_SESSION['register_error'] = "Invalid gender selection.";
    header("Location: /ridesync/pages/register.php");
    exit();
}

// Passwords must match
if ($password !== $confirm_password) {
    $_SESSION['register_error'] = "Passwords do not match.";
    header("Location: /ridesync/pages/register.php");
    exit();
}

// Password minimum length
if (strlen($password) < 8){
    $_SESSION['register_error'] = "Password must be at least 8 characters.";
    header("Location: /ridesync/pages/register.php");
    exit();
}

// Check for duplicate email
$check = mysqli_prepare($conn, "SELECT id FROM users WHERE email = ?");
mysqli_stmt_bind_param($check, "s", $email);
mysqli_stmt_execute($check);
if (mysqli_num_rows(mysqli_stmt_get_result($check)) > 0) {
    $_SESSION['register_error'] = "An account with that email already exists.";
    header("Location: /ridesync/pages/register.php");
    exit();
}

$driverTable = mysqli_query($conn, "SHOW TABLES LIKE 'driver_accounts'");
if ($driverTable && mysqli_num_rows($driverTable) > 0) {
    $driverCheck = mysqli_prepare($conn, "SELECT id FROM driver_accounts WHERE email = ? LIMIT 1");
    if ($driverCheck) {
        mysqli_stmt_bind_param($driverCheck, "s", $email);
        mysqli_stmt_execute($driverCheck);
        if (mysqli_num_rows(mysqli_stmt_get_result($driverCheck)) > 0) {
            $_SESSION['register_error'] = "This email is already used for a driver account. Use a separate rider email.";
            header("Location: /ridesync/pages/register.php");
            exit();
        }
    }
}

// Hash password and insert
$hashed = password_hash($password, PASSWORD_DEFAULT);

$sql = "INSERT INTO users (name, email, password, college, gender) VALUES (?, ?, ?, ?, ?)";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "sssss", $name, $email, $hashed, $college, $gender);

if (mysqli_stmt_execute($stmt)) {
    ridesync_rate_limit_clear('auth:rider_register', $rateIdentity);
    $_SESSION['register_success'] = "Account created! You can now log in.";
    header("Location: /ridesync/pages/login.php");
} else {
    if ((int) mysqli_errno($conn) === 1062) {
        $_SESSION['register_error'] = "An account with that email already exists.";
    } else {
        $_SESSION['register_error'] = "Something went wrong. Please try again.";
    }
    header("Location: /ridesync/pages/register.php");
}
exit();
?>
