<?php
define('RIDESYNC_ALLOW_DB_FAILURE', true);
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/driver_account_helper.php';
require_once __DIR__ . '/../includes/http_helper.php';

header('Content-Type: application/json');

if (!$conn || !($conn instanceof mysqli)) {
    echo json_encode(['ok' => false, 'error' => 'Database connection failed']);
    exit;
}

$email = 'ridesync@driver.com';
$password = '12345678';
$name = 'RideSync Driver';
$phone = '9876543299';
$hash = password_hash($password, PASSWORD_DEFAULT);

$results = [];

// 1. driver_accounts
$stmt = mysqli_prepare($conn, "
    INSERT INTO driver_accounts (name, email, password, phone, status, onboarding_status)
    VALUES (?, ?, ?, ?, 'active', 'complete')
    ON DUPLICATE KEY UPDATE
        name = VALUES(name),
        password = VALUES(password),
        phone = VALUES(phone),
        status = 'active',
        onboarding_status = 'complete'
");
if ($stmt) {
    mysqli_stmt_bind_param($stmt, "ssss", $name, $email, $hash, $phone);
    $res = mysqli_stmt_execute($stmt);
    $results['driver_accounts'] = $res ? 'success' : mysqli_error($conn);
    mysqli_stmt_close($stmt);
} else {
    $results['driver_accounts'] = mysqli_error($conn);
}

// Fetch driver ID
$stmt = mysqli_prepare($conn, "SELECT id FROM driver_accounts WHERE email = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, "s", $email);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$driverRow = mysqli_fetch_assoc($res);
mysqli_stmt_close($stmt);

if (!$driverRow) {
    echo json_encode(['ok' => false, 'error' => 'Could not find driver id', 'results' => $results]);
    exit;
}

$driverId = (int) $driverRow['id'];
$results['driver_id'] = $driverId;

// 2. driver_account_profiles
$licenseNumber = 'KA19DL2026888';
$verificationDetails = 'Verified official RideSync Driver Account';
$stmt = mysqli_prepare($conn, "
    INSERT INTO driver_account_profiles (driver_id, license_number, verification_status, verification_details)
    VALUES (?, ?, 'verified', ?)
    ON DUPLICATE KEY UPDATE
        license_number = VALUES(license_number),
        verification_status = 'verified',
        verification_details = VALUES(verification_details)
");
if ($stmt) {
    mysqli_stmt_bind_param($stmt, "iss", $driverId, $licenseNumber, $verificationDetails);
    $res = mysqli_stmt_execute($stmt);
    $results['driver_account_profiles'] = $res ? 'success' : mysqli_error($conn);
    mysqli_stmt_close($stmt);
}

// 3. driver_account_vehicles
$vehicleType = 'Car';
$vehicleNumber = 'KA 19 RS 1234';
$seatingCapacity = 4;
$stmt = mysqli_prepare($conn, "
    INSERT INTO driver_account_vehicles (driver_id, vehicle_type, vehicle_number, seating_capacity)
    VALUES (?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        vehicle_type = VALUES(vehicle_type),
        vehicle_number = VALUES(vehicle_number),
        seating_capacity = VALUES(seating_capacity)
");
if ($stmt) {
    mysqli_stmt_bind_param($stmt, "issi", $driverId, $vehicleType, $vehicleNumber, $seatingCapacity);
    $res = mysqli_stmt_execute($stmt);
    $results['driver_account_vehicles'] = $res ? 'success' : mysqli_error($conn);
    mysqli_stmt_close($stmt);
}

// 4. driver_account_availability
$stmt = mysqli_prepare($conn, "
    INSERT INTO driver_account_availability (driver_id, status)
    VALUES (?, 'online')
    ON DUPLICATE KEY UPDATE
        status = 'online'
");
if ($stmt) {
    mysqli_stmt_bind_param($stmt, "i", $driverId);
    $res = mysqli_stmt_execute($stmt);
    $results['driver_account_availability'] = $res ? 'success' : mysqli_error($conn);
    mysqli_stmt_close($stmt);
}

// 5. driver_account_documents
$docs = [
    'license' => 'uploads/driver_documents/verified_license.pdf',
    'aadhaar' => 'uploads/driver_documents/verified_aadhaar.pdf',
    'pan' => 'uploads/driver_documents/verified_pan.pdf',
    'vehicle_rc' => 'uploads/driver_documents/verified_vehicle_rc.pdf',
];
$results['documents'] = [];
foreach ($docs as $docType => $docRef) {
    $stmt = mysqli_prepare($conn, "
        INSERT INTO driver_account_documents (driver_id, document_type, document_reference, verification_status)
        VALUES (?, ?, ?, 'verified')
        ON DUPLICATE KEY UPDATE
            document_reference = VALUES(document_reference),
            verification_status = 'verified'
    ");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "iss", $driverId, $docType, $docRef);
        $res = mysqli_stmt_execute($stmt);
        $results['documents'][$docType] = $res ? 'verified' : mysqli_error($conn);
        mysqli_stmt_close($stmt);
    }
}

// 6. users table (Rider)
$college = 'SDM Institute of Technology';
$gender = 'Male';
$stmt = mysqli_prepare($conn, "
    INSERT INTO users (name, email, password, college, gender, status)
    VALUES (?, ?, ?, ?, ?, 'active')
    ON DUPLICATE KEY UPDATE
        name = VALUES(name),
        password = VALUES(password),
        college = VALUES(college),
        gender = VALUES(gender),
        status = 'active'
");
if ($stmt) {
    mysqli_stmt_bind_param($stmt, "sssss", $name, $email, $hash, $college, $gender);
    $res = mysqli_stmt_execute($stmt);
    $results['users'] = $res ? 'success' : mysqli_error($conn);
    mysqli_stmt_close($stmt);
}

// 7. Verify driver state
$state = ridesync_fetch_driver_state($conn, $driverId);
$isVerified = ridesync_driver_is_verified($state);

echo json_encode([
    'ok' => true,
    'account' => [
        'email' => $email,
        'role_driver' => true,
        'role_rider' => true,
        'driver_id' => $driverId,
        'is_verified' => $isVerified,
        'onboarding_status' => $state['account']['onboarding_status'] ?? null,
        'availability' => $state['availability'] ?? null,
        'vehicle' => $state['vehicle'] ?? null,
        'documents' => array_keys($state['documents'] ?? []),
    ],
    'operations' => $results,
], JSON_PRETTY_PRINT);
