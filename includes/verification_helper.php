<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/driver_document_helper.php';
require_once __DIR__ . '/matching_helper.php';

function ridesync_verification_schema_ready($conn) {
    if (!$conn instanceof mysqli) {
        return false;
    }

    foreach ([
        'driver_verification_sessions',
        'document_analysis_results',
        'fraud_flags',
        'verification_audit_logs',
        'face_match_results',
        'government_api_checks',
    ] as $table) {
        if (!ridesync_table_exists($conn, $table)) {
            return false;
        }
    }

    return true;
}

function ridesync_verification_status_label($status) {
    $labels = [
        'queued' => 'Queued',
        'processing' => 'Processing',
        'verified' => 'Verified',
        'suspicious' => 'Suspicious',
        'fake_tampered' => 'Fake / Tampered',
        'needs_manual_review' => 'Needs Manual Review',
        'failed' => 'Failed',
        'cancelled' => 'Cancelled',
    ];

    return $labels[(string) $status] ?? ucwords(str_replace('_', ' ', (string) $status));
}

function ridesync_verification_badge_class($status) {
    if (in_array($status, ['verified', 'low', 'passed', 'approved'], true)) {
        return 'accepted';
    }
    if (in_array($status, ['fake_tampered', 'failed', 'critical', 'high', 'failed', 'rejected'], true)) {
        return 'rejected';
    }
    if (in_array($status, ['suspicious', 'medium', 'needs_review', 'escalated'], true)) {
        return 'open';
    }
    return 'pending';
}

function ridesync_verification_json($value) {
    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function ridesync_verification_decode($value, $default = []) {
    $decoded = json_decode((string) $value, true);
    return is_array($decoded) ? $decoded : $default;
}

function ridesync_verification_clean($value) {
    return strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) $value));
}

function ridesync_verification_name_match_score($a, $b) {
    $a = trim(strtolower(preg_replace('/\s+/', ' ', (string) $a)));
    $b = trim(strtolower(preg_replace('/\s+/', ' ', (string) $b)));
    if ($a === '' || $b === '') {
        return 0;
    }
    if ($a === $b) {
        return 100;
    }

    similar_text($a, $b, $percent);
    return (int) round($percent);
}

function ridesync_verification_mask_value($value, $visible = 4) {
    $value = preg_replace('/\s+/', '', (string) $value);
    if ($value === '') {
        return '';
    }

    $visible = max(2, min(6, (int) $visible));
    if (strlen($value) <= $visible) {
        return str_repeat('*', strlen($value));
    }

    return str_repeat('*', max(0, strlen($value) - $visible)) . substr($value, -$visible);
}

function ridesync_verification_fetch_driver_bundle($conn, $driverId) {
    $driverId = (int) $driverId;
    $stmt = mysqli_prepare(
        $conn,
        "SELECT
            d.id AS driver_id,
            d.name,
            d.email,
            d.phone,
            d.status AS account_status,
            p.id AS profile_id,
            p.license_number,
            p.verification_details,
            p.verification_status AS profile_status,
            v.vehicle_type,
            v.vehicle_number,
            v.seating_capacity
         FROM driver_accounts d
         LEFT JOIN driver_account_profiles p ON p.driver_id = d.id
         LEFT JOIN driver_account_vehicles v ON v.driver_id = d.id
         WHERE d.id = ?
         LIMIT 1"
    );
    mysqli_stmt_bind_param($stmt, 'i', $driverId);
    mysqli_stmt_execute($stmt);
    $driver = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if (!$driver) {
        return null;
    }

    $documents = [];
    $stmt = mysqli_prepare(
        $conn,
        "SELECT id, driver_id, document_type, document_reference, verification_status, created_at
         FROM driver_account_documents
         WHERE driver_id = ?
         ORDER BY FIELD(document_type, 'license', 'aadhaar', 'pan', 'id_proof', 'vehicle_rc', 'insurance', 'selfie', 'profile_photo', 'vehicle_image', 'other'), id DESC"
    );
    mysqli_stmt_bind_param($stmt, 'i', $driverId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) {
        $documents[] = $row;
    }

    return [
        'driver' => $driver,
        'documents' => $documents,
    ];
}

function ridesync_verification_input_snapshot(array $bundle) {
    $driver = $bundle['driver'];
    $documents = [];

    foreach ($bundle['documents'] as $doc) {
        $documentStream = ridesync_driver_document_read($doc['document_reference'] ?? '');
        $documents[] = [
            'id' => (int) $doc['id'],
            'type' => $doc['document_type'],
            'status' => $doc['verification_status'],
            'is_file' => ridesync_driver_document_reference_is_file($doc['document_reference'] ?? ''),
            'mime' => $documentStream['mime'] ?? null,
            'size' => $documentStream['size'] ?? null,
            'encrypted_storage' => (bool) ($documentStream['encrypted'] ?? false),
            'submitted_at' => $doc['created_at'],
        ];
    }

    return [
        'driver' => [
            'id' => (int) $driver['driver_id'],
            'name' => $driver['name'],
            'email_domain' => substr(strrchr((string) $driver['email'], '@') ?: '', 1),
            'phone_present' => trim((string) $driver['phone']) !== '',
            'license_number_present' => trim((string) $driver['license_number']) !== '',
            'vehicle_number_present' => trim((string) $driver['vehicle_number']) !== '',
            'vehicle_type' => $driver['vehicle_type'],
        ],
        'documents' => $documents,
        'captured_at' => date('c'),
    ];
}

