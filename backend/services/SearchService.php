<?php

namespace RideSync\Backend\Services;

use mysqli;
use RideSync\Backend\Repositories\SearchRepository;

final class SearchService
{
    public function __construct(private mysqli $conn)
    {
    }

    public function adminSuggestions(string $context, string $query, int $limit): array
    {
        $query = function_exists('ridesync_search_query') ? ridesync_search_query($query) : substr(trim($query), 0, 120);
        if (strlen($query) < 2) {
            return [];
        }

        return (new SearchRepository($this->conn))->adminSuggestions($context, $query, $limit);
    }
}
