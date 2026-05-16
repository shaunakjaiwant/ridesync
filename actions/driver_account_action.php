<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/driver_account_helper.php';
require_once __DIR__ . '/../includes/driver_document_helper.php';
require_once __DIR__ . '/../includes/matching_helper.php';
require_once __DIR__ . '/../includes/driver_opportunity_helper.php';
require_once __DIR__ . '/../includes/wallet_helper.php';
require_once __DIR__ . '/../includes/redirect_helper.php';
require_once __DIR__ . '/../includes/verification_helper.php';

function ridesync_driver_account_redirect($default) {
    ridesync_redirect_back($default);
}

function ridesync_driver_account_fail($message, $default) {
    $_SESSION['driver_error'] = $message;
    ridesync_driver_account_redirect($default);
}

ridesync_require_driver_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: /ridesync/pages/driver_dashboard.php");
    exit();
}

if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
    $_SESSION['driver_error'] = "Invalid request. Please try again.";
    header("Location: /ridesync/pages/driver_dashboard.php");
    exit();
}

if (!ridesync_driver_schema_ready($conn)) {
    $_SESSION['driver_error'] = "Driver database tables are missing.";
    header("Location: /ridesync/pages/driver_dashboard.php");
    exit();
}

$driverId = (int) $_SESSION['driver_id'];
$action = $_POST['action_type'] ?? '';

if ($action === 'toggle_availability') {
    $status = $_POST['status'] ?? 'offline';
    $currentLat = ridesync_float_or_null($_POST['current_lat'] ?? null);
    $currentLng = ridesync_float_or_null($_POST['current_lng'] ?? null);

    if (!in_array($status, ['online', 'offline'], true)) {
        $_SESSION['driver_error'] = "Invalid availability status.";
        header("Location: /ridesync/pages/driver_dashboard.php");
        exit();
    }

    if (($currentLat !== null && ($currentLat < -90 || $currentLat > 90)) || ($currentLng !== null && ($currentLng < -180 || $currentLng > 180))) {
        $currentLat = null;
        $currentLng = null;
    }

    $state = ridesync_fetch_driver_state($conn, $driverId);
    if (!ridesync_driver_onboarding_complete($state)) {
        $_SESSION['driver_error'] = "Complete your driver profile before going online.";
        header("Location: /ridesync/pages/driver_profile.php");
        exit();
    }

    if ($status === 'online' && !ridesync_driver_is_verified($state)) {
        $_SESSION['driver_error'] = "Your driver verification must be approved by admin before going online.";
        header("Location: /ridesync/pages/driver_profile.php");
        exit();
    }

    $stmt = mysqli_prepare($conn,
        "INSERT INTO driver_account_availability (driver_id, status, current_lat, current_lng, last_changed_at)
         VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)
         ON DUPLICATE KEY UPDATE status = VALUES(status), current_lat = VALUES(current_lat), current_lng = VALUES(current_lng), last_changed_at = CURRENT_TIMESTAMP"
    );
    mysqli_stmt_bind_param($stmt, "isdd", $driverId, $status, $currentLat, $currentLng);
    mysqli_stmt_execute($stmt);

    $_SESSION['driver_success'] = "You are now " . $status . ".";
    header("Location: /ridesync/pages/driver_dashboard.php");
    exit();
}

