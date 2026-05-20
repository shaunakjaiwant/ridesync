<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$baseUrl = getenv('RIDESYNC_BASE_URL') ?: 'http://127.0.0.1/ridesync';
$failures = [];
$passes = 0;

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--base-url=')) {
        $baseUrl = substr($arg, strlen('--base-url='));
    }
    if ($arg === '--help' || $arg === '-h') {
        echo "Usage: php tests/api/negative_api.php [--base-url=https://host/ridesync]" . PHP_EOL;
        echo "Environment: RIDESYNC_BASE_URL may also provide the target base URL." . PHP_EOL;
        exit(0);
    }
}

$baseUrl = rtrim($baseUrl, '/');

function napi_note($status, $message) {
    echo '[' . $status . '] ' . $message . PHP_EOL;
}

function napi_request($baseUrl, $method, $path, array $headers = [], $body = null) {
    $headerLines = array_merge([
        'Accept: application/json',
        'User-Agent: RideSyncNegativeApiSuite/1.0',
        'X-RideSync-Negative-Test: 1',
    ], $headers);

    $options = [
        'http' => [
            'method' => strtoupper($method),
            'header' => implode("\r\n", $headerLines),
            'ignore_errors' => true,
            'timeout' => 12,
            'follow_location' => 0,
            'max_redirects' => 0,
        ],
    ];

    if ($body !== null) {
        $options['http']['content'] = $body;
    }

    $responseHeaders = [];
    $content = @file_get_contents($baseUrl . $path, false, stream_context_create($options));
    if (isset($http_response_header) && is_array($http_response_header)) {
        $responseHeaders = $http_response_header;
    }

    $status = 0;
    foreach ($responseHeaders as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', $header, $matches)) {
            $status = (int) $matches[1];
        }
    }

    return [
        'status' => $status,
        'headers' => $responseHeaders,
        'body' => $content === false ? '' : (string) $content,
    ];
}

function napi_header_value(array $headers, $name) {
    $prefix = strtolower($name) . ':';
    foreach ($headers as $header) {
        if (str_starts_with(strtolower($header), $prefix)) {
            return trim(substr($header, strlen($prefix)));
        }
    }

    return null;
}

function napi_decode_json($body) {
    $decoded = json_decode((string) $body, true);
    return is_array($decoded) ? $decoded : null;
}

function napi_expect($condition, $message, array &$failures) {
    if (!$condition) {
        $failures[] = $message;
    }
}

$cases = [
    [
        'name' => 'liveness stays public',
        'method' => 'GET',
        'path' => '/api/live.php',
        'status' => [200],
        'json' => true,
        'ok' => true,
    ],
    [
        'name' => 'liveness rejects unsafe method',
        'method' => 'POST',
        'path' => '/api/live.php',
        'status' => [405],
        'json' => true,
        'ok' => false,
    ],
    [
        'name' => 'readiness degrades without leaking internals',
        'method' => 'GET',
        'path' => '/api/readiness.php',
        'status' => [200, 503],
        'json' => true,
    ],
    [
        'name' => 'location suggestions reject unsafe method',
        'method' => 'POST',
        'path' => '/api/location_suggestions.php?q=SDMIT',
        'status' => [405],
        'json' => true,
        'ok' => false,
    ],
    [
        'name' => 'location suggestions tolerate short query',
        'method' => 'GET',
        'path' => '/api/location_suggestions.php?q=a',
        'status' => [200],
        'json' => true,
        'ok' => true,
        'empty_suggestions' => true,
    ],
    [
        'name' => 'driver state requires driver session',
        'method' => 'GET',
        'path' => '/api/driver_state.php',
        'status' => [401],
        'json' => true,
        'ok' => false,
    ],
    [
        'name' => 'ride status requires authenticated actor',
        'method' => 'GET',
        'path' => '/api/ride_status.php?ride_id=1',
        'status' => [401],
        'json' => true,
        'ok' => false,
    ],
    [
        'name' => 'ride status rejects unsafe method before auth',
        'method' => 'POST',
        'path' => '/api/ride_status.php?ride_id=1',
        'status' => [405],
        'json' => true,
        'ok' => false,
    ],
    [
        'name' => 'realtime token requires authenticated actor',
        'method' => 'GET',
        'path' => '/api/realtime_token.php',
        'status' => [401],
        'json' => true,
        'ok' => false,
    ],
    [
        'name' => 'realtime event poll requires authenticated actor',
        'method' => 'GET',
        'path' => '/api/realtime_events.php?after_id=-1&limit=5000',
        'status' => [401],
        'json' => true,
        'ok' => false,
    ],
    [
        'name' => 'admin search suggestions reject unsafe method before auth',
        'method' => 'POST',
        'path' => '/api/search_suggestions.php?q=test',
        'status' => [405],
        'json' => true,
        'ok' => false,
    ],
    [
        'name' => 'admin search suggestions require admin session',
        'method' => 'GET',
        'path' => '/api/search_suggestions.php?q=test&context=admin_global&limit=9999',
        'status' => [401],
        'json' => true,
        'ok' => false,
    ],
    [
        'name' => 'admin services reject unsafe method before auth',
        'method' => 'POST',
        'path' => '/api/admin_services.php',
        'status' => [405],
        'json' => true,
        'ok' => false,
    ],
    [
        'name' => 'admin services require admin session',
        'method' => 'GET',
        'path' => '/api/admin_services.php',
        'status' => [401],
        'json' => true,
        'ok' => false,
    ],
    [
        'name' => 'driver verification status requires admin session',
        'method' => 'GET',
        'path' => '/api/driver_verification_status.php?driver_id=1',
        'status' => [401],
        'json' => true,
        'ok' => false,
    ],
    [
        'name' => 'user-driver SSE requires actor session',
        'method' => 'GET',
        'path' => '/api/events.php?last_event_id=-100',
        'status' => [401],
    ],
    [
        'name' => 'admin SSE requires admin session',
        'method' => 'GET',
        'path' => '/api/admin_events.php?last_event_id=-100',
        'status' => [401],
    ],
    [
        'name' => 'driver verification SSE requires admin session',
        'method' => 'GET',
        'path' => '/api/driver_verification_events.php?driver_id=1',
        'status' => [401],
    ],
    [
        'name' => 'metrics endpoint rejects bad token when protected',
        'method' => 'GET',
        'path' => '/api/metrics.php',
        'headers' => ['Authorization: Bearer definitely-invalid'],
        'status' => [200, 403],
        'text' => true,
    ],
];

