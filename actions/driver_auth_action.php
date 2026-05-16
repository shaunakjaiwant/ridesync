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

$action = $_POST['action_type'] ?? '';

function ridesync_driver_register_old_input() {
    $keys = [
        'name',
        'email',
        'phone',
        'license_number',
        'vehicle_type',
        'vehicle_number',
        'seating_capacity',
        'document_reference',
        'aadhaar_reference',
        'pan_reference',
        'id_proof_reference',
        'vehicle_rc_reference',
        'insurance_reference',
        'selfie_reference',
        'vehicle_image_reference',
        'other_document_reference',
        'verification_details',
    ];

    $old = [];
    foreach ($keys as $key) {
        $old[$key] = substr(trim((string) ($_POST[$key] ?? '')), 0, $key === 'verification_details' ? 2000 : 255);
    }

    return $old;
}

function ridesync_driver_register_fail($message) {
    $_SESSION['driver_register_old'] = ridesync_driver_register_old_input();
    $_SESSION['driver_register_error'] = $message;
    header("Location: /ridesync/pages/driver_register.php");
    exit();
}

if (!ridesync_csrf_is_valid()) {
    if ($action === 'register') {
        ridesync_driver_register_fail("Invalid request. Please try again.");
    }

    $_SESSION['driver_auth_error'] = "Invalid request. Please try again.";
    header("Location: /ridesync/pages/driver_login.php");
    exit();
}

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
        ridesync_driver_register_fail("All required fields must be filled.");
    }

    if (strlen($name) > 100 || strlen($email) > 190 || strlen($licenseNumber) > 80
        || strlen($documentReference) > 255 || strlen($idProofReference) > 255
        || strlen($aadhaarReference) > 255 || strlen($panReference) > 255
        || strlen($vehicleRcReference) > 255 || strlen($insuranceReference) > 255
        || strlen($selfieReference) > 255 || strlen($vehicleImageReference) > 255
        || strlen($otherDocumentReference) > 255) {
        ridesync_driver_register_fail("One or more fields are too long.");
    }

    if (!ridesync_is_valid_email($email)) {
        ridesync_driver_register_fail("Please enter a valid email address.");
    }

    if ($password !== $confirm || strlen($password) < 8) {
        ridesync_driver_register_fail("Passwords must match and be at least 8 characters.");
    }

    if (!preg_match('/^[0-9+\- ]{8,20}$/', $phone)) {
        ridesync_driver_register_fail("Please enter a valid phone number.");
    }

    if (!in_array($vehicleType, $vehicleTypes, true) || $seatingCapacity < 1 || $seatingCapacity > 8) {
        ridesync_driver_register_fail("Please enter valid vehicle details.");
    }

    if (!preg_match('/^[A-Z0-9 -]{4,40}$/', $vehicleNumber)) {
        ridesync_driver_register_fail("Vehicle number can use only letters, numbers, spaces, and hyphens.");
    }

    $stmt = mysqli_prepare($conn, "SELECT id FROM users WHERE email = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "s", $email);
    mysqli_stmt_execute($stmt);
    if (mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))) {
        ridesync_driver_register_fail("This email is already used for a rider account. Use a separate driver email.");
    }

    $stmt = mysqli_prepare($conn, "SELECT id FROM driver_accounts WHERE email = ? OR phone = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "ss", $email, $phone);
    mysqli_stmt_execute($stmt);
    if (mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))) {
        ridesync_driver_register_fail("A driver account with this email or phone already exists.");
    }

    $stmt = mysqli_prepare($conn, "SELECT id FROM driver_account_vehicles WHERE vehicle_number = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "s", $vehicleNumber);
    mysqli_stmt_execute($stmt);
    if (mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))) {
        ridesync_driver_register_fail("This vehicle number is already registered.");
    }

    mysqli_begin_transaction($conn);

    $uploadedDocuments = [];
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

        $documentUploadFields = [
            'license' => 'license_file',
            'aadhaar' => 'aadhaar_file',
            'pan' => 'pan_file',
            'id_proof' => 'id_proof_file',
            'vehicle_rc' => 'vehicle_rc_file',
            'insurance' => 'insurance_file',
            'selfie' => 'selfie_file',
            'vehicle_image' => 'vehicle_image_file',
            'other' => 'other_file',
        ];
        foreach ($documentUploadFields as $documentType => $fieldName) {
            $uploaded = ridesync_driver_document_upload($fieldName, $driverId, $documentType);
            if ($uploaded !== null) {
                $uploadedDocuments[$documentType] = $uploaded;
            }
        }

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
        unset($_SESSION['driver_register_old']);
        $_SESSION['selected_role'] = 'driver';
        $_SESSION['driver_register_success'] = "Driver account created. Please login to continue.";
        header("Location: /ridesync/pages/driver_login.php");
        exit();
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        foreach ($uploadedDocuments as $reference) {
            ridesync_driver_document_delete_reference($reference);
        }
        ridesync_driver_register_fail($e instanceof RuntimeException ? $e->getMessage() : "Could not create driver account. Please check your details.");
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
    ridesync_mark_authenticated_session('driver');

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

    ridesync_forget_authenticated_session('ended');
    $_SESSION['selected_role'] = 'driver';
    header("Location: /ridesync/pages/driver_login.php");
    exit();
}

header("Location: /ridesync/pages/driver_login.php");
exit();
?>