if ($action === 'update_profile') {
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
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

    if ($name === '' || $phone === '' || $licenseNumber === '' || $vehicleType === '' || $vehicleNumber === '') {
        $_SESSION['driver_error'] = "All required profile and vehicle fields must be filled.";
        header("Location: /ridesync/pages/driver_profile.php");
        exit();
    }

    if (strlen($name) > 100 || strlen($licenseNumber) > 80
        || strlen($documentReference) > 255 || strlen($idProofReference) > 255
        || strlen($aadhaarReference) > 255 || strlen($panReference) > 255
        || strlen($vehicleRcReference) > 255 || strlen($insuranceReference) > 255
        || strlen($selfieReference) > 255 || strlen($vehicleImageReference) > 255
        || strlen($otherDocumentReference) > 255) {
        $_SESSION['driver_error'] = "One or more profile fields are too long.";
        header("Location: /ridesync/pages/driver_profile.php");
        exit();
    }

    if (!preg_match('/^[0-9+\- ]{8,20}$/', $phone) || !preg_match('/^[A-Z0-9 -]{4,40}$/', $vehicleNumber)) {
        $_SESSION['driver_error'] = "Check your phone number and vehicle number.";
        header("Location: /ridesync/pages/driver_profile.php");
        exit();
    }

    if (!in_array($vehicleType, ['Bike', 'Car', 'Auto', 'Van', 'Other'], true) || $seatingCapacity < 1 || $seatingCapacity > 8) {
        $_SESSION['driver_error'] = "Check your vehicle type and seating capacity.";
        header("Location: /ridesync/pages/driver_profile.php");
        exit();
    }

    $stmt = mysqli_prepare($conn, "SELECT id FROM driver_accounts WHERE phone = ? AND id != ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "si", $phone, $driverId);
    mysqli_stmt_execute($stmt);
    if (mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))) {
        $_SESSION['driver_error'] = "That phone number is already used by another driver.";
        header("Location: /ridesync/pages/driver_profile.php");
        exit();
    }

    $stmt = mysqli_prepare($conn, "SELECT id FROM driver_account_vehicles WHERE vehicle_number = ? AND driver_id != ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "si", $vehicleNumber, $driverId);
    mysqli_stmt_execute($stmt);
    if (mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))) {
        $_SESSION['driver_error'] = "That vehicle number is already registered by another driver.";
        header("Location: /ridesync/pages/driver_profile.php");
        exit();
    }

    mysqli_begin_transaction($conn);
    try {
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

        $stmt = mysqli_prepare($conn, "UPDATE driver_accounts SET name = ?, phone = ?, onboarding_status = 'complete' WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "ssi", $name, $phone, $driverId);
        mysqli_stmt_execute($stmt);

        $stmt = mysqli_prepare($conn,
            "INSERT INTO driver_account_profiles (driver_id, license_number, verification_details, verification_status)
             VALUES (?, ?, ?, 'pending')
             ON DUPLICATE KEY UPDATE license_number = VALUES(license_number), verification_details = VALUES(verification_details), verification_status = 'pending'"
        );
        mysqli_stmt_bind_param($stmt, "iss", $driverId, $licenseNumber, $verificationDetails);
        mysqli_stmt_execute($stmt);

        $stmt = mysqli_prepare($conn,
            "INSERT INTO driver_account_vehicles (driver_id, vehicle_type, vehicle_number, seating_capacity)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE vehicle_type = VALUES(vehicle_type), vehicle_number = VALUES(vehicle_number), seating_capacity = VALUES(seating_capacity)"
        );
        mysqli_stmt_bind_param($stmt, "issi", $driverId, $vehicleType, $vehicleNumber, $seatingCapacity);
        mysqli_stmt_execute($stmt);

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

            $stmt = mysqli_prepare($conn, "DELETE FROM driver_account_documents WHERE driver_id = ? AND document_type = ?");
            mysqli_stmt_bind_param($stmt, "is", $driverId, $documentType);
            mysqli_stmt_execute($stmt);

            $stmt = mysqli_prepare($conn, "INSERT INTO driver_account_documents (driver_id, document_type, document_reference) VALUES (?, ?, ?)");
            mysqli_stmt_bind_param($stmt, "iss", $driverId, $documentType, $reference);
            mysqli_stmt_execute($stmt);
        }

        $stmt = mysqli_prepare(
            $conn,
            "UPDATE driver_account_availability
             SET status = 'offline', current_lat = NULL, current_lng = NULL, last_changed_at = CURRENT_TIMESTAMP
             WHERE driver_id = ?"
        );
        mysqli_stmt_bind_param($stmt, "i", $driverId);
        mysqli_stmt_execute($stmt);

        mysqli_commit($conn);
        ridesync_verification_start_for_driver($conn, $driverId, 'driver_profile_update');
        $_SESSION['driver_name'] = $name;
        $_SESSION['driver_success'] = "Driver profile updated and sent for admin review.";
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        $_SESSION['driver_error'] = "Could not update driver profile.";
    }

    header("Location: /ridesync/pages/driver_profile.php");
    exit();
}

