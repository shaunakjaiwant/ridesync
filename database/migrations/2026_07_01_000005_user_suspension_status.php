<?php
return [
    'version' => '2026_07_01_000005_user_suspension_status',
    'description' => 'Add status and status_reason to users table and rejection_reason to driver_accounts table',
    'up' => function ($conn) {
        // Add status to users if missing
        $checkStatus = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'status'");
        if ($checkStatus && mysqli_num_rows($checkStatus) === 0) {
            mysqli_query($conn, "ALTER TABLE users ADD COLUMN status ENUM('active','suspended') NOT NULL DEFAULT 'active' AFTER password");
        }

        // Add status_reason to users if missing
        $checkReason = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'status_reason'");
        if ($checkReason && mysqli_num_rows($checkReason) === 0) {
            mysqli_query($conn, "ALTER TABLE users ADD COLUMN status_reason VARCHAR(255) NULL AFTER status");
        }

        // Add rejection_reason to driver_accounts if missing
        $checkDriverReason = mysqli_query($conn, "SHOW COLUMNS FROM driver_accounts LIKE 'rejection_reason'");
        if ($checkDriverReason && mysqli_num_rows($checkDriverReason) === 0) {
            mysqli_query($conn, "ALTER TABLE driver_accounts ADD COLUMN rejection_reason VARCHAR(255) NULL AFTER status");
        }

        return true;
    }
];