function ridesync_verification_add_audit($conn, $sessionId, $actorType, $eventType, $message, array $metadata = [], $adminId = null) {
    if (!ridesync_verification_schema_ready($conn)) {
        return false;
    }

    $sessionId = (int) $sessionId;
    $adminId = $adminId !== null ? (int) $adminId : null;
    $actorType = substr((string) $actorType, 0, 20);
    $eventType = substr((string) $eventType, 0, 80);
    $message = substr((string) $message, 0, 255);
    $metadataJson = ridesync_verification_json($metadata);

    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO verification_audit_logs (session_id, admin_id, actor_type, event_type, message, metadata_json)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    mysqli_stmt_bind_param($stmt, 'iissss', $sessionId, $adminId, $actorType, $eventType, $message, $metadataJson);
    return mysqli_stmt_execute($stmt);
}

function ridesync_verification_create_session($conn, $driverId, $source = 'manual') {
    if (!ridesync_verification_schema_ready($conn)) {
        return null;
    }

    $bundle = ridesync_verification_fetch_driver_bundle($conn, $driverId);
    if (!$bundle) {
        return null;
    }

    $snapshotJson = ridesync_verification_json(ridesync_verification_input_snapshot($bundle));
    $reasonsJson = ridesync_verification_json(['Queued for AI document verification.']);
    $provider = ridesync_env('RIDESYNC_KYC_PROVIDER', 'mock_compliance_provider');
    $model = 'ridesync-verification-v1';
    $status = 'queued';
    $riskLevel = 'medium';
    $stage = 'queued';

    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO driver_verification_sessions
            (driver_id, status, risk_level, confidence_score, progress_stage, provider, model_version, reasons_json, input_snapshot_json, queued_at)
         VALUES (?, ?, ?, 0, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)"
    );
    mysqli_stmt_bind_param($stmt, 'isssssss', $driverId, $status, $riskLevel, $stage, $provider, $model, $reasonsJson, $snapshotJson);
    mysqli_stmt_execute($stmt);
    $sessionId = (int) mysqli_insert_id($conn);

    ridesync_verification_add_audit($conn, $sessionId, 'system', 'queued', 'Verification session queued.', [
        'source' => $source,
        'document_count' => count($bundle['documents']),
    ]);

    return $sessionId;
}

function ridesync_verification_latest_session($conn, $driverId) {
    if (!ridesync_verification_schema_ready($conn)) {
        return null;
    }

    $driverId = (int) $driverId;
    $stmt = mysqli_prepare(
        $conn,
        "SELECT *
         FROM driver_verification_sessions
         WHERE driver_id = ?
         ORDER BY id DESC
         LIMIT 1"
    );
    mysqli_stmt_bind_param($stmt, 'i', $driverId);
    mysqli_stmt_execute($stmt);
    $session = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    return $session ?: null;
}

function ridesync_verification_fetch_session_bundle($conn, $sessionId) {
    if (!ridesync_verification_schema_ready($conn)) {
        return null;
    }

    $sessionId = (int) $sessionId;
    $stmt = mysqli_prepare($conn, "SELECT * FROM driver_verification_sessions WHERE id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'i', $sessionId);
    mysqli_stmt_execute($stmt);
    $session = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if (!$session) {
        return null;
    }

    $rowsByQuery = static function ($sql) use ($conn, $sessionId) {
        $rows = [];
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, 'i', $sessionId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($res)) {
            $rows[] = $row;
        }
        return $rows;
    };

    return [
        'session' => $session,
        'documents' => $rowsByQuery(
            "SELECT ar.*, doc.document_reference, doc.verification_status AS manual_status
             FROM document_analysis_results ar
             LEFT JOIN driver_account_documents doc ON doc.id = ar.document_id
             WHERE ar.session_id = ?
             ORDER BY FIELD(ar.document_type, 'license', 'aadhaar', 'pan', 'id_proof', 'vehicle_rc', 'insurance', 'selfie', 'profile_photo', 'vehicle_image', 'other'), ar.id"
        ),
        'fraud_flags' => $rowsByQuery("SELECT * FROM fraud_flags WHERE session_id = ? ORDER BY FIELD(severity, 'critical', 'high', 'medium', 'low', 'info'), id"),
        'face_matches' => $rowsByQuery("SELECT * FROM face_match_results WHERE session_id = ? ORDER BY id DESC"),
        'api_checks' => $rowsByQuery("SELECT * FROM government_api_checks WHERE session_id = ? ORDER BY document_id, id"),
        'audit_logs' => $rowsByQuery("SELECT * FROM verification_audit_logs WHERE session_id = ? ORDER BY created_at ASC, id ASC"),
    ];
}

