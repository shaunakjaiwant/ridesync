<?php
require_once __DIR__ . '/http_helper.php';
require_once __DIR__ . '/rate_limit_helper.php';

function ridesync_api_timestamp(): string
{
    return gmdate('c');
}

function ridesync_api_envelope(array $payload = []): array
{
    return array_merge([
        'ok' => true,
        'request_id' => ridesync_request_id(),
        'timestamp' => ridesync_api_timestamp(),
    ], $payload);
}

function ridesync_api_success(array $data = [], int $statusCode = 200, array $meta = []): void
{
    ridesync_json_response(ridesync_api_envelope([
        'data' => $data,
        'meta' => $meta,
    ]), $statusCode);
}

function ridesync_api_error(string $code, string $message, int $statusCode = 400, array $details = []): void
{
    ridesync_json_response([
        'ok' => false,
        'error' => [
            'code' => preg_replace('/[^a-z0-9_.-]/', '_', strtolower($code)) ?: 'error',
            'message' => $message,
            'details' => $details,
        ],
        'request_id' => ridesync_request_id(),
        'timestamp' => ridesync_api_timestamp(),
    ], $statusCode);
}

function ridesync_api_require_method(string $method): void
{
    $method = strtoupper($method);
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === $method) {
        return;
    }

    if (!headers_sent()) {
        header('Allow: ' . $method);
    }

    ridesync_api_error('method_not_allowed', 'Method not allowed.', 405, [
        'allowed_method' => $method,
    ]);
}

function ridesync_api_param_int(string $name, int $default = 0, int $min = 0, int $max = PHP_INT_MAX): int
{
    $value = $_GET[$name] ?? $default;
    if (filter_var($value, FILTER_VALIDATE_INT) === false) {
        ridesync_api_error('invalid_parameter', "{$name} must be an integer.", 400, [
            'parameter' => $name,
        ]);
    }

    $intValue = (int) $value;
    if ($intValue < $min || $intValue > $max) {
        ridesync_api_error('invalid_parameter_range', "{$name} is outside the allowed range.", 400, [
            'parameter' => $name,
            'min' => $min,
            'max' => $max,
        ]);
    }

    return $intValue;
}

function ridesync_api_param_string(string $name, string $default = '', int $maxLength = 255): string
{
    $value = trim((string) ($_GET[$name] ?? $default));
    if (strlen($value) > $maxLength) {
        ridesync_api_error('invalid_parameter_length', "{$name} is too long.", 400, [
            'parameter' => $name,
            'max_length' => $maxLength,
        ]);
    }

    return $value;
}

function ridesync_api_enforce_rate_limit(string $scope, int $limit, int $windowSeconds, ?string $identity = null): void
{
    ridesync_enforce_rate_limit($scope, $limit, $windowSeconds, $identity, [
        'json' => true,
        'message' => 'Too many requests. Please retry shortly.',
    ]);
}

function ridesync_api_bearer_token(): string
{
    foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
        $value = trim((string) ($_SERVER[$key] ?? ''));
        if (stripos($value, 'Bearer ') === 0) {
            return trim(substr($value, 7));
        }
    }

    if (function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $name => $value) {
            if (strtolower((string) $name) === 'authorization' && stripos((string) $value, 'Bearer ') === 0) {
                return trim(substr((string) $value, 7));
            }
        }
    }

    return '';
}

function ridesync_api_require_static_token(string $envKey, int $minLength = 32): void
{
    $expected = trim((string) ridesync_env($envKey, ''));
    if (!ridesync_secret_is_configured($expected, $minLength)) {
        ridesync_api_error('token_not_configured', 'API token is not configured.', 503);
    }

    $provided = ridesync_api_bearer_token();
    if ($provided === '' || !hash_equals($expected, $provided)) {
        ridesync_api_error('unauthorized', 'Invalid or missing bearer token.', 401);
    }
}

function ridesync_api_validate_schema(array $data, array $schema): void
{
    foreach ($schema as $field => $rules) {
        $isRequired = in_array('required', $rules, true);
        if (!isset($data[$field])) {
            if ($isRequired) {
                ridesync_api_error('validation_error', "Field '{$field}' is required.", 400);
            }
            continue;
        }

        $val = $data[$field];
        if (in_array('int', $rules, true) && filter_var($val, FILTER_VALIDATE_INT) === false) {
            ridesync_api_error('validation_error', "Field '{$field}' must be an integer.", 400);
        }
        if (in_array('string', $rules, true) && !is_string($val)) {
            ridesync_api_error('validation_error', "Field '{$field}' must be a string.", 400);
        }
        if (in_array('float', $rules, true) && filter_var($val, FILTER_VALIDATE_FLOAT) === false) {
            ridesync_api_error('validation_error', "Field '{$field}' must be a float.", 400);
        }
    }
}

function ridesync_api_get_json_payload(array $schema = []): array
{
    $input = file_get_contents('php://input');
    $payload = json_decode($input, true);
    if (!is_array($payload)) {
        ridesync_api_error('invalid_json', 'Request body must be valid JSON.', 400);
    }

    if (!empty($schema)) {
        ridesync_api_validate_schema($payload, $schema);
    }

    return $payload;
}

?>
