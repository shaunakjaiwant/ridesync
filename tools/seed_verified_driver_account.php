<?php
/**
 * RideSync - Seed Verified Driver and User Account
 * 
 * Account details:
 * Email/Username: ridesync@driver.com
 * Password:       12345678
 * Verification:   Verified
 */

define('RIDESYNC_ALLOW_DB_FAILURE', false);
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/driver_account_helper.php';

if (!$conn || !($conn instanceof mysqli)) {
    echo "FAIL: Database connection unavailable.\n";
    exit(1);
}

$email = 'ridesync@driver.com';
$password = '12345678';
$name = 'RideSync Driver';
$phone = '9876543299';
$hash = password_hash($password, PASSWORD_DEFAULT);

echo "========================================================\n";
echo "   RideSync Account Seeder: {$email}\n";
echo "========================================================\n";

// 1. Seed into driver_accounts
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
mysqli_stmt_bind_param($stmt, "ssss", $name, $email, $hash, $phone);
if (!mysqli_stmt_execute($stmt)) {
    echo "FAIL driver_accounts: " . mysqli_error($conn) . "\n";
} else {
    echo "[OK] driver_accounts updated.\n";
}
mysqli_stmt_close($stmt);

// Fetch driver ID
$stmt = mysqli_prepare($conn, "SELECT id FROM driver_accounts WHERE email = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, "s", $email);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$driverRow = mysqli_fetch_assoc($res);
mysqli_stmt_close($stmt);

if (!$driverRow) {
    echo "FAIL: Could not locate driver id for {$email}\n";
    exit(1);
}

$driverId = (int) $driverRow['id'];
echo "[OK] Driver ID: {$driverId}\n";

// 2. Seed into driver_account_profiles
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
mysqli_stmt_bind_param($stmt, "iss", $driverId, $licenseNumber, $verificationDetails);
if (!mysqli_stmt_execute($stmt)) {
    echo "FAIL driver_account_profiles: " . mysqli_error($conn) . "\n";
} else {
    echo "[OK] driver_account_profiles set to verified.\n";
}
mysqli_stmt_close($stmt);

// 3. Seed into driver_account_vehicles
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
mysqli_stmt_bind_param($stmt, "issi", $driverId, $vehicleType, $vehicleNumber, $seatingCapacity);
if (!mysqli_stmt_execute($stmt)) {
    echo "FAIL driver_account_vehicles: " . mysqli_error($conn) . "\n";
} else {
    echo "[OK] driver_account_vehicles updated.\n";
}
mysqli_stmt_close($stmt);

// 4. Seed into driver_account_availability
$stmt = mysqli_prepare($conn, "
    INSERT INTO driver_account_availability (driver_id, status)
    VALUES (?, 'online')
    ON DUPLICATE KEY UPDATE
        status = 'online'
");
mysqli_stmt_bind_param($stmt, "i", $driverId);
if (!mysqli_stmt_execute($stmt)) {
    echo "FAIL driver_account_availability: " . mysqli_error($conn) . "\n";
} else {
    echo "[OK] driver_account_availability set to online.\n";
}
mysqli_stmt_close($stmt);

// 5. Seed required documents as verified
$docs = [
    'license' => 'uploads/driver_documents/verified_license.pdf',
    'aadhaar' => 'uploads/driver_documents/verified_aadhaar.pdf',
    'pan' => 'uploads/driver_documents/verified_pan.pdf',
    'vehicle_rc' => 'uploads/driver_documents/verified_vehicle_rc.pdf',
];

foreach ($docs as $docType => $docRef) {
    $stmt = mysqli_prepare($conn, "
        INSERT INTO driver_account_documents (driver_id, document_type, document_reference, verification_status)
        VALUES (?, ?, ?, 'verified')
        ON DUPLICATE KEY UPDATE
            document_reference = VALUES(document_reference),
            verification_status = 'verified'
    ");
    mysqli_stmt_bind_param($stmt, "iss", $driverId, $docType, $docRef);
    if (!mysqli_stmt_execute($stmt)) {
        echo "FAIL driver_account_documents ({$docType}): " . mysqli_error($conn) . "\n";
    } else {
        echo "[OK] Document '{$docType}' set to verified.\n";
    }
    mysqli_stmt_close($stmt);
}

// 6. Also seed into users table (Rider login)
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
mysqli_stmt_bind_param($stmt, "sssss", $name, $email, $hash, $college, $gender);
if (!mysqli_stmt_execute($stmt)) {
    echo "FAIL users: " . mysqli_error($conn) . "\n";
} else {
    echo "[OK] users (rider) account created and active.\n";
}
mysqli_stmt_close($stmt);

// Verification check
$state = ridesync_fetch_driver_state($conn, $driverId);
$isVerified = ridesync_driver_is_verified($state);
echo "========================================================\n";
echo "Driver Verification Check: " . ($isVerified ? "VERIFIED (TRUE)" : "NOT VERIFIED (FALSE)") . "\n";
echo "Onboarding Status: " . ($state['account']['onboarding_status'] ?? 'N/A') . "\n";
echo "Availability: " . ($state['availability'] ?? 'N/A') . "\n";
echo "Password Verify Test: " . (password_verify($password, $state['account']['password'] ?? $hash) ? "PASSED" : "FAILED") . "\n";
echo "========================================================\n";