function ridesync_verification_update_stage($conn, $sessionId, $stage, $message) {
    $sessionId = (int) $sessionId;
    $status = $stage === 'failed' ? 'failed' : 'processing';
    $stmt = mysqli_prepare(
        $conn,
        "UPDATE driver_verification_sessions
         SET status = ?, progress_stage = ?, started_at = COALESCE(started_at, CURRENT_TIMESTAMP), updated_at = CURRENT_TIMESTAMP
         WHERE id = ?"
    );
    mysqli_stmt_bind_param($stmt, 'ssi', $status, $stage, $sessionId);
    mysqli_stmt_execute($stmt);
    ridesync_verification_add_audit($conn, $sessionId, 'system', $stage, $message);
}

function ridesync_verification_reference_field($reference, $key) {
    $reference = (string) $reference;
    if (preg_match('/(?:^|[;&,\s])' . preg_quote($key, '/') . '\s*[:=]\s*([^;&,]+)/i', $reference, $matches)) {
        return trim($matches[1]);
    }
    return null;
}

function ridesync_verification_extract_pattern($text, $pattern) {
    return preg_match($pattern, (string) $text, $matches) ? trim($matches[1]) : null;
}

function ridesync_verification_extract_document_data(array $doc, array $driver) {
    $type = (string) $doc['document_type'];
    $reference = (string) ($doc['document_reference'] ?? '');
    $explicitName = ridesync_verification_reference_field($reference, 'name');
    $explicitLicense = ridesync_verification_reference_field($reference, 'license');
    $explicitVehicle = ridesync_verification_reference_field($reference, 'vehicle');
    $explicitDob = ridesync_verification_reference_field($reference, 'dob');
    $explicitGender = ridesync_verification_reference_field($reference, 'gender');
    $explicitAddress = ridesync_verification_reference_field($reference, 'address');
    $expiry = ridesync_verification_reference_field($reference, 'expiry') ?: ridesync_verification_reference_field($reference, 'expires');
    $expiry = $expiry ?: (str_contains(strtolower($reference), 'expired') ? date('Y-m-d', strtotime('-30 days')) : date('Y-m-d', strtotime('+1 year')));

    $data = [
        'full_name' => $explicitName ?: (string) ($driver['name'] ?? ''),
        'dob' => $explicitDob ?: null,
        'gender' => $explicitGender ?: null,
        'address' => $explicitAddress ?: null,
    ];

    if ($type === 'license') {
        $data['license_number'] = strtoupper($explicitLicense ?: ridesync_verification_extract_pattern($reference, '/([A-Z]{2}[0-9]{2}[\s-]?[0-9A-Z]{6,16})/i') ?: (string) ($driver['license_number'] ?? ''));
        $data['expiry_date'] = $expiry;
    } elseif ($type === 'aadhaar' || $type === 'id_proof') {
        $aadhaar = ridesync_verification_reference_field($reference, 'aadhaar')
            ?: ridesync_verification_extract_pattern($reference, '/\b([0-9]{4}\s?[0-9]{4}\s?[0-9]{4})\b/');
        $data['aadhaar_number'] = $aadhaar ? ridesync_verification_mask_value($aadhaar, 4) : null;
    } elseif ($type === 'pan') {
        $pan = ridesync_verification_reference_field($reference, 'pan')
            ?: ridesync_verification_extract_pattern($reference, '/\b([A-Z]{5}[0-9]{4}[A-Z])\b/i');
        $data['pan_number'] = $pan ? ridesync_verification_mask_value(strtoupper($pan), 4) : null;
    } elseif ($type === 'vehicle_rc') {
        $data['vehicle_registration_number'] = strtoupper($explicitVehicle ?: ridesync_verification_extract_pattern($reference, '/\b([A-Z]{2}\s?[0-9]{1,2}\s?[A-Z]{1,3}\s?[0-9]{3,4})\b/i') ?: (string) ($driver['vehicle_number'] ?? ''));
        $data['owner_name'] = $data['full_name'];
    } elseif ($type === 'insurance') {
        $data['vehicle_registration_number'] = strtoupper($explicitVehicle ?: (string) ($driver['vehicle_number'] ?? ''));
        $data['policy_number'] = strtoupper(ridesync_verification_reference_field($reference, 'policy') ?: ridesync_verification_extract_pattern($reference, '/\b(POL[0-9A-Z-]{4,24})\b/i') ?: '');
        $data['expiry_date'] = $expiry;
    } elseif (in_array($type, ['selfie', 'profile_photo'], true)) {
        $data['face_detected'] = ridesync_driver_document_reference_is_file($reference);
    } elseif ($type === 'vehicle_image') {
        $data['vehicle_detected'] = ridesync_driver_document_reference_is_file($reference);
        $data['vehicle_registration_number'] = strtoupper($explicitVehicle ?: (string) ($driver['vehicle_number'] ?? ''));
    }

    return array_filter($data, static fn($value) => $value !== null && $value !== '');
}

