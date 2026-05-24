<?php

namespace RideSync\Backend\Controllers\Api\V1;

use mysqli;
use RideSync\Backend\Policies\AdminPolicy;
use RideSync\Backend\Services\SearchService;
use RideSync\Backend\Validators\SearchSuggestionRequest;

final class SearchController
{
    public function adminSuggestions(mysqli $conn): void
    {
        ridesync_api_require_method('GET');
        $admin = AdminPolicy::requireActive($conn);

        ridesync_api_enforce_rate_limit('api:v1:search_suggestions', 180, 60, 'admin:' . (int) $admin['id']);
        $request = SearchSuggestionRequest::fromQuery();
        $suggestions = (new SearchService($conn))->adminSuggestions(
            $request['context'],
            $request['query'],
            $request['limit']
        );

        ridesync_api_success([
            'context' => $request['context'],
            'query' => $request['query'],
            'suggestions' => $suggestions,
        ], 200, [
            'count' => count($suggestions),
        ]);
    }
}
