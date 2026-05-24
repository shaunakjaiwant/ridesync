<?php

namespace RideSync\Backend\Policies;

use mysqli;

final class AdminPolicy
{
    public static function requireActive(mysqli $conn): array
    {
        if (!isset($_SESSION['admin_id'])) {
            ridesync_api_error('authentication_required', 'Authentication required.', 401);
        }

        if (!function_exists('ridesync_fetch_admin')) {
            require_once dirname(__DIR__, 2) . '/includes/admin_helper.php';
        }

        $admin = ridesync_fetch_admin($conn, (int) $_SESSION['admin_id']);
        if (!$admin || ($admin['status'] ?? null) !== 'active') {
            ridesync_api_error('forbidden', 'Not allowed.', 403);
        }

        return $admin;
    }
}