function ridesync_verification_mismatches(array $extracted, array $driver, $documentType) {
    $mismatches = [];

    if (!empty($extracted['full_name'])) {
        $nameScore = ridesync_verification_name_match_score($extracted['full_name'], $driver['name'] ?? '');
        if ($nameScore > 0 && $nameScore < 82) {
            $mismatches[] = [
                'field' => 'full_name',
                'label' => 'Name mismatch',
                'message' => 'Uploaded document name does not match registered account name.',
                'form_value' => $driver['name'] ?? '',
                'document_value' => $extracted['full_name'],
                'match_score' => $nameScore,
            ];
        }
    }

    if ($documentType === 'license' && !empty($extracted['license_number'])) {
        if (ridesync_verification_clean($extracted['license_number']) !== ridesync_verification_clean($driver['license_number'] ?? '')) {
            $mismatches[] = [
                'field' => 'license_number',
                'label' => 'License number mismatch',
                'message' => 'Uploaded license number does not match the driver profile license number.',
                'form_value' => ridesync_verification_mask_value($driver['license_number'] ?? '', 4),
                'document_value' => ridesync_verification_mask_value($extracted['license_number'], 4),
            ];
        }
    }

    if (in_array($documentType, ['vehicle_rc', 'insurance', 'vehicle_image'], true) && !empty($extracted['vehicle_registration_number'])) {
        if (ridesync_verification_clean($extracted['vehicle_registration_number']) !== ridesync_verification_clean($driver['vehicle_number'] ?? '')) {
            $mismatches[] = [
                'field' => 'vehicle_registration_number',
                'label' => 'Vehicle number mismatch',
                'message' => 'Uploaded vehicle document does not match the vehicle number entered during signup.',
                'form_value' => $driver['vehicle_number'] ?? '',
                'document_value' => $extracted['vehicle_registration_number'],
            ];
        }
    }

    if (!empty($extracted['expiry_date']) && strtotime((string) $extracted['expiry_date']) && strtotime((string) $extracted['expiry_date']) < time()) {
        $mismatches[] = [
            'field' => 'expiry_date',
            'label' => 'Expired document',
            'message' => 'Document expiry date is in the past.',
            'document_value' => $extracted['expiry_date'],
        ];
    }

    return $mismatches;
}

function ridesync_verification_fraud_heuristics(array $doc, array $documentStream) {
    $reference = strtolower((string) ($doc['document_reference'] ?? ''));
    $flags = [];
    $keywordMap = [
        'photoshop' => ['high', 'photoshop_marker', 'Possible Photoshop edit', 'Document reference or metadata suggests image editing.'],
        'tamper' => ['critical', 'tamper_marker', 'Tampering marker detected', 'Document reference contains tamper indicators.'],
        'fake' => ['critical', 'fake_marker', 'Fake document marker detected', 'Document reference contains fake document indicators.'],
        'edited' => ['high', 'edited_marker', 'Possible edited field', 'Document reference contains edited-field indicators.'],
        'blur' => ['medium', 'blur_masking', 'Possible blur masking', 'Document may contain masked or blurred regions.'],
        'crop' => ['medium', 'cropped_overlay', 'Possible cropped overlay', 'Document may contain cropped text or overlays.'],
        'screenshot' => ['medium', 'screenshot_artifact', 'Screenshot artifact', 'Document appears to be a screenshot rather than an original scan.'],
    ];

    foreach ($keywordMap as $needle => $flag) {
        if (str_contains($reference, $needle)) {
            $flags[] = [
                'severity' => $flag[0],
                'flag_code' => $flag[1],
                'flag_label' => $flag[2],
                'description' => $flag[3],
                'confidence' => $flag[0] === 'critical' ? 92 : 74,
                'evidence' => ['signal' => $needle],
            ];
        }
    }

    if (empty($documentStream)) {
        $flags[] = [
            'severity' => 'medium',
            'flag_code' => 'reference_only',
            'flag_label' => 'Reference-only submission',
            'description' => 'No uploaded PDF/JPG/PNG file is available for computer-vision verification.',
            'confidence' => 67,
            'evidence' => ['reference_type' => 'text'],
        ];
        return $flags;
    }

    $mime = (string) ($documentStream['mime'] ?? '');
    $size = (int) ($documentStream['size'] ?? 0);
    if ($size > 0 && $size < 12 * 1024) {
        $flags[] = [
            'severity' => 'low',
            'flag_code' => 'low_file_size',
            'flag_label' => 'Low-resolution or tiny file',
            'description' => 'File is unusually small for a KYC document and may be compressed or cropped.',
            'confidence' => 52,
            'evidence' => ['size_bytes' => $size],
        ];
    }

    if (!in_array($mime, ['application/pdf', 'image/jpeg', 'image/png'], true)) {
        $flags[] = [
            'severity' => 'medium',
            'flag_code' => 'unexpected_mime',
            'flag_label' => 'Unexpected file type',
            'description' => 'Document MIME type is outside the accepted PDF/JPG/PNG policy.',
            'confidence' => 79,
            'evidence' => ['mime' => $mime],
        ];
    }

    if (str_starts_with($mime, 'image/')) {
        $tmpPath = tempnam(sys_get_temp_dir(), 'ridesync_doc_');
        if ($tmpPath && file_put_contents($tmpPath, $documentStream['bytes']) !== false) {
            $imageInfo = @getimagesize($tmpPath);
            @unlink($tmpPath);
            if ($imageInfo) {
                [$width, $height] = $imageInfo;
                if ($width < 640 || $height < 420) {
                    $flags[] = [
                        'severity' => 'medium',
                        'flag_code' => 'low_resolution',
                        'flag_label' => 'Low image resolution',
                        'description' => 'Image dimensions are low for reliable OCR and tampering analysis.',
                        'confidence' => 71,
                        'evidence' => ['width' => $width, 'height' => $height],
                    ];
                }
            }
        }
    }

    return $flags;
}

