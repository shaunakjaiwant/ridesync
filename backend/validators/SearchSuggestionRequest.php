<?php

namespace RideSync\Backend\Validators;

final class SearchSuggestionRequest
{
    public static function fromQuery(): array
    {
        $context = ridesync_api_param_string('context', 'admin_global', 80);
        $query = ridesync_api_param_string('q', '', 120);
        $limit = ridesync_api_param_int('limit', 10, 1, 20);

        return [
            'context' => $context,
            'query' => $query,
            'limit' => $limit,
        ];
    }
}
