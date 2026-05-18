<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$root = realpath(__DIR__ . '/../..');
$specPath = $root . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'openapi.yaml';
$apiDir = $root . DIRECTORY_SEPARATOR . 'api';
$failures = [];

function contract_note($status, $message) {
    echo '[' . $status . '] ' . $message . PHP_EOL;
}

function contract_expect($condition, $message, array &$failures) {
    if (!$condition) {
        $failures[] = $message;
    }
}

if (!is_file($specPath)) {
    echo '[FAIL] OpenAPI spec missing: docs/openapi.yaml' . PHP_EOL;
    exit(1);
}

$lines = file($specPath, FILE_IGNORE_NEW_LINES);
$contents = implode("\n", $lines);
contract_expect(str_starts_with(trim($lines[0] ?? ''), 'openapi: 3.'), 'OpenAPI version header is missing or invalid', $failures);
contract_expect(str_contains($contents, "\ninfo:"), 'OpenAPI info section is missing', $failures);
contract_expect(str_contains($contents, "\npaths:"), 'OpenAPI paths section is missing', $failures);
contract_expect(str_contains($contents, "\ncomponents:"), 'OpenAPI components section is missing', $failures);

$paths = [];
$currentPath = null;
$inPaths = false;
$hasMethod = [];
$hasResponse = [];

foreach ($lines as $line) {
    if (preg_match('/^paths:\s*$/', $line)) {
        $inPaths = true;
        continue;
    }

    if ($inPaths && preg_match('/^[A-Za-z][A-Za-z0-9_-]*:\s*$/', $line)) {
        $inPaths = false;
        $currentPath = null;
    }

    if (!$inPaths) {
        continue;
    }

    if (preg_match('/^  (\/api\/[^:]+):\s*$/', $line, $matches)) {
        $currentPath = $matches[1];
        $paths[] = $currentPath;
        $hasMethod[$currentPath] = false;
        $hasResponse[$currentPath] = false;
        continue;
    }

    if ($currentPath !== null && preg_match('/^    (get|post|put|patch|delete):\s*$/', $line)) {
        $hasMethod[$currentPath] = true;
    }

    if ($currentPath !== null && preg_match('/^      responses:\s*$/', $line)) {
        $hasResponse[$currentPath] = true;
    }
}

sort($paths);
$actualApiFiles = [];
foreach (glob($apiDir . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
    $actualApiFiles[] = '/api/' . basename($file);
}
sort($actualApiFiles);

$undocumented = array_values(array_diff($actualApiFiles, $paths));
$missingFiles = array_values(array_diff($paths, $actualApiFiles));

contract_expect($undocumented === [], 'API files missing from OpenAPI: ' . implode(', ', $undocumented), $failures);
contract_expect($missingFiles === [], 'OpenAPI paths without PHP files: ' . implode(', ', $missingFiles), $failures);

foreach ($paths as $path) {
    contract_expect(!empty($hasMethod[$path]), "{$path} has no HTTP method", $failures);
    contract_expect(!empty($hasResponse[$path]), "{$path} has no responses block", $failures);
}

contract_expect(
    preg_match('/\/api\/ride_status\.php:[\s\S]*?"403":\s*\n\s+\$ref:\s*"#\/components\/responses\/Forbidden"/', $contents) === 1,
    'ride_status contract must document 403 Forbidden for unauthorized participants',
    $failures
);

if (!empty($failures)) {
    echo PHP_EOL . 'OpenAPI contract failures:' . PHP_EOL;
    foreach ($failures as $failure) {
        echo '- ' . $failure . PHP_EOL;
    }
    exit(1);
}

contract_note('OK', count($paths) . ' OpenAPI paths match api/*.php');
contract_note('OK', 'operation methods and responses are present');
contract_note('OK', 'ride_status forbidden contract is documented');
echo PHP_EOL . 'RideSync OpenAPI contract check passed.' . PHP_EOL;
?>
