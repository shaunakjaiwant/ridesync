<?php
return [
    'version' => '2026_07_30_000010_create_password_resets_table',
    'description' => 'Create password_resets table for OTP-based password resets',
    'up' => function ($conn) {
        $sql = "CREATE TABLE IF NOT EXISTS password_resets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            account_type ENUM('rider', 'driver') NOT NULL DEFAULT 'rider',
            user_id INT NOT NULL,
            email VARCHAR(190) NOT NULL,
            otp_hash VARCHAR(255) NOT NULL,
            token_hash VARCHAR(255) NULL,
            attempts INT NOT NULL DEFAULT 0,
            expires_at DATETIME NOT NULL,
            verified_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_email_account (email, account_type),
            INDEX idx_user_account (user_id, account_type),
            INDEX idx_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

        if (!mysqli_query($conn, $sql)) {
            return false;
        }

        return true;
    }
];