napi_note('INFO', 'Negative API target: ' . $baseUrl);

foreach ($cases as $case) {
    $caseFailures = [];
    $response = napi_request(
        $baseUrl,
        $case['method'],
        $case['path'],
        $case['headers'] ?? [],
        $case['body'] ?? null
    );
    $label = $case['method'] . ' ' . $case['path'];

    napi_expect(
        in_array($response['status'], $case['status'], true),
        $case['name'] . " expected HTTP " . implode('/', $case['status']) . ", got " . $response['status'],
        $caseFailures
    );

    $body = $response['body'];
    napi_expect(
        !preg_match('/(Fatal error|Stack trace|mysqli_sql_exception|Warning:|Notice:|Deprecated:|<br\s*\/?>)/i', $body),
        $case['name'] . ' leaked PHP or database internals',
        $caseFailures
    );

    if (!empty($case['json'])) {
        $contentType = napi_header_value($response['headers'], 'Content-Type') ?? '';
        $decoded = napi_decode_json($body);
        napi_expect(str_contains(strtolower($contentType), 'application/json'), $case['name'] . ' did not return JSON content type', $caseFailures);
        napi_expect(is_array($decoded), $case['name'] . ' did not return valid JSON', $caseFailures);

        if (is_array($decoded)) {
            if (array_key_exists('ok', $case)) {
                napi_expect(($decoded['ok'] ?? null) === $case['ok'], $case['name'] . ' returned unexpected ok flag', $caseFailures);
            }
            napi_expect(!empty($decoded['request_id']), $case['name'] . ' response is missing request_id', $caseFailures);
            if (!empty($case['empty_suggestions'])) {
                napi_expect(isset($decoded['suggestions']) && $decoded['suggestions'] === [], $case['name'] . ' should return no suggestions for a short query', $caseFailures);
            }
        }
    }

    if (!empty($case['text'])) {
        $contentType = napi_header_value($response['headers'], 'Content-Type') ?? '';
        napi_expect($contentType !== '', $case['name'] . ' did not return a content type', $caseFailures);
    }

    if (empty($caseFailures)) {
        $passes++;
        napi_note('OK', $case['name'] . ' (' . $label . ')');
    } else {
        foreach ($caseFailures as $failure) {
            $failures[] = $failure;
        }
        napi_note('FAIL', $case['name'] . ' (' . $label . ')');
    }
}

if (!empty($failures)) {
    echo PHP_EOL . 'Negative API failures:' . PHP_EOL;
    foreach ($failures as $failure) {
        echo '- ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo PHP_EOL . "RideSync negative API suite passed ({$passes} checks)." . PHP_EOL;
