<?php
define('RIDESYNC_ALLOW_DB_FAILURE', true);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/api_helper.php';
require_once __DIR__ . '/../../includes/admin_helper.php';
require_once __DIR__ . '/../../includes/search_suggestions_helper.php';

ridesync_api_require_method('GET');

if (!isset($_SESSION['admin_id'])) {
    ridesync_api_error('authentication_required', 'Authentication required.', 401);
}

if (!$conn instanceof mysqli) {
    ridesync_api_error('service_unavailable', 'Search suggestions are temporarily unavailable.', 503);
}

(new \RideSync\Backend\Controllers\Api\V1\SearchController())->adminSuggestions($conn);
