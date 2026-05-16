<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/email_helper.php';
require_once __DIR__ . '/../includes/driver_account_helper.php';
require_once __DIR__ . '/../includes/driver_document_helper.php';
require_once __DIR__ . '/../includes/verification_helper.php';
require_once __DIR__ . '/../includes/http_helper.php';
require_once __DIR__ . '/../includes/rate_limit_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: /ridesync/pages/driver_login.php");
    exit();
}

ridesync_require_csrf('/ridesync/pages/driver_login.php', 'driver_auth_error');

$action = $_POST['action_type'] ?? '';

if ($action === 'register') {
    if (!ridesync_driver_schema_ready($conn)) {
        $_SESSION['driver_register_error'] = "Driver database tables are missing. Run the dedicated driver migration first.";
        header("Location: /ridesync/pages/driver_register.php");
        exit();
    }

    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    $licenseNumber = strtoupper(trim($_POST['license_number'] ?? ''));
    $vehicleType = trim($_POST['vehicle_type'] ?? '');
    $vehicleNumber = strtoupper(trim($_POST['vehicle_number'] ?? ''));
    $seatingCapacity = (int) ($_POST['seating_capacity'] ?? 0);
    $documentReference = trim($_POST['document_reference'] ?? '');
    $aadhaarReference = trim($_POST['aadhaar_reference'] ?? '');
    $panReference = trim($_POST['pan_reference'] ?? '');
    $idProofReference = trim($_POST['id_proof_reference'] ?? '');
    $vehicleRcReference = trim($_POST['vehicle_rc_reference'] ?? '');
    $insuranceReference = trim($_POST['insurance_reference'] ?? '');
    $selfieReference = trim($_POST['selfie_reference'] ?? '');
    $vehicleImageReference = trim($_POST['vehicle_image_reference'] ?? '');
    $otherDocumentReference = trim($_POST['other_document_reference'] ?? '');
    $verificationDetails = trim($_POST['verification_details'] ?? '');
    $rateIdentity = ridesync_client_ip() . '|driver_register|' . strtolower($email ?: 'anonymous');
    ridesync_enforce_rate_limit('auth:driver_register', 4, 60 * 60, $rateIdentity, [
        'redirect' => '/ridesync/pages/driver_register.php',
        'flash_key' => 'driver_register_error',
        'message' => 'Too many driver account creation attempts. Please wait before trying again.',
    ]);

    $vehicleTypes = ['Bike', 'Car', 'Auto', 'Van', 'Other'];

    if ($name === '' || $email === '' || $phone === '' || $password === '' || $confirm === '' || $licenseNumber === '' || $vehicleType === '' || $vehicleNumber === '') {
        $_SESSION['driver_register_error'] = "All required fields must be filled.";
        header("Location: /ridesync/pages/driver_register.php");
        exit();
    }

    if (strlen($name) > 100 || strlen($email) > 190 || strlen($licenseNumber) > 80
        || strlen($documentReference) > 255 || strlen($idProofReference) > 255
        || strlen($aadhaarReference) > 255 || strlen($panReference) > 255
        || strlen($vehicleRcReference) > 255 || strlen($insuranceReference) > 255
        || strlen($selfieReference) > 255 || strlen($vehicleImageReference) > 255
        || strlen($otherDocumentReference) > 255) {
        $_SESSION['driver_register_error'] = "One or more fields are too long.";
        header("Location: /ridesync/pages/driver_register.php");
        exit();
    }

    if (!ridesync_is_valid_email($email)) {
        $_SESSION['driver_register_error'] = "Please enter a valid email address.";
        header("Location: /ridesync/pages/driver_register.php");
        exit();
    }

    if ($password !== $confirm || strlen($password) < 8) {
        $_SESSION['driver_register_error'] = "Passwords must match and be at least 8 characters.";
        header("Location: /ridesync/pages/driver_register.php");
        exit();
    }

    if (!preg_match('/^[0-9+\- ]{8,20}$/', $phone)) {
        $_SESSION['driver_register_error'] = "Please enter a valid phone number.";
        header("Location: /ridesync/pages/driver_register.php");
        exit();
    }

    if (!in_array($vehicleType, $vehicleTypes, true) || $seatingCapacity < 1 || $seatingCapacity > 8) {
        $_SESSION['driver_register_error'] = "Please enter valid vehicle details.";
        header("Location: /ridesync/pages/driver_register.php");
        exit();
    }

    if (!preg_match('/^[A-Z0-9 -]{4,40}$/', $vehicleNumber)) {
        $_SESSION['driver_register_error'] = "Vehicle number can use only letters, numbers, spaces, and hyphens.";
        header("Location: /ridesync/pages/driver_register.php");
        exit();
    }

    $stmt = mysqli_prepare($conn, "SELECT id FROM users WHERE email = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "s", $email);
    mysqli_stmt_execute($stmt);
    if (mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))) {
        $_SESSION['driver_register_error'] = "This email is already used for a rider account. Use a separate driver email.";
        header("Location: /ridesync/pages/driver_register.php");
        exit();
    }

    $stmt = mysqli_prepare($conn, "SELECT id FROM driver_accounts WHERE email = ? OR phone = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "ss", $email, $phone);
    mysqli_stmt_execute($stmt);
    if (mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))) {
        $_SESSION['driver_register_error'] = "A driver account with this email or phone already exists.";
        header("Location: /ridesync/pages/driver_register.php");
        exit();
    }

    $stmt = mysqli_prepare($conn, "SELECT id FROM driver_account_vehicles WHERE vehicle_number = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "s", $vehicleNumber);
    mysqli_stmt_execute($stmt);
    if (mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))) {
        $_SESSION['driver_register_error'] = "This vehicle number is already registered.";
        header("Location: /ridesync/pages/driver_register.php");
        exit();
    }

    mysqli_begin_transaction($conn);

    try {
        $hashed = password_hash($password, PASSWORD_DEFAULT);

        $stmt = mysqli_prepare($conn, "INSERT INTO driver_accounts (name, email, password, phone, status, onboarding_status) VALUES (?, ?, ?, ?, 'active', 'complete')");
        mysqli_stmt_bind_param($stmt, "ssss", $name, $email, $hashed, $phone);
        mysqli_stmt_execute($stmt);
        $driverId = mysqli_insert_id($conn);

        $stmt = mysqli_prepare($conn, "INSERT INTO driver_account_profiles (driver_id, license_number, verification_details) VALUES (?, ?, ?)");
        mysqli_stmt_bind_param($stmt, "iss", $driverId, $licenseNumber, $verificationDetails);
        mysqli_stmt_execute($stmt);

        $stmt = mysqli_prepare($conn, "INSERT INTO driver_account_vehicles (driver_id, vehicle_type, vehicle_number, seating_capacity) VALUES (?, ?, ?, ?)");
        mysqli_stmt_bind_param($stmt, "issi", $driverId, $vehicleType, $vehicleNumber, $seatingCapacity);
        mysqli_stmt_execute($stmt);

        $uploadedDocuments = [
            'license' => ridesync_driver_document_upload('license_file', $driverId, 'license'),
            'aadhaar' => ridesync_driver_document_upload('aadhaar_file', $driverId, 'aadhaar'),
            'pan' => ridesync_driver_document_upload('pan_file', $driverId, 'pan'),
            'id_proof' => ridesync_driver_document_upload('id_proof_file', $driverId, 'id_proof'),
            'vehicle_rc' => ridesync_driver_document_upload('vehicle_rc_file', $driverId, 'vehicle_rc'),
            'insurance' => ridesync_driver_document_upload('insurance_file', $driverId, 'insurance'),
            'selfie' => ridesync_driver_document_upload('selfie_file', $driverId, 'selfie'),
            'vehicle_image' => ridesync_driver_document_upload('vehicle_image_file', $driverId, 'vehicle_image'),
            'other' => ridesync_driver_document_upload('other_file', $driverId, 'other'),
        ];

        $documentReferences = [
            'license' => $uploadedDocuments['license'] ?? $documentReference,
            'aadhaar' => $uploadedDocuments['aadhaar'] ?? $aadhaarReference,
            'pan' => $uploadedDocuments['pan'] ?? $panReference,
            'id_proof' => $uploadedDocuments['id_proof'] ?? $idProofReference,
            'vehicle_rc' => $uploadedDocuments['vehicle_rc'] ?? $vehicleRcReference,
            'insurance' => $uploadedDocuments['insurance'] ?? $insuranceReference,
            'selfie' => $uploadedDocuments['selfie'] ?? $selfieReference,
            'vehicle_image' => $uploadedDocuments['vehicle_image'] ?? $vehicleImageReference,
            'other' => $uploadedDocuments['other'] ?? $otherDocumentReference,
        ];

        foreach ($documentReferences as $documentType => $reference) {
            if ($reference === '') {
                continue;
            }

            $stmt = mysqli_prepare($conn, "INSERT INTO driver_account_documents (driver_id, document_type, document_reference) VALUES (?, ?, ?)");
            mysqli_stmt_bind_param($stmt, "iss", $driverId, $documentType, $reference);
            mysqli_stmt_execute($stmt);
        }

        $stmt = mysqli_prepare($conn, "INSERT INTO driver_account_availability (driver_id, status) VALUES (?, 'offline')");
        mysqli_stmt_bind_param($stmt, "i", $driverId);
        mysqli_stmt_execute($stmt);

        mysqli_commit($conn);
        ridesync_verification_start_for_driver($conn, $driverId, 'driver_registration');
        ridesync_rate_limit_clear('auth:driver_register', $rateIdentity);
        $_SESSION['selected_role'] = 'driver';
        $_SESSION['driver_register_success'] = "Driver account created. Please login to continue.";
        header("Location: /ridesync/pages/driver_login.php");
        exit();
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        $_SESSION['driver_register_error'] = "Could not create driver account. Please check your details.";
        header("Location: /ridesync/pages/driver_register.php");
        exit();
    }
}

