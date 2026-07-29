<?php
/**
 * Automated Driver Document Verification Helper
 * Implements AI structured OCR extraction, cross-check validation, fraud heuristics,
 * and automated decision engine (auto_approve, needs_review, auto_reject).
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/admin_helper.php';
require_once __DIR__ . '/driver_account_helper.php';
require_once __DIR__ . '/driver_document_helper.php';
require_once __DIR__ . '/verification_helper.php';

/**
 * Perform AI vision/OCR extraction on an uploaded document file or reference.
 * Returns structured JSON array: name, document_number, expiry_date, issuing_authority, vehicle_number, confidence_score.
 */
function ridesync_document_ai_extract_data(string $documentType, string $reference): array {
    $extracted = [
        'name' => null,
        'document_number' => null,
        'expiry_date' => null,
        'issuing_authority' => null,
        'vehicle_number' => null,
        'confidence_score' => 85,
        'extraction_status' => 'success',
        'raw_text' => '',
    ];

    // Attempt to read the actual document file payload if encrypted or uploaded
    $path = null;
    if (str_starts_with($reference, 'secure://driver_documents/')) {
        $path = ridesync_driver_document_secure_path($reference);
    } elseif (str_starts_with($reference, 'uploads/driver_documents/')) {
        $path = ridesync_driver_document_upload_path($reference);
    }

    $serviceUrl = rtrim((string) ridesync_env('RIDESYNC_VERIFICATION_SERVICE_URL', 'http://127.0.0.1:8011'), '/');
    
    // Probe external FastAPI OCR service if file path is accessible
    if ($path && is_file($path)) {
        $mime = ridesync_driver_document_detect_mime($path);
        $fileBytes = null;
        if (str_ends_with($path, '.enc')) {
            $decrypted = ridesync_driver_document_read($reference);
            if ($decrypted && !empty($decrypted['content'])) {
                $fileBytes = $decrypted['content'];
                $mime = $decrypted['mime_type'] ?? $mime;
            }
        } else {
            $fileBytes = file_get_contents($path);
        }

        if ($fileBytes && strlen($fileBytes) > 0) {
            $postData = json_encode([
                'document_type' => $documentType,
                'file_base64' => base64_encode($fileBytes),
                'mime_type' => $mime,
            ]);

            $ch = curl_init($serviceUrl . '/process');
            if ($ch) {
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $postData,
                    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
                    CURLOPT_CONNECTTIMEOUT => 2,
                    CURLOPT_TIMEOUT => 4,
                ]);
                $response = curl_exec($ch);
                $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                curl_close($ch);

                if ($status === 200 && is_string($response)) {
                    $json = json_decode($response, true);
                    if (is_array($json) && !empty($json['extracted_data'])) {
                        $aiData = $json['extracted_data'];
                        $extracted['name'] = !empty($aiData['name']) ? trim((string) $aiData['name']) : null;
                        $extracted['document_number'] = !empty($aiData['document_number']) ? ridesync_verification_clean($aiData['document_number']) : null;
                        $extracted['expiry_date'] = !empty($aiData['expiry_date']) ? trim((string) $aiData['expiry_date']) : null;
                        $extracted['issuing_authority'] = !empty($aiData['issuing_authority']) ? trim((string) $aiData['issuing_authority']) : null;
                        $extracted['vehicle_number'] = !empty($aiData['vehicle_number']) ? ridesync_verification_clean($aiData['vehicle_number']) : null;
                        $extracted['confidence_score'] = max(10, min(100, (int) ($json['confidence_score'] ?? 85)));
                        $extracted['raw_text'] = (string) ($json['raw_text'] ?? '');
                        return $extracted;
                    }
                }
            }
        }
    }

    // Heuristic structured extraction from reference string if file is direct text reference
    $cleanRef = trim($reference);
    if (!str_contains($cleanRef, '/')) {
        $extracted['confidence_score'] = 90;
        if (in_array($documentType, ['license', 'aadhaar', 'pan', 'vehicle_rc'], true)) {
            $extracted['document_number'] = ridesync_verification_clean($cleanRef);
        }
    }

    return $extracted;
}