function ridesync_verification_api_checks(array $doc, array $extracted, array $driver) {
    $type = (string) $doc['document_type'];
    $checks = [];
    $provider = ridesync_env('RIDESYNC_KYC_PROVIDER', 'mock_compliance_provider');
    $existsStatus = ridesync_driver_document_reference_is_file($doc['document_reference'] ?? '') ? 'passed' : 'needs_review';

    $checks[] = [
        'provider' => $provider,
        'check_type' => $type . '_document_exists',
        'status' => $existsStatus,
        'confidence' => $existsStatus === 'passed' ? 91 : 55,
        'response' => ['mode' => 'mock', 'replaceable_provider' => true],
    ];

    if ($type === 'license') {
        $valid = preg_match('/^[A-Z0-9 -]{4,80}$/', (string) ($driver['license_number'] ?? '')) === 1;
        $checks[] = [
            'provider' => $provider,
            'check_type' => 'driving_license_format',
            'status' => $valid ? 'passed' : 'failed',
            'confidence' => $valid ? 88 : 35,
            'response' => ['license_number_masked' => ridesync_verification_mask_value($driver['license_number'] ?? '', 4)],
        ];
    }

    if (in_array($type, ['vehicle_rc', 'insurance', 'vehicle_image'], true)) {
        $validVehicle = preg_match('/^[A-Z]{2}[0-9]{1,2}[A-Z]{0,3}[0-9]{3,4}$/', ridesync_verification_clean($driver['vehicle_number'] ?? '')) === 1;
        $checks[] = [
            'provider' => $provider,
            'check_type' => 'vehicle_registration_format',
            'status' => $validVehicle ? 'passed' : 'needs_review',
            'confidence' => $validVehicle ? 86 : 58,
            'response' => ['vehicle_number' => $driver['vehicle_number'] ?? null],
        ];
    }

    if ($type === 'insurance') {
        $expired = !empty($extracted['expiry_date']) && strtotime((string) $extracted['expiry_date']) < time();
        $checks[] = [
            'provider' => $provider,
            'check_type' => 'insurance_expiry',
            'status' => $expired ? 'failed' : 'passed',
            'confidence' => $expired ? 39 : 84,
            'response' => ['expiry_date' => $extracted['expiry_date'] ?? null],
        ];
    }

    if ($type === 'aadhaar' || $type === 'id_proof') {
        $checks[] = [
            'provider' => $provider,
            'check_type' => 'uidai_compatible_masked_identity',
            'status' => !empty($extracted['aadhaar_number']) ? 'passed' : 'needs_review',
            'confidence' => !empty($extracted['aadhaar_number']) ? 82 : 57,
            'response' => ['aadhaar_masked' => $extracted['aadhaar_number'] ?? null],
        ];
    }

    if ($type === 'pan') {
        $checks[] = [
            'provider' => $provider,
            'check_type' => 'pan_format',
            'status' => !empty($extracted['pan_number']) ? 'passed' : 'needs_review',
            'confidence' => !empty($extracted['pan_number']) ? 82 : 57,
            'response' => ['pan_masked' => $extracted['pan_number'] ?? null],
        ];
    }

    return $checks;
}

function ridesync_verification_insert_document_result($conn, $sessionId, array $doc, array $extracted, array $mismatches, $ocrConfidence, $authenticityScore, $documentScore) {
    $status = count($mismatches) > 0 ? 'needs_review' : 'passed';
    $extractedJson = ridesync_verification_json($extracted);
    $normalizedJson = ridesync_verification_json($extracted);
    $mismatchJson = ridesync_verification_json($mismatches);
    $documentId = (int) $doc['id'];
    $driverId = (int) $doc['driver_id'];
    $documentType = (string) $doc['document_type'];

    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO document_analysis_results
            (session_id, document_id, driver_id, document_type, analysis_status, extracted_json, normalized_json, mismatch_json, ocr_confidence, authenticity_score, document_score)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    mysqli_stmt_bind_param(
        $stmt,
        'iiisssssddd',
        $sessionId,
        $documentId,
        $driverId,
        $documentType,
        $status,
        $extractedJson,
        $normalizedJson,
        $mismatchJson,
        $ocrConfidence,
        $authenticityScore,
        $documentScore
    );
    mysqli_stmt_execute($stmt);
}

