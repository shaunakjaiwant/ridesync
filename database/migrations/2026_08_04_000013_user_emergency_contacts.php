<?php
/**
 * Migration 2026_08_04_000013_user_emergency_contacts
 *
 * Creates the user_emergency_contacts table for personal safety contacts.
 */

return new class {
    public function up($conn) {
        $sql = "CREATE TABLE IF NOT EXISTS `user_emergency_contacts` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `account_type` ENUM('rider', 'driver') NOT NULL DEFAULT 'rider',
            `user_id` INT NOT NULL,
            `name` VARCHAR(120) NOT NULL,
            `relationship` VARCHAR(60) NOT NULL DEFAULT 'Family',
            `phone_number` VARCHAR(32) NOT NULL,
            `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_user_account` (`account_type`, `user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        return mysqli_query($conn, $sql);
    }

    public function down($conn) {
        return mysqli_query($conn, "DROP TABLE IF EXISTS `user_emergency_contacts`");
    }
};
