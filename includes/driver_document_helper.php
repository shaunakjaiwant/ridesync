<?php
require_once __DIR__ . '/../config/bootstrap.php';

function ridesync_driver_document_types() {
    return [
        'license' => 'Driving License',
        'aadhaar' => 'Aadhaar Card',
        'pan' => 'PAN Card',
        'id_proof' => 'ID Proof',
        'vehicle_rc' => 'Vehicle RC',
        'insurance' => 'Insurance Certificate',
        'profile_photo' => 'Profile Photo',
        'selfie' => 'Selfie',
        'vehicle_image' => 'Vehicle Image',
        'other' => 'Other Supporting Document',
    ];
}

function ridesync_driver_document_label($type) {
    $types = ridesync_driver_document_types();
    return $types[(string) $type] ?? ucwords(str_replace('_', ' ', (string) $type));
}

function ridesync_driver_document_core_types() {
    return ['license', 'aadhaar', 'pan', 'vehicle_rc', 'insurance', 'selfie', 'vehicle_image'];
}

function ridesync_driver_document_reference_is_file($reference) {
    $reference = trim((string) $reference);
    return str_starts_with($reference, 'uploads/driver_documents/')
        || str_starts_with($reference, 'secure://driver_documents/');
}

function ridesync_driver_document_crypto_material() {
    $envKey = ridesync_env('RIDESYNC_DOCUMENT_ENCRYPTION_KEY', '');
    if ($envKey !== '') {
        $decoded = base64_decode($envKey, true);
        return $decoded !== false && strlen($decoded) >= 32 ? substr($decoded, 0, 32) : hash('sha256', $envKey, true);
    }

    $keyDir = ridesync_storage_path('secure_driver_documents');
    ridesync_ensure_directory($keyDir);
    $keyFile = $keyDir . DIRECTORY_SEPARATOR . '.document.key';

    if (is_file($keyFile)) {
        $stored = trim((string) file_get_contents($keyFile));
        $decoded = base64_decode($stored, true);
        if ($decoded !== false && strlen($decoded) >= 32) {
            return substr($decoded, 0, 32);
        }
    }

    $material = random_bytes(32);
    file_put_contents($keyFile, base64_encode($material), LOCK_EX);
    return $material;
}

function ridesync_driver_document_secure_path($reference) {
    $reference = trim((string) $reference);
    if (!preg_match('#^secure://driver_documents/(driver_[0-9]+)/([A-Za-z0-9._-]+\.enc)$#', $reference, $matches)) {
        return null;
    }

    $root = ridesync_storage_path('secure_driver_documents');
    $path = $root . DIRECTORY_SEPARATOR . $matches[1] . DIRECTORY_SEPARATOR . $matches[2];
    $rootReal = realpath($root);
    $pathReal = realpath($path);

    if (!$rootReal || !$pathReal || !str_starts_with($pathReal, $rootReal) || !is_file($pathReal)) {
        return null;
    }

    return $pathReal;
}

function ridesync_driver_document_upload_path($reference) {
    $reference = trim((string) $reference);
    if ($reference === '' || !str_starts_with($reference, 'uploads/driver_documents/')) {
        return null;
    }

    $uploadRoot = realpath(RIDESYNC_ROOT . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'driver_documents');
    $filePath = realpath(RIDESYNC_ROOT . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $reference));

    if (!$uploadRoot || !$filePath || !str_starts_with($filePath, $uploadRoot) || !is_file($filePath)) {
        return null;
    }

    return $filePath;
}

function ridesync_driver_document_detect_mime($path) {
    $mime = 'application/octet-stream';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $detected = finfo_file($finfo, $path);
            finfo_close($finfo);
            if (is_string($detected) && $detected !== '') {
                $mime = $detected;
            }
        }
    } elseif (function_exists('mime_content_type')) {
        $detected = mime_content_type($path);
        if (is_string($detected) && $detected !== '') {
            $mime = $detected;
        }
    }

    return $mime;
}

function ridesync_driver_document_read($reference) {
    $reference = trim((string) $reference);

    if (str_starts_with($reference, 'secure://driver_documents/')) {
        $path = ridesync_driver_document_secure_path($reference);
        if (!$path) {
            return null;
        }

        $metaPath = preg_replace('/\.enc$/', '.json', $path);
        $meta = is_file($metaPath) ? json_decode((string) file_get_contents($metaPath), true) : [];
        $payload = file_get_contents($path);
        if (!is_string($payload) || !str_starts_with($payload, "RSENC1\n")) {
            return null;
        }

        $parts = explode("\n", $payload, 3);
        if (count($parts) !== 3) {
            return null;
        }

        $header = json_decode(base64_decode($parts[1], true) ?: '', true);
        if (!is_array($header) || empty($header['iv']) || empty($header['tag'])) {
            return null;
        }

        $bytes = openssl_decrypt(
            $parts[2],
            'aes-256-gcm',
            ridesync_driver_document_crypto_material(),
            OPENSSL_RAW_DATA,
            base64_decode($header['iv'], true),
            base64_decode($header['tag'], true)
        );

        if (!is_string($bytes)) {
            return null;
        }

        return [
            'bytes' => $bytes,
            'mime' => (string) ($meta['mime'] ?? $header['mime'] ?? 'application/octet-stream'),
            'filename' => (string) ($meta['original_name'] ?? $header['filename'] ?? basename($path, '.enc')),
            'size' => strlen($bytes),
            'encrypted' => true,
            'path' => $path,
            'reference' => $reference,
        ];
    }

    $path = ridesync_driver_document_upload_path($reference);
    if (!$path) {
        return null;
    }

    return [
        'bytes' => file_get_contents($path),
        'mime' => ridesync_driver_document_detect_mime($path),
        'filename' => basename($path),
        'size' => filesize($path),
        'encrypted' => false,
        'path' => $path,
        'reference' => $reference,
    ];
}