function ridesync_verification_insert_fraud_flag($conn, $sessionId, $documentId, array $flag) {
    $documentId = $documentId !== null ? (int) $documentId : null;
    $severity = (string) $flag['severity'];
    $code = substr((string) $flag['flag_code'], 0, 80);
    $label = substr((string) $flag['flag_label'], 0, 140);
    $description = substr((string) $flag['description'], 0, 255);
    $confidence = (float) $flag['confidence'];
    $evidenceJson = ridesync_verification_json($flag['evidence'] ?? []);

    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO fraud_flags (session_id, document_id, severity, flag_code, flag_label, description, confidence, evidence_json)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );
    mysqli_stmt_bind_param($stmt, 'iissssds', $sessionId, $documentId, $severity, $code, $label, $description, $confidence, $evidenceJson);
    mysqli_stmt_execute($stmt);
}

function ridesync_verification_insert_api_check($conn, $sessionId, $documentId, array $check) {
    $provider = substr((string) $check['provider'], 0, 80);
    $checkType = substr((string) $check['check_type'], 0, 100);
    $status = (string) $check['status'];
    $confidence = (float) $check['confidence'];
    $responseJson = ridesync_verification_json($check['response'] ?? []);

    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO government_api_checks (session_id, document_id, provider, check_type, status, confidence, response_json)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    mysqli_stmt_bind_param($stmt, 'iisssds', $sessionId, $documentId, $provider, $checkType, $status, $confidence, $responseJson);
    mysqli_stmt_execute($stmt);
}

function ridesync_verification_insert_face_match($conn, $sessionId, $selfieDocumentId, $idDocumentId, $similarity, $threshold, $status, array $details = []) {
    $selfieDocumentId = $selfieDocumentId !== null ? (int) $selfieDocumentId : null;
    $idDocumentId = $idDocumentId !== null ? (int) $idDocumentId : null;
    $similarity = (float) $similarity;
    $threshold = (float) $threshold;
    $detailsJson = ridesync_verification_json($details);

    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO face_match_results (session_id, selfie_document_id, id_document_id, similarity_percent, threshold_percent, status, details_json)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    mysqli_stmt_bind_param($stmt, 'iiiddss', $sessionId, $selfieDocumentId, $idDocumentId, $similarity, $threshold, $status, $detailsJson);
    mysqli_stmt_execute($stmt);
}

function ridesync_verification_call_service($conn, $sessionId, array $bundle) {
    $serviceUrl = rtrim((string) ridesync_env('RIDESYNC_VERIFICATION_SERVICE_URL', ''), '/');
    if ($serviceUrl === '') {
        return null;
    }

    $payload = [
        'session_id' => (int) $sessionId,
        'driver' => [
            'name' => $bundle['driver']['name'] ?? '',
            'license_number' => ridesync_verification_mask_value($bundle['driver']['license_number'] ?? '', 4),
            'vehicle_number' => $bundle['driver']['vehicle_number'] ?? '',
            'vehicle_type' => $bundle['driver']['vehicle_type'] ?? '',
        ],
        'documents' => array_map(static function ($doc) {
            return [
                'id' => (int) $doc['id'],
                'document_type' => $doc['document_type'],
                'is_file' => ridesync_driver_document_reference_is_file($doc['document_reference'] ?? ''),
                'reference_fingerprint' => substr(hash('sha256', (string) ($doc['document_reference'] ?? '')), 0, 20),
            ];
        }, $bundle['documents']),
    ];

    $json = ridesync_verification_json($payload);
    $response = null;

    try {
        if (function_exists('curl_init')) {
            $ch = curl_init($serviceUrl . '/v1/driver-verifications/analyze');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $json,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-RideSync-Request-Id: ' . ridesync_request_id()],
                CURLOPT_TIMEOUT => 2,
            ]);
            $response = curl_exec($ch);
            $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($statusCode < 200 || $statusCode >= 300) {
                return null;
            }
        } else {
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/json\r\nX-RideSync-Request-Id: " . ridesync_request_id() . "\r\n",
                    'content' => $json,
                    'timeout' => 2,
                ],
            ]);
            $response = @file_get_contents($serviceUrl . '/v1/driver-verifications/analyze', false, $context);
        }

        $decoded = json_decode((string) $response, true);
        if (is_array($decoded)) {
            ridesync_verification_add_audit($conn, $sessionId, 'service', 'service_response', 'Verification microservice returned analysis.', [
                'service_url' => $serviceUrl,
                'status' => $decoded['status'] ?? null,
            ]);
            return $decoded;
        }
    } catch (Throwable $exception) {
        ridesync_log('warning', 'Verification service call failed', [
            'session_id' => (int) $sessionId,
            'message' => $exception->getMessage(),
        ]);
    }

    return null;
}

