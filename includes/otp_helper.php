<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/email_helper.php';

function ridesync_generate_otp(): string {
    return (string) random_int(100000, 999999);
}

function ridesync_create_password_reset_otp($conn, string $accountType, int $userId, string $email): array {
    $accountType = in_array($accountType, ['rider', 'driver'], true) ? $accountType : 'rider';
    $email = strtolower(trim($email));

    if (!ridesync_is_valid_email($email) || $userId <= 0) {
        return ['ok' => false, 'error' => 'Invalid email or user identity.'];
    }

    // Check 60-second cooldown
    $stmt = mysqli_prepare($conn, "SELECT created_at FROM password_resets WHERE email = ? AND account_type = ? AND created_at > NOW() - INTERVAL 60 SECOND ORDER BY id DESC LIMIT 1");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "ss", $email, $accountType);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $cooldownRow = mysqli_fetch_assoc($res);
        mysqli_stmt_close($stmt);

        if ($cooldownRow) {
            return [
                'ok' => false,
                'cooldown' => true,
                'error' => 'Please wait 60 seconds before requesting a new OTP code.',
            ];
        }
    }

    // Invalidate existing active OTPs for this account
    $stmt = mysqli_prepare($conn, "UPDATE password_resets SET verified_at = NOW() WHERE email = ? AND account_type = ? AND verified_at IS NULL");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "ss", $email, $accountType);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }

    $rawOtp = ridesync_generate_otp();
    $otpHash = password_hash($rawOtp, PASSWORD_DEFAULT);

    // Insert new OTP with 10-minute expiration
    $stmt = mysqli_prepare($conn, "INSERT INTO password_resets (account_type, user_id, email, otp_hash, attempts, expires_at, created_at) VALUES (?, ?, ?, ?, 0, NOW() + INTERVAL 10 MINUTE, NOW())");
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Could not prepare OTP record.'];
    }

    mysqli_stmt_bind_param($stmt, "siss", $accountType, $userId, $email, $otpHash);
    $inserted = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    if (!$inserted) {
        return ['ok' => false, 'error' => 'Could not save OTP security record.'];
    }

    // Email delivery
    $subject = "RideSync Password Reset OTP";
    $body = "Your RideSync password reset verification code is:\n\n"
        . "    {$rawOtp}\n\n"
        . "This code is valid for 10 minutes.\n"
        . "If you did not request a password reset, please ignore this email and your account password will remain unchanged.";

    ridesync_send_email($email, $subject, $body, 'RideSync OTP');

    return [
        'ok' => true,
        'raw_otp' => $rawOtp,
        'message' => 'If this email address is registered, an OTP verification code has been sent.',
    ];
}

function ridesync_verify_password_reset_otp($conn, string $accountType, string $email, string $otp): array {
    $accountType = in_array($accountType, ['rider', 'driver'], true) ? $accountType : 'rider';
    $email = strtolower(trim($email));
    $otp = trim($otp);

    if (!preg_match('/^\d{6}$/', $otp)) {
        return ['ok' => false, 'error' => 'Please enter a valid 6-digit numeric OTP code.'];
    }

    $stmt = mysqli_prepare($conn, "SELECT id, otp_hash, attempts, expires_at FROM password_resets WHERE email = ? AND account_type = ? AND verified_at IS NULL AND expires_at > NOW() ORDER BY id DESC LIMIT 1");
    if (!$stmt) {
        return ['ok' => false, 'error' => 'System error verifying OTP.'];
    }

    mysqli_stmt_bind_param($stmt, "ss", $email, $accountType);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);

    if (!$row) {
        return ['ok' => false, 'error' => 'Invalid or expired OTP code. Please request a new OTP code.'];
    }

    if ((int) $row['attempts'] >= 5) {
        return ['ok' => false, 'error' => 'Maximum verification attempts exceeded (5/5). Please request a new OTP code.'];
    }

    if (!password_verify($otp, $row['otp_hash'])) {
        $newAttempts = (int) $row['attempts'] + 1;
        $upStmt = mysqli_prepare($conn, "UPDATE password_resets SET attempts = ? WHERE id = ?");
        if ($upStmt) {
            mysqli_stmt_bind_param($upStmt, "ii", $newAttempts, $row['id']);
            mysqli_stmt_execute($upStmt);
            mysqli_stmt_close($upStmt);
        }

        $remaining = max(0, 5 - $newAttempts);
        return [
            'ok' => false,
            'error' => "Incorrect OTP code. {$remaining} attempt(s) remaining before lockout.",
        ];
    }

    // OTP Verified! Mark verified_at
    $verStmt = mysqli_prepare($conn, "UPDATE password_resets SET verified_at = NOW() WHERE id = ?");
    if ($verStmt) {
        mysqli_stmt_bind_param($verStmt, "i", $row['id']);
        mysqli_stmt_execute($verStmt);
        mysqli_stmt_close($verStmt);
    }

    return ['ok' => true, 'reset_id' => (int) $row['id']];
}

function ridesync_complete_password_reset($conn, string $accountType, string $email, string $newPassword, ?int $resetId = null): array {
    $accountType = in_array($accountType, ['rider', 'driver'], true) ? $accountType : 'rider';
    $email = strtolower(trim($email));

    if (strlen($newPassword) < 8) {
        return ['ok' => false, 'error' => 'Password must be at least 8 characters long.'];
    }

    // Check for verified session in DB
    if ($resetId !== null && $resetId > 0) {
        $stmt = mysqli_prepare($conn, "SELECT id, user_id FROM password_resets WHERE id = ? AND email = ? AND account_type = ? AND verified_at IS NOT NULL AND expires_at > NOW() LIMIT 1");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "iss", $resetId, $email, $accountType);
        }
    } else {
        $stmt = mysqli_prepare($conn, "SELECT id, user_id FROM password_resets WHERE email = ? AND account_type = ? AND verified_at IS NOT NULL AND expires_at > NOW() ORDER BY id DESC LIMIT 1");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "ss", $email, $accountType);
        }
    }

    if (!$stmt) {
        return ['ok' => false, 'error' => 'System error completing password reset.'];
    }

    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);

    if (!$row) {
        return ['ok' => false, 'error' => 'OTP verification session expired or not found. Please verify your OTP code again.'];
    }

    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
    $table = $accountType === 'driver' ? 'driver_accounts' : 'users';
    $userId = (int) $row['user_id'];

    $upStmt = mysqli_prepare($conn, "UPDATE {$table} SET password = ? WHERE id = ?");
    if (!$upStmt) {
        return ['ok' => false, 'error' => 'Could not update password hash.'];
    }

    mysqli_stmt_bind_param($upStmt, "si", $hashedPassword, $userId);
    $updated = mysqli_stmt_execute($upStmt);
    mysqli_stmt_close($upStmt);

    if (!$updated) {
        return ['ok' => false, 'error' => 'Could not save new password. Please try again.'];
    }

    // Clean up reset records for this account
    $delStmt = mysqli_prepare($conn, "DELETE FROM password_resets WHERE email = ? AND account_type = ?");
    if ($delStmt) {
        mysqli_stmt_bind_param($delStmt, "ss", $email, $accountType);
        mysqli_stmt_execute($delStmt);
        mysqli_stmt_close($delStmt);
    }

    return ['ok' => true];
}