function ridesync_driver_document_signature($documentId, $expiresAt) {
    return hash_hmac(
        'sha256',
        (int) $documentId . '|' . (int) $expiresAt,
        ridesync_driver_document_crypto_material()
    );
}

function ridesync_driver_document_signed_url($documentId, $reference, $ttlSeconds = 900) {
    if (!ridesync_driver_document_reference_is_file($reference)) {
        return null;
    }

    $expiresAt = time() + max(60, min(3600, (int) $ttlSeconds));
    return '/ridesync/pages/admin_document.php?document_id=' . (int) $documentId
        . '&expires=' . $expiresAt
        . '&signature=' . ridesync_driver_document_signature($documentId, $expiresAt);
}

function ridesync_driver_document_validate_signature($documentId, $expiresAt, $signature) {
    $expiresAt = (int) $expiresAt;
    $signature = (string) $signature;

    if ($expiresAt < time() || $signature === '') {
        return false;
    }

    return hash_equals(ridesync_driver_document_signature($documentId, $expiresAt), $signature);
}

function ridesync_driver_document_upload($fieldName, $driverId, $documentType) {
    if (!isset($_FILES[$fieldName]) || ($_FILES[$fieldName]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ($_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException("Could not upload " . str_replace('_', ' ', $documentType) . ".");
    }

    if ($_FILES[$fieldName]['size'] > 8 * 1024 * 1024) {
        throw new RuntimeException("Driver documents must be 8 MB or smaller.");
    }

    $allowed = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];

    $mime = ridesync_driver_document_detect_mime($_FILES[$fieldName]['tmp_name']);
    if (!isset($allowed[$mime])) {
        throw new RuntimeException("Upload PDF, JPG, or PNG driver documents only.");
    }

    if (!function_exists('openssl_encrypt')) {
        throw new RuntimeException("Secure document storage is not available on this PHP runtime.");
    }

    $bytes = file_get_contents($_FILES[$fieldName]['tmp_name']);
    if (!is_string($bytes) || $bytes === '') {
        throw new RuntimeException("Uploaded document is empty.");
    }

    $extension = $allowed[$mime];
    $driverFolder = 'driver_' . (int) $driverId;
    $storageDir = ridesync_storage_path('secure_driver_documents' . DIRECTORY_SEPARATOR . $driverFolder);
    if (!ridesync_ensure_directory($storageDir)) {
        throw new RuntimeException("Could not prepare the secure driver document folder.");
    }

    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt(
        $bytes,
        'aes-256-gcm',
        ridesync_driver_document_crypto_material(),
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    if (!is_string($ciphertext)) {
        throw new RuntimeException("Could not encrypt uploaded driver document.");
    }

    $safeType = preg_replace('/[^a-z0-9_]/', '_', strtolower((string) $documentType));
    $fileName = $safeType . '_' . bin2hex(random_bytes(10)) . '.' . $extension . '.enc';
    $targetPath = $storageDir . DIRECTORY_SEPARATOR . $fileName;
    $header = [
        'alg' => 'aes-256-gcm',
        'iv' => base64_encode($iv),
        'tag' => base64_encode($tag),
        'mime' => $mime,
        'filename' => basename((string) ($_FILES[$fieldName]['name'] ?? ($safeType . '.' . $extension))),
    ];

    $payload = "RSENC1\n" . base64_encode(json_encode($header, JSON_UNESCAPED_SLASHES)) . "\n" . $ciphertext;
    if (file_put_contents($targetPath, $payload, LOCK_EX) === false) {
        throw new RuntimeException("Could not save encrypted driver document.");
    }

    $meta = [
        'driver_id' => (int) $driverId,
        'document_type' => $safeType,
        'original_name' => basename((string) ($_FILES[$fieldName]['name'] ?? 'document.' . $extension)),
        'mime' => $mime,
        'extension' => $extension,
        'size' => strlen($bytes),
        'sha256' => hash('sha256', $bytes),
        'encrypted' => true,
        'stored_at' => date('c'),
    ];
    file_put_contents(preg_replace('/\.enc$/', '.json', $targetPath), json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

    return 'secure://driver_documents/' . $driverFolder . '/' . $fileName;
}
?>