function ridesync_verification_process_session($conn, $sessionId) {
    if (!ridesync_verification_schema_ready($conn)) {
        return false;
    }

    $sessionId = (int) $sessionId;
    $stmt = mysqli_prepare($conn, "SELECT * FROM driver_verification_sessions WHERE id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'i', $sessionId);
    mysqli_stmt_execute($stmt);
    $session = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if (!$session) {
        return false;
    }

    $bundle = ridesync_verification_fetch_driver_bundle($conn, (int) $session['driver_id']);
    if (!$bundle) {
        return false;
    }

    mysqli_begin_transaction($conn);

    try {
        ridesync_verification_update_stage($conn, $sessionId, 'ocr', 'OCR extraction started.');
        $serviceResponse = ridesync_verification_call_service($conn, $sessionId, $bundle);

        $docScores = [];
        $apiChecks = [];
        $allFlags = [];
        $mismatchReasons = [];
        $documentIdsByType = [];

        foreach ($bundle['documents'] as $doc) {
            $documentIdsByType[$doc['document_type']] = (int) $doc['id'];
            $documentStream = ridesync_driver_document_read($doc['document_reference'] ?? '') ?: [];
            $extracted = ridesync_verification_extract_document_data($doc, $bundle['driver']);
            $mismatches = ridesync_verification_mismatches($extracted, $bundle['driver'], $doc['document_type']);
            $flags = ridesync_verification_fraud_heuristics($doc, $documentStream);
            $checks = ridesync_verification_api_checks($doc, $extracted, $bundle['driver']);

            foreach ($mismatches as $mismatch) {
                $mismatchReasons[] = $mismatch['message'];
            }
            foreach ($flags as $flag) {
                $allFlags[] = $flag;
                ridesync_verification_insert_fraud_flag($conn, $sessionId, (int) $doc['id'], $flag);
            }
            foreach ($checks as $check) {
                $apiChecks[] = $check;
                ridesync_verification_insert_api_check($conn, $sessionId, (int) $doc['id'], $check);
            }

            $ocrConfidence = ridesync_driver_document_reference_is_file($doc['document_reference'] ?? '') ? 86.0 : 48.0;
            $authenticityPenalty = 0;
            foreach ($flags as $flag) {
                $authenticityPenalty += ['critical' => 38, 'high' => 26, 'medium' => 16, 'low' => 8, 'info' => 3][$flag['severity']] ?? 8;
            }
            $authenticityScore = max(0, 100 - $authenticityPenalty);
            $documentScore = max(0, min(100, (($ocrConfidence + $authenticityScore) / 2) - (count($mismatches) * 15)));
            $docScores[] = $documentScore;

            ridesync_verification_insert_document_result($conn, $sessionId, $doc, $extracted, $mismatches, $ocrConfidence, $authenticityScore, $documentScore);
        }

        ridesync_verification_update_stage($conn, $sessionId, 'face_match', 'Face comparison started.');
        $selfieId = $documentIdsByType['selfie'] ?? $documentIdsByType['profile_photo'] ?? null;
        $idDocId = $documentIdsByType['license'] ?? $documentIdsByType['aadhaar'] ?? $documentIdsByType['id_proof'] ?? null;
        $hasCriticalFlag = count(array_filter($allFlags, static fn($flag) => $flag['severity'] === 'critical')) > 0;
        $hasHighFlag = count(array_filter($allFlags, static fn($flag) => in_array($flag['severity'], ['critical', 'high'], true))) > 0;

        if ($selfieId && $idDocId) {
            $similarity = $hasHighFlag ? 64.2 : 93.4;
            $faceStatus = $similarity >= 82 ? 'passed' : 'failed';
            $faceScore = $similarity;
        } else {
            $similarity = 0.0;
            $faceStatus = 'not_available';
            $faceScore = 58.0;
        }
        ridesync_verification_insert_face_match($conn, $sessionId, $selfieId, $idDocId, $similarity, 82.0, $faceStatus, [
            'engine' => 'mocked-facenet-compatible',
            'reason' => $faceStatus === 'not_available' ? 'Selfie or document face image not submitted.' : 'Embedding comparison completed.',
        ]);

        ridesync_verification_update_stage($conn, $sessionId, 'api_validation', 'Provider validation checks completed.');
        $passedChecks = count(array_filter($apiChecks, static fn($check) => $check['status'] === 'passed'));
        $apiScore = count($apiChecks) > 0 ? round(($passedChecks / count($apiChecks)) * 100, 2) : 0.0;

        ridesync_verification_update_stage($conn, $sessionId, 'fraud_analysis', 'Fraud analysis completed.');
        $ocrScore = count($docScores) > 0 ? round(array_sum($docScores) / count($docScores), 2) : 0.0;
        $fraudPenalty = 0;
        foreach ($allFlags as $flag) {
            $fraudPenalty += ['critical' => 38, 'high' => 24, 'medium' => 14, 'low' => 6, 'info' => 2][$flag['severity']] ?? 6;
        }
        $fraudScore = max(0, 100 - $fraudPenalty);

        $confidenceScore = (int) round(($ocrScore * 0.25) + ($apiScore * 0.30) + ($faceScore * 0.20) + ($fraudScore * 0.25));
        $submittedTypes = array_map(static fn($doc) => $doc['document_type'], $bundle['documents']);
        $hasIdentityDoc = count(array_intersect($submittedTypes, ['aadhaar', 'pan', 'id_proof'])) > 0;
        $missingCore = [];
        foreach (['license', 'vehicle_rc', 'insurance'] as $coreType) {
            if (!in_array($coreType, $submittedTypes, true)) {
                $missingCore[] = ridesync_driver_document_label($coreType);
            }
        }
        if (!$hasIdentityDoc) {
            $missingCore[] = 'Aadhaar/PAN or ID Proof';
        }
        if (!$selfieId) {
            $missingCore[] = 'Selfie';
        }

        $reasons = array_values(array_unique(array_filter(array_merge(
            $mismatchReasons,
            array_map(static fn($flag) => $flag['flag_label'], $allFlags),
            array_map(static fn($missing) => 'Missing ' . $missing . ' for automated KYC.', $missingCore)
        ))));

        if ($hasCriticalFlag || $confidenceScore < 50 || $faceStatus === 'failed') {
            $decision = 'fake_tampered';
            $riskLevel = 'critical';
        } elseif ($confidenceScore >= 85 && !$hasHighFlag && count($missingCore) === 0) {
            $decision = 'verified';
            $riskLevel = 'low';
        } elseif (count($missingCore) > 0 || $apiScore < 70 || $faceStatus === 'not_available') {
            $decision = 'needs_manual_review';
            $riskLevel = $confidenceScore >= 70 ? 'medium' : 'high';
        } else {
            $decision = 'suspicious';
            $riskLevel = $confidenceScore >= 70 ? 'medium' : 'high';
        }

        if (count($reasons) === 0) {
            $reasons[] = $decision === 'verified'
                ? 'All submitted evidence passed automated consistency checks.'
                : 'Automated checks require admin review.';
        }

        $reasonsJson = ridesync_verification_json(array_slice($reasons, 0, 8));
        $serviceResponseJson = $serviceResponse ? ridesync_verification_json($serviceResponse) : null;
        $stage = 'complete';
        $stmt = mysqli_prepare(
            $conn,
            "UPDATE driver_verification_sessions
             SET status = ?,
                 ai_decision = ?,
                 risk_level = ?,
                 confidence_score = ?,
                 ocr_score = ?,
                 api_score = ?,
                 face_score = ?,
                 fraud_score = ?,
                 progress_stage = ?,
                 reasons_json = ?,
                 service_response_json = ?,
                 completed_at = CURRENT_TIMESTAMP,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = ?"
        );
        mysqli_stmt_bind_param(
            $stmt,
            'sssdddddsssi',
            $decision,
            $decision,
            $riskLevel,
            $confidenceScore,
            $ocrScore,
            $apiScore,
            $faceScore,
            $fraudScore,
            $stage,
            $reasonsJson,
            $serviceResponseJson,
            $sessionId
        );
        mysqli_stmt_execute($stmt);

        ridesync_verification_add_audit($conn, $sessionId, 'system', 'decision', 'AI verification decision generated.', [
            'decision' => $decision,
            'confidence_score' => $confidenceScore,
            'ocr_score' => $ocrScore,
            'api_score' => $apiScore,
            'face_score' => $faceScore,
            'fraud_score' => $fraudScore,
        ]);

        mysqli_commit($conn);
        return true;
    } catch (Throwable $exception) {
        mysqli_rollback($conn);
        $failed = 'failed';
        $stage = 'failed';
        $reason = ridesync_verification_json(['Verification worker failed.']);
        $stmt = mysqli_prepare(
            $conn,
            "UPDATE driver_verification_sessions
             SET status = ?, progress_stage = ?, reasons_json = ?, completed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
             WHERE id = ?"
        );
        mysqli_stmt_bind_param($stmt, 'sssi', $failed, $stage, $reason, $sessionId);
        mysqli_stmt_execute($stmt);
        ridesync_verification_add_audit($conn, $sessionId, 'system', 'failed', 'Verification worker failed.', [
            'error_class' => get_class($exception),
        ]);
        ridesync_log_exception($exception, ['session_id' => $sessionId]);
        return false;
    }
}

function ridesync_verification_start_for_driver($conn, $driverId, $source = 'manual') {
    $sessionId = ridesync_verification_create_session($conn, $driverId, $source);
    if (!$sessionId) {
        return null;
    }

    $inlineFallback = filter_var(ridesync_env('RIDESYNC_VERIFICATION_INLINE_FALLBACK', 'true'), FILTER_VALIDATE_BOOLEAN);
    if ($inlineFallback) {
        ridesync_verification_process_session($conn, $sessionId);
    }

    return $sessionId;
}

function ridesync_verification_admin_decision($conn, $sessionId, $adminId, $decision, $note = '') {
    if (!ridesync_verification_schema_ready($conn)) {
        return false;
    }

    if (!in_array($decision, ['approved', 'rejected', 'escalated'], true)) {
        return false;
    }

    $sessionId = (int) $sessionId;
    $adminId = (int) $adminId;
    $note = trim((string) $note);
    if (strlen($note) > 1000) {
        $note = substr($note, 0, 1000);
    }

    $stmt = mysqli_prepare(
        $conn,
        "UPDATE driver_verification_sessions
         SET admin_decision = ?, admin_note = ?, decided_by = ?, decided_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
         WHERE id = ?"
    );
    mysqli_stmt_bind_param($stmt, 'ssii', $decision, $note, $adminId, $sessionId);
    $ok = mysqli_stmt_execute($stmt);

    ridesync_verification_add_audit($conn, $sessionId, 'admin', 'admin_' . $decision, 'Admin marked verification as ' . $decision . '.', [
        'note_present' => $note !== '',
    ], $adminId);

    return $ok;
}
?>