if ($action === 'login') {
    if (!ridesync_driver_schema_ready($conn)) {
        $_SESSION['driver_auth_error'] = "Driver database tables are missing. Run the dedicated driver migration first.";
        header("Location: /ridesync/pages/driver_login.php");
        exit();
    }

    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $rateIdentity = ridesync_client_ip() . '|driver|' . strtolower($email ?: 'anonymous');
    ridesync_enforce_rate_limit('auth:driver_login', 8, 15 * 60, $rateIdentity, [
        'redirect' => '/ridesync/pages/driver_login.php',
        'flash_key' => 'driver_auth_error',
        'message' => 'Too many driver login attempts. Please wait a few minutes and try again.',
    ]);

    if (!ridesync_is_valid_email($email) || $password === '') {
        $_SESSION['driver_auth_error'] = "Enter a valid email and password.";
        header("Location: /ridesync/pages/driver_login.php");
        exit();
    }

    $stmt = mysqli_prepare($conn, "SELECT id, name, password, status, onboarding_status FROM driver_accounts WHERE email = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "s", $email);
    mysqli_stmt_execute($stmt);
    $driver = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if (!$driver || !password_verify($password, $driver['password'])) {
        $_SESSION['driver_auth_error'] = "Invalid driver email or password.";
        header("Location: /ridesync/pages/driver_login.php");
        exit();
    }

    if ($driver['status'] === 'suspended' || $driver['status'] === 'inactive') {
        $_SESSION['driver_auth_error'] = "This driver account cannot access the dashboard right now. Please contact support.";
        header("Location: /ridesync/pages/driver_login.php");
        exit();
    }

    ridesync_rate_limit_clear('auth:driver_login', $rateIdentity);
    session_regenerate_id(true);
    unset($_SESSION['user_id'], $_SESSION['user_name'], $_SESSION['admin_id'], $_SESSION['admin_name'], $_SESSION['admin_role']);
    $_SESSION['selected_role'] = 'driver';
    $_SESSION['driver_id'] = (int) $driver['id'];
    $_SESSION['driver_name'] = $driver['name'];

    $stmt = mysqli_prepare($conn,
        "INSERT INTO driver_account_availability (driver_id, status, current_lat, current_lng, last_changed_at)
         VALUES (?, 'offline', NULL, NULL, CURRENT_TIMESTAMP)
         ON DUPLICATE KEY UPDATE status = 'offline', current_lat = NULL, current_lng = NULL, last_changed_at = CURRENT_TIMESTAMP"
    );
    mysqli_stmt_bind_param($stmt, "i", $_SESSION['driver_id']);
    mysqli_stmt_execute($stmt);

    if ($driver['onboarding_status'] !== 'complete') {
        header("Location: /ridesync/pages/driver_profile.php");
        exit();
    }

    header("Location: /ridesync/pages/driver_dashboard.php");
    exit();
}

if ($action === 'logout') {
    if (isset($_SESSION['driver_id']) && ridesync_driver_schema_ready($conn)) {
        $driverId = (int) $_SESSION['driver_id'];
        $stmt = mysqli_prepare($conn, "UPDATE driver_account_availability SET status = 'offline', current_lat = NULL, current_lng = NULL, last_changed_at = CURRENT_TIMESTAMP WHERE driver_id = ?");
        mysqli_stmt_bind_param($stmt, "i", $driverId);
        mysqli_stmt_execute($stmt);
    }

    unset($_SESSION['driver_id'], $_SESSION['driver_name']);
    $_SESSION['selected_role'] = 'driver';
    header("Location: /ridesync/pages/driver_login.php");
    exit();
}

header("Location: /ridesync/pages/driver_login.php");
exit();
?>
