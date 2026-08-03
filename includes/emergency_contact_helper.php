<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/db_helper.php';

function ridesync_get_user_emergency_contacts($conn, string $accountType, int $userId): array {
    $accountType = in_array($accountType, ['rider', 'driver'], true) ? $accountType : 'rider';
    if ($userId <= 0) return [];

    return ridesync_db_fetch_all(
        $conn,
        "SELECT id, name, relationship, phone_number, is_primary, created_at FROM user_emergency_contacts WHERE account_type = ? AND user_id = ? ORDER BY is_primary DESC, id ASC",
        "si",
        [$accountType, $userId]
    );
}

function ridesync_add_emergency_contact($conn, string $accountType, int $userId, string $name, string $relationship, string $phone, bool $isPrimary = false): array {
    $accountType = in_array($accountType, ['rider', 'driver'], true) ? $accountType : 'rider';
    $name = trim($name);
    $relationship = trim($relationship) ?: 'Family';
    $phone = preg_replace('/[^\d+]/', '', trim($phone));

    if ($userId <= 0 || strlen($name) < 2 || strlen($phone) < 8) {
        return ['ok' => false, 'error' => 'Please enter a valid name and phone number (min 8 digits).'];
    }

    $existing = ridesync_get_user_emergency_contacts($conn, $accountType, $userId);
    if (count($existing) >= 3) {
        return ['ok' => false, 'error' => 'Maximum limit of 3 emergency contacts reached. Delete an existing contact first.'];
    }

    if (empty($existing)) {
        $isPrimary = true;
    }

    if ($isPrimary) {
        $stmt = mysqli_prepare($conn, "UPDATE user_emergency_contacts SET is_primary = 0 WHERE account_type = ? AND user_id = ?");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "si", $accountType, $userId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }

    $stmt = mysqli_prepare($conn, "INSERT INTO user_emergency_contacts (account_type, user_id, name, relationship, phone_number, is_primary) VALUES (?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Database error saving emergency contact.'];
    }

    $primaryFlag = $isPrimary ? 1 : 0;
    mysqli_stmt_bind_param($stmt, "sisssi", $accountType, $userId, $name, $relationship, $phone, $primaryFlag);
    $inserted = mysqli_stmt_execute($stmt);
    $contactId = mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);

    if (!$inserted) {
        return ['ok' => false, 'error' => 'Could not insert emergency contact.'];
    }

    return ['ok' => true, 'contact_id' => $contactId];
}

function ridesync_delete_emergency_contact($conn, string $accountType, int $userId, int $contactId): array {
    $accountType = in_array($accountType, ['rider', 'driver'], true) ? $accountType : 'rider';
    if ($userId <= 0 || $contactId <= 0) {
        return ['ok' => false, 'error' => 'Invalid contact parameters.'];
    }

    $stmt = mysqli_prepare($conn, "DELETE FROM user_emergency_contacts WHERE id = ? AND account_type = ? AND user_id = ?");
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Database error deleting contact.'];
    }

    mysqli_stmt_bind_param($stmt, "isi", $contactId, $accountType, $userId);
    mysqli_stmt_execute($stmt);
    $affected = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);

    if ($affected <= 0) {
        return ['ok' => false, 'error' => 'Emergency contact not found.'];
    }

    return ['ok' => true];
}
