<?php
/**
 * Migration 2026_08_04_000012_telemetry_indexes_and_suspension_tuning
 *
 * Adds composite indexes on telemetry & security tracking tables:
 * - ride_locations(ride_id, created_at DESC)
 * - sos_alerts(status, id DESC)
 */

return new class {
    public function up($conn) {
        $queries = [
            "ALTER TABLE `ride_locations` ADD INDEX `idx_ride_created` (`ride_id`, `created_at` DESC)",
            "ALTER TABLE `sos_alerts` ADD INDEX `idx_status_id` (`status`, `id` DESC)",
        ];

        foreach ($queries as $sql) {
            try {
                @mysqli_query($conn, $sql);
            } catch (Throwable $e) {
                // Index may already exist; suppress error gracefully
            }
        }

        return true;
    }

    public function down($conn) {
        $queries = [
            "ALTER TABLE `ride_locations` DROP INDEX `idx_ride_created`",
            "ALTER TABLE `sos_alerts` DROP INDEX `idx_status_id`",
        ];

        foreach ($queries as $sql) {
            try {
                @mysqli_query($conn, $sql);
            } catch (Throwable $e) {
                // Suppress rollback errors
            }
        }

        return true;
    }
};