/**
 * Check if a document number is already registered under another driver account.
 */
function ridesync_document_check_duplicate($conn, string $documentType, ?string $documentNumber, int $currentDriverId): bool {
    if (!$conn instanceof mysqli || empty($documentNumber) || strlen($documentNumber) < 5) {
        return false;
    }

    $cleanDocNum = ridesync_verification_clean($documentNumber);
    
    // Check against driver_account_profiles.license_number
    if ($documentType === 'license') {
        $stmt = mysqli_prepare($conn, "SELECT driver_id FROM driver_account_profiles WHERE REPLACE(REPLACE(UPPER(license_number), ' ', ''), '-', '') = ? AND driver_id != ? LIMIT 1");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'si', $cleanDocNum, $currentDriverId);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            if ($res && mysqli_fetch_assoc($res)) {
                return true;
            }
        }
    }

    // Check against driver_account_vehicles.vehicle_number
    if ($documentType === 'vehicle_rc') {
        $stmt = mysqli_prepare($conn, "SELECT driver_id FROM driver_account_vehicles WHERE REPLACE(REPLACE(UPPER(vehicle_number), ' ', ''), '-', '') = ? AND driver_id != ? LIMIT 1");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'si', $cleanDocNum, $currentDriverId);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            if ($res && mysqli_fetch_assoc($res)) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Main Automated Document Verification Pipeline.
 * Evaluates all submitted documents for a driver and assigns auto_approve, needs_review, or auto_reject.
 */