if ($action === 'claim_community_ride') {
    $rideId = (int) ($_POST['ride_id'] ?? 0);
    $returnTo = "/ridesync/pages/driver_requests.php#community-rides";

    if ($rideId <= 0) {
        ridesync_driver_account_fail("Choose a valid posted ride.", $returnTo);
    }

    if (!ridesync_table_exists($conn, 'rides') || !ridesync_table_exists($conn, 'ride_live_status')) {
        ridesync_driver_account_fail("Posted ride pool is not ready yet.", $returnTo);
    }

    $state = ridesync_fetch_driver_state($conn, $driverId);

    if (!ridesync_driver_onboarding_complete($state)) {
        $_SESSION['driver_error'] = "Complete your driver profile before driving posted rides.";
        header("Location: /ridesync/pages/driver_profile.php");
        exit();
    }

    if (!ridesync_driver_is_verified($state)) {
        $_SESSION['driver_error'] = "Admin verification is required before driving posted rides.";
        header("Location: /ridesync/pages/driver_profile.php");
        exit();
    }

    if (($state['availability'] ?? 'offline') !== 'online') {
        $_SESSION['driver_error'] = "Go online first so riders can see you are available.";
        header("Location: /ridesync/pages/driver_dashboard.php");
        exit();
    }

    mysqli_begin_transaction($conn);

    try {
        $stmt = mysqli_prepare($conn,
            "SELECT r.*, u.name AS poster_name
             FROM rides r
             JOIN users u ON u.id = r.user_id
             WHERE r.id = ?
             FOR UPDATE"
        );
        mysqli_stmt_bind_param($stmt, "i", $rideId);
        mysqli_stmt_execute($stmt);
        $ride = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

        if (!$ride) {
            throw new RuntimeException("Ride not found.");
        }

        if (!in_array(($ride['status'] ?? ''), ['open', 'closed'], true)) {
            throw new RuntimeException("This ride is no longer available.");
        }

        $travelTimestamp = strtotime(($ride['travel_date'] ?? '') . ' ' . ($ride['travel_time'] ?? ''));
        if ($travelTimestamp && $travelTimestamp < (time() - 1800)) {
            throw new RuntimeException("This ride time has already passed.");
        }

        $stmt = mysqli_prepare($conn, "SELECT driver_id, live_status FROM ride_live_status WHERE ride_id = ? FOR UPDATE");
        mysqli_stmt_bind_param($stmt, "i", $rideId);
        mysqli_stmt_execute($stmt);
        $liveState = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

        if ($liveState && !empty($liveState['driver_id']) && (int) $liveState['driver_id'] !== $driverId) {
            throw new RuntimeException("Another driver already picked up this posted ride.");
        }

        if ($liveState && in_array($liveState['live_status'], ['active', 'completed', 'cancelled'], true)) {
            throw new RuntimeException("This ride cannot be picked up now.");
        }

        $etaMinutes = ridesync_driver_community_eta($ride, $state);
        $fareEstimate = ridesync_driver_community_fare($ride);
        $driverName = $_SESSION['driver_name'] ?? ($state['account']['name'] ?? 'Your driver');
        $note = $driverName . ' accepted this posted ride from the driver app.';

        ridesync_update_live_status($conn, $rideId, 'driver_assigned', $note, $driverId, $etaMinutes);

        ridesync_create_notification(
            $conn,
            (int) $ride['user_id'],
            $driverId,
            'Driver assigned to your posted ride',
            $driverName . ' accepted your posted ride from ' . $ride['origin'] . ' to ' . $ride['destination'] . '. Estimated ride value: ' . html_entity_decode(formatCost($fareEstimate), ENT_QUOTES, 'UTF-8') . '.'
        );

        if (ridesync_table_exists($conn, 'matches')) {
            $stmt = mysqli_prepare($conn, "SELECT matched_user_id FROM matches WHERE ride_id = ? AND status = 'accepted'");
            mysqli_stmt_bind_param($stmt, "i", $rideId);
            mysqli_stmt_execute($stmt);
            $acceptedRiders = mysqli_stmt_get_result($stmt);
            while ($rider = mysqli_fetch_assoc($acceptedRiders)) {
                ridesync_create_notification(
                    $conn,
                    (int) $rider['matched_user_id'],
                    $driverId,
                    'Driver assigned to your ride',
                    $driverName . ' is now assigned to the ride from ' . $ride['origin'] . ' to ' . $ride['destination'] . '.'
                );
            }
        }

        mysqli_commit($conn);
        $_SESSION['driver_success'] = "Posted ride connected. You are now assigned to this ride.";
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        $_SESSION['driver_error'] = $e instanceof RuntimeException ? $e->getMessage() : "Could not connect you to this posted ride.";
    }

    ridesync_driver_account_redirect($returnTo);
}

