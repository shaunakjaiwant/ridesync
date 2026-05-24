<?php

namespace RideSync\Backend\Repositories;

final class SearchRepository extends BaseRepository
{
    public function adminSuggestions(string $context, string $query, int $limit): array
    {
        if (!function_exists('ridesync_admin_search_suggestions')) {
            require_once dirname(__DIR__, 2) . '/includes/search_suggestions_helper.php';
        }

        return ridesync_admin_search_suggestions($this->conn, $context, $query, $limit);
    }
}
