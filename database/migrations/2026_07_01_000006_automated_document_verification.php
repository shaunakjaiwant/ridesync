<?php
/**
 * Migration: 2026_07_01_000006_automated_document_verification.php
 * Adds AI extraction, confidence scoring, verification method, flag reasons, and auditor tracking
 * to driver_account_documents table.
 */

return new class {
    public function up(mysqli $conn): bool {
        // Ensure driver_account_documents table exists
        $checkTable = mysqli_query($conn, "SHOW TABLES LIKE 'driver_account_documents'");
        if (!$checkTable || mysqli_num_rows($checkTable) === 0) {
            $createTable = "CREATE TABLE driver_account_documents (
                id INT AUTO_INCREMENT PRIMARY KEY,
                driver_id INT NOT NULL,
                document_type VARCHAR(50) NOT NULL,
                document_reference VARCHAR(255) NOT NULL,
                verification_status ENUM('pending', 'verified', 'rejected', 'suspicious', 'needs_review') DEFAULT 'pending',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_driver_docs_driver (driver_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
            mysqli_query($conn, $createTable);
        }

        // Add extracted_data column
        $checkCol = mysqli_query($conn, "SHOW COLUMNS FROM driver_account_documents LIKE 'extracted_data'");
        if ($checkCol && mysqli_num_rows($checkCol) === 0) {
            mysqli_query($conn, "ALTER TABLE driver_account_documents ADD COLUMN extracted_data JSON NULL AFTER document_reference");
        }

        // Add confidence_score column
        $checkCol = mysqli_query($conn, "SHOW COLUMNS FROM driver_account_documents LIKE 'confidence_score'");
        if ($checkCol && mysqli_num_rows($checkCol) === 0) {
            mysqli_query($conn, "ALTER TABLE driver_account_documents ADD COLUMN confidence_score INT NULL AFTER extracted_data");
        }

        // Add verification_method column
        $checkCol = mysqli_query($conn, "SHOW COLUMNS FROM driver_account_documents LIKE 'verification_method'");
        if ($checkCol && mysqli_num_rows($checkCol) === 0) {
            mysqli_query($conn, "ALTER TABLE driver_account_documents ADD COLUMN verification_method ENUM('auto', 'manual') DEFAULT 'manual' AFTER confidence_score");
        }

        // Add flag_reasons column
        $checkCol = mysqli_query($conn, "SHOW COLUMNS FROM driver_account_documents LIKE 'flag_reasons'");
        if ($checkCol && mysqli_num_rows($checkCol) === 0) {
            mysqli_query($conn, "ALTER TABLE driver_account_documents ADD COLUMN flag_reasons TEXT NULL AFTER verification_method");
        }

        // Add verified_at column
        $checkCol = mysqli_query($conn, "SHOW COLUMNS FROM driver_account_documents LIKE 'verified_at'");
        if ($checkCol && mysqli_num_rows($checkCol) === 0) {
            mysqli_query($conn, "ALTER TABLE driver_account_documents ADD COLUMN verified_at DATETIME NULL AFTER flag_reasons");
        }

        // Add verified_by column
        $checkCol = mysqli_query($conn, "SHOW COLUMNS FROM driver_account_documents LIKE 'verified_by'");
        if ($checkCol && mysqli_num_rows($checkCol) === 0) {
            mysqli_query($conn, "ALTER TABLE driver_account_documents ADD COLUMN verified_by INT NULL AFTER verified_at");
        }

        // Add rejection_reason column
        $checkCol = mysqli_query($conn, "SHOW COLUMNS FROM driver_account_documents LIKE 'rejection_reason'");
        if ($checkCol && mysqli_num_rows($checkCol) === 0) {
            mysqli_query($conn, "ALTER TABLE driver_account_documents ADD COLUMN rejection_reason VARCHAR(255) NULL AFTER verified_by");
        }

        return true;
    }

    public function down(mysqli $conn): bool {
        return true;
    }
};