if ($action === 'respond_request') {
    $requestId = (int) ($_POST['request_id'] ?? 0);
    $decision = $_POST['decision'] ?? '';

    if ($requestId <= 0 || !in_array($decision, ['accepted', 'rejected'], true)) {
        $_SESSION['driver_error'] = "Invalid ride request action.";
        header("Location: /ridesync/pages/driver_requests.php");
        exit();
    }

    if ($decision === 'accepted') {
        $state = ridesync_fetch_driver_state($conn, $driverId);

        if (!ridesync_driver_onboarding_complete($state) || !ridesync_driver_is_verified($state)) {
            $_SESSION['driver_error'] = "Admin verification is required before accepting requests.";
            header("Location: /ridesync/pages/driver_profile.php");
            exit();
        }

        if (($state['availability'] ?? 'offline') !== 'online') {
            $_SESSION['driver_error'] = "Go online before accepting a ride request.";
            header("Location: /ridesync/pages/driver_dashboard.php");
            exit();
        }
    }

    $stmt = mysqli_prepare($conn,
        "SELECT rider_user_id, pickup, drop_location, estimated_fare, route_distance_km
         FROM driver_ride_requests
         WHERE id = ? AND driver_id = ? AND request_status = 'pending'
         LIMIT 1"
    );
    mysqli_stmt_bind_param($stmt, "ii", $requestId, $driverId);
    mysqli_stmt_execute($stmt);
    $request = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if (!$request) {
        $_SESSION['driver_error'] = "Ride request is no longer available.";
        header("Location: /ridesync/pages/driver_requests.php");
        exit();
    }

    $stmt = mysqli_prepare($conn,
        "UPDATE driver_ride_requests
         SET request_status = ?, responded_at = CURRENT_TIMESTAMP
         WHERE id = ? AND driver_id = ? AND request_status = 'pending'"
    );
    mysqli_stmt_bind_param($stmt, "sii", $decision, $requestId, $driverId);
    mysqli_stmt_execute($stmt);

    if (!empty($request['rider_user_id'])) {
        ridesync_create_notification(
            $conn,
            (int) $request['rider_user_id'],
            null,
            $decision === 'accepted' ? 'Driver accepted your request' : 'Driver rejected your request',
            $decision === 'accepted'
                ? 'Your driver accepted the ride from ' . $request['pickup'] . ' to ' . $request['drop_location'] . '.'
                : 'Your driver request from ' . $request['pickup'] . ' to ' . $request['drop_location'] . ' was rejected.'
        );
    }

    $_SESSION['driver_success'] = $decision === 'accepted' ? "Ride request accepted." : "Ride request rejected.";
    header("Location: /ridesync/pages/driver_requests.php");
    exit();
}