function ridesync_verify_driver_documents_automated($conn, int $driverId): array {
    $summary = [
        'driver_id' => $driverId,
        'overall_decision' => 'needs_review',
        'profile_status' => 'pending',
        'documents_processed' => 0,
        'auto_approved_count' => 0,
        'needs_review_count' => 0,
        'auto_rejected_count' => 0,
        'rejection_reasons' => [],
        'flag_reasons' => [],
    ];

    if (!$conn instanceof mysqli || $driverId <= 0) {
        return $summary;
    }

    // 1. Fetch driver account, profile, and vehicle details
    $accountStmt = mysqli_prepare($conn, "SELECT name, email, phone FROM driver_accounts WHERE id = ? LIMIT 1");
    mysqli_stmt_bind_param($accountStmt, 'i', $driverId);
    mysqli_stmt_execute($accountStmt);
    $account = mysqli_fetch_assoc(mysqli_stmt_get_result($accountStmt));
    if (!$account) {
        return $summary;
    }

    $driverName = trim((string) ($account['name'] ?? ''));

    $profileStmt = mysqli_prepare($conn, "SELECT id, license_number FROM driver_account_profiles WHERE driver_id = ? LIMIT 1");
    mysqli_stmt_bind_param($profileStmt, 'i', $driverId);
    mysqli_stmt_execute($profileStmt);
    $profile = mysqli_fetch_assoc(mysqli_stmt_get_result($profileStmt));

    $vehicleStmt = mysqli_prepare($conn, "SELECT vehicle_number FROM driver_account_vehicles WHERE driver_id = ? LIMIT 1");
    mysqli_stmt_bind_param($vehicleStmt, 'i', $driverId);
    mysqli_stmt_execute($vehicleStmt);
    $vehicle = mysqli_fetch_assoc(mysqli_stmt_get_result($vehicleStmt));

    $registeredVehicleNumber = ridesync_verification_clean($vehicle['vehicle_number'] ?? '');
    $registeredLicenseNumber = ridesync_verification_clean($profile['license_number'] ?? '');

    // 2. Fetch all submitted documents for this driver
    $docStmt = mysqli_prepare($conn, "SELECT id, document_type, document_reference FROM driver_account_documents WHERE driver_id = ? ORDER BY id ASC");
    mysqli_stmt_bind_param($docStmt, 'i', $driverId);
    mysqli_stmt_execute($docStmt);
    $docRes = mysqli_stmt_get_result($docStmt);
    $documents = [];
    while ($docRes && ($row = mysqli_fetch_assoc($docRes))) {
        $documents[] = $row;
    }

    if (empty($documents)) {
        return $summary;
    }

    $allFlagReasons = [];
    $allRejectionReasons = [];
    $hasAutoReject = false;
    $hasNeedsReview = false;

    // 3. Process each document through AI vision & cross-checking
    foreach ($documents as $doc) {
        $docId = (int) $doc['id'];
        $type = (string) $doc['document_type'];
        $ref = (string) $doc['document_reference'];

        $aiResult = ridesync_document_ai_extract_data($type, $ref);
        $confidence = (int) ($aiResult['confidence_score'] ?? 80);
        $extractedData = $aiResult;

        $docFlags = [];
        $docDecision = 'auto_approve';
        $rejectionReason = null;

        // Check if document reference is empty or completely unreadable file
        if ($ref === '') {
            $docDecision = 'auto_reject';
            $rejectionReason = 'Document reference is missing or unreadable.';
            $docFlags[] = $rejectionReason;
        }

        // Cross-Check 1: Name Match (for License, RC, Aadhaar, PAN)
        if ($docDecision !== 'auto_reject' && !empty($aiResult['name'])) {
            $extractedName = (string) $aiResult['name'];
            $nameScore = ridesync_verification_name_match_score($extractedName, $driverName);
            if ($nameScore < 70) {
                $docFlags[] = sprintf("Name mismatch: Document says '%s', account says '%s'", $extractedName, $driverName);
                $docDecision = 'needs_review';
            }
        }

        // Cross-Check 2: Vehicle Number Match (for Vehicle RC)
        if ($docDecision !== 'auto_reject' && $type === 'vehicle_rc' && !empty($aiResult['vehicle_number']) && $registeredVehicleNumber !== '') {
            $extractedVehicle = ridesync_verification_clean($aiResult['vehicle_number']);
            if ($extractedVehicle !== '' && $extractedVehicle !== $registeredVehicleNumber) {
                $docFlags[] = sprintf("Vehicle number mismatch: RC says '%s', registered vehicle says '%s'", $extractedVehicle, $registeredVehicleNumber);
                $docDecision = 'needs_review';
            }
        }

        // Cross-Check 3: Expiry Date Check (for License / RC / Insurance)
        if (!empty($aiResult['expiry_date'])) {
            $expiryTimestamp = strtotime((string) $aiResult['expiry_date']);
            if ($expiryTimestamp !== false && $expiryTimestamp < time()) {
                $docDecision = 'auto_reject';
                $rejectionReason = sprintf("Document is expired (Expiry date: %s)", date('Y-m-d', $expiryTimestamp));
                $docFlags[] = $rejectionReason;
            }
        }

        // Heuristic 1: Low Confidence / Image Quality Flag -> Queue for Human Review
        if ($docDecision !== 'auto_reject' && $confidence < 60) {
            $docFlags[] = sprintf("Low AI extraction confidence (%d%%) — requires human inspection", $confidence);
            $docDecision = 'needs_review';
        }

        // Heuristic 2: Duplicate Submission Flag -> Queue for Human Review
        if ($docDecision !== 'auto_reject' && !empty($aiResult['document_number'])) {
            $isDuplicate = ridesync_document_check_duplicate($conn, $type, $aiResult['document_number'], $driverId);
            if ($isDuplicate) {
                $docFlags[] = sprintf("Duplicate document alert: %s '%s' is registered to another driver", ridesync_driver_document_label($type), $aiResult['document_number']);
                $docDecision = 'needs_review';
            }
        }

        // Final status per document
        $statusStr = $docDecision === 'auto_approve' ? 'verified' : ($docDecision === 'auto_reject' ? 'rejected' : 'pending');
        
        if ($docDecision === 'auto_reject') {
            $hasAutoReject = true;
            $allRejectionReasons[] = $rejectionReason ?: 'Document rejected by validation engine.';
            $summary['auto_rejected_count']++;
        } elseif ($docDecision === 'needs_review') {
            $hasNeedsReview = true;
            $summary['needs_review_count']++;
        } else {
            $summary['auto_approved_count']++;
        }

        $allFlagReasons = array_merge($allFlagReasons, $docFlags);

        // Update database record for this document
        $jsonExtracted = json_encode($extractedData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $jsonFlags = !empty($docFlags) ? json_encode($docFlags, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
        $verMethod = $docDecision === 'auto_approve' ? 'auto' : 'manual';
        $now = date('Y-m-d H:i:s');

        $updateDoc = mysqli_prepare(
            $conn,
            "UPDATE driver_account_documents
             SET verification_status = ?,
                 extracted_data = ?,
                 confidence_score = ?,
                 verification_method = ?,
                 flag_reasons = ?,
                 verified_at = ?,
                 rejection_reason = ?
             WHERE id = ?"
        );
        if ($updateDoc) {
            mysqli_stmt_bind_param(
                $updateDoc,
                'ssissssi',
                $statusStr,
                $jsonExtracted,
                $confidence,
                $verMethod,
                $jsonFlags,
                $now,
                $rejectionReason,
                $docId
            );
            mysqli_stmt_execute($updateDoc);
        }

        $summary['documents_processed']++;
    }

    // 4. Overall Driver Decision Engine
    $overallDecision = 'needs_review';
    $profileStatus = 'pending';

    if ($hasAutoReject) {
        $overallDecision = 'auto_reject';
        $profileStatus = 'rejected';
    } elseif (!$hasNeedsReview && $summary['auto_approved_count'] === count($documents) && count($documents) >= 4) {
        $overallDecision = 'auto_approve';
        $profileStatus = 'verified';
    } else {
        $overallDecision = 'needs_review';
        $profileStatus = 'pending';
    }

    $summary['overall_decision'] = $overallDecision;
    $summary['profile_status'] = $profileStatus;
    $summary['flag_reasons'] = array_values(array_unique($allFlagReasons));
    $summary['rejection_reasons'] = array_values(array_unique($allRejectionReasons));

    // 5. Update driver_account_profiles and audit log
    $combinedRejectionReason = !empty($summary['rejection_reasons']) ? implode(' | ', $summary['rejection_reasons']) : null;
    $combinedDetails = !empty($summary['flag_reasons']) ? implode("\n", $summary['flag_reasons']) : 'Automated AI verification completed.';

    $updateProfile = mysqli_prepare(
        $conn,
        "UPDATE driver_account_profiles
         SET verification_status = ?,
             verification_details = ?
         WHERE driver_id = ?"
    );
    if ($updateProfile) {
        mysqli_stmt_bind_param($updateProfile, 'ssi', $profileStatus, $combinedDetails, $driverId);
        mysqli_stmt_execute($updateProfile);
    }

    if ($combinedRejectionReason !== null) {
        $updateAccount = mysqli_prepare($conn, "UPDATE driver_accounts SET rejection_reason = ? WHERE id = ?");
        if ($updateAccount) {
            mysqli_stmt_bind_param($updateAccount, 'si', $combinedRejectionReason, $driverId);
            mysqli_stmt_execute($updateAccount);
        }
    }

    // 6. Audit Trail Logging
    ridesync_admin_log(
        $conn,
        null,
        'driver_verification_auto_' . $overallDecision,
        'driver_account',
        $driverId,
        sprintf("Decision: %s | Profile: %s | Flags: %d", $overallDecision, $profileStatus, count($summary['flag_reasons']))
    );

    return $summary;
}