if ($action === 'complete_direct_request') {
    $requestId = (int) ($_POST['request_id'] ?? 0);

    if ($requestId <= 0) {
        $_SESSION['driver_error'] = "Choose a valid accepted request.";
        header("Location: /ridesync/pages/driver_requests.php");
        exit();
    }

    mysqli_begin_transaction($conn);

    try {
        $stmt = mysqli_prepare($conn,
            "SELECT id, rider_user_id, pickup, drop_location, estimated_fare, route_distance_km
             FROM driver_ride_requests
             WHERE id = ? AND driver_id = ? AND request_status = 'accepted'
             LIMIT 1
             FOR UPDATE"
        );
        mysqli_stmt_bind_param($stmt, "ii", $requestId, $driverId);
        mysqli_stmt_execute($stmt);
        $request = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

        if (!$request) {
            throw new RuntimeException("Accepted request not found or already completed.");
        }

        $distanceKm = ridesync_float_or_null($request['route_distance_km'] ?? null);
        if ($distanceKm === null || $distanceKm <= 0) {
            $distanceKm = ridesync_estimate_route_distance($request['pickup'], $request['drop_location']);
        }

        $fare = (float) ($request['estimated_fare'] ?? 0);
        if ($fare <= 0) {
            $fare = ridesync_driver_fare_estimate($distanceKm);
        }

        $recorded = ridesync_record_driver_trip(
            $conn,
            $driverId,
            $request['pickup'],
            $request['drop_location'],
            $fare,
            $distanceKm,
            'direct_request',
            $requestId
        );

        if (!$recorded) {
            throw new RuntimeException("Could not add this trip to driver history.");
        }

        $hasCompletedAt = ridesync_column_exists($conn, 'driver_ride_requests', 'completed_at');
        if ($hasCompletedAt) {
            $stmt = mysqli_prepare($conn,
                "UPDATE driver_ride_requests
                 SET request_status = 'completed', completed_at = CURRENT_TIMESTAMP
                 WHERE id = ? AND driver_id = ? AND request_status = 'accepted'"
            );
        } else {
            $stmt = mysqli_prepare($conn,
                "UPDATE driver_ride_requests
                 SET request_status = 'completed', responded_at = CURRENT_TIMESTAMP
                 WHERE id = ? AND driver_id = ? AND request_status = 'accepted'"
            );
        }
        mysqli_stmt_bind_param($stmt, "ii", $requestId, $driverId);
        mysqli_stmt_execute($stmt);

        if (mysqli_stmt_affected_rows($stmt) !== 1) {
            throw new RuntimeException("Could not mark the request completed.");
        }

        if (!empty($request['rider_user_id'])) {
            ridesync_wallet_record_fare_due(
                $conn,
                (int) $request['rider_user_id'],
                null,
                $driverId,
                $fare,
                'Direct driver request fare from ' . $request['pickup'] . ' to ' . $request['drop_location'],
                'direct_request',
                $requestId
            );

            ridesync_create_notification(
                $conn,
                (int) $request['rider_user_id'],
                null,
                'Driver trip completed',
                'Your driver trip from ' . $request['pickup'] . ' to ' . $request['drop_location'] . ' was marked completed.'
            );
        }

        mysqli_commit($conn);
        $_SESSION['driver_success'] = "Trip completed and added to earnings.";
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        $_SESSION['driver_error'] = $e instanceof RuntimeException ? $e->getMessage() : "Could not complete the request.";
    }

    header("Location: /ridesync/pages/driver_requests.php");
    exit();
}

header("Location: /ridesync/pages/driver_dashboard.php");
exit();
?>
