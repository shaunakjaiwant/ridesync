<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$root = dirname(__DIR__);
$failures = [];

function pr_note($status, $message) {
    echo '[' . $status . '] ' . $message . PHP_EOL;
}

function pr_expect($condition, $message, array &$failures) {
    if (!$condition) {
        $failures[] = $message;
    }
}

function pr_read($root, $relative) {
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    return is_readable($path) ? (string) file_get_contents($path) : '';
}

function pr_env_template_keys($contents) {
    $keys = [];
    foreach (preg_split('/\R/', (string) $contents) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key] = explode('=', $line, 2);
        $keys[trim($key)] = true;
    }

    return $keys;
}

$envExample = pr_read($root, '.env.example');
$compose = pr_read($root, 'docker-compose.yml');
$dockerfile = pr_read($root, 'Dockerfile');
$apache = pr_read($root, 'docker/apache/ridesync.conf');
$readiness = pr_read($root, 'api/readiness.php');
$workflow = pr_read($root, '.github/workflows/quality.yml');
$runbook = pr_read($root, 'docs/production_runbook.md');

$requiredFiles = [
    '.env.example',
    'docker-compose.yml',
    'Dockerfile',
    'docker/apache/ridesync.conf',
    'api/live.php',
    'api/readiness.php',
    'api/metrics.php',
    'api/v1/health.php',
    'api/v1/readiness.php',
    'includes/api_helper.php',
    'docs/openapi.yaml',
    'docs/production_hardening_report_2026-05-24.md',
    'docs/dast_security_test_plan.md',
    'docs/dast_zap_validation_2026-05-21.md',
    'docs/screen_reader_test_plan.md',
    'docs/production_runbook.md',
    'tests/load/k6-production.js',
    'tools/db_bootstrap_check.php',
    '.github/workflows/quality.yml',
];
foreach ($requiredFiles as $relative) {
    pr_expect(is_readable($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative)), 'Missing production artifact: ' . $relative, $failures);
}
pr_note(count(array_filter($failures, static fn($failure) => str_starts_with($failure, 'Missing production artifact:'))) === 0 ? 'OK' : 'FAIL', 'production artifacts are present');

$requiredEnvKeys = [
    'RIDESYNC_ENV',
    'RIDESYNC_DEBUG',
    'RIDESYNC_DB_HOST',
    'RIDESYNC_DB_PORT',
    'RIDESYNC_DB_NAME',
    'RIDESYNC_DB_USER',
    'RIDESYNC_DB_PASSWORD',
    'RIDESYNC_DB_ROOT_PASSWORD',
    'RIDESYNC_COOKIE_SECURE',
    'RIDESYNC_DOCUMENT_ENCRYPTION_KEY',
    'RIDESYNC_REPAIR_LOG_KEY',
    'RIDESYNC_METRICS_TOKEN',
    'RIDESYNC_VERIFICATION_SERVICE_URL',
    'RIDESYNC_VERIFICATION_SERVICE_TOKEN',
    'RIDESYNC_WEBSOCKET_URL',
    'RIDESYNC_WS_SHARED_TOKEN',
    'RIDESYNC_REDIS_URL',
];
$envKeys = pr_env_template_keys($envExample);
foreach ($requiredEnvKeys as $key) {
    pr_expect(isset($envKeys[$key]), '.env.example missing required key: ' . $key, $failures);
}
pr_note(count(array_filter($failures, static fn($failure) => str_starts_with($failure, '.env.example missing required key:'))) === 0 ? 'OK' : 'FAIL', 'environment template contains production keys');

$requiredFailFastSecrets = [
    'RIDESYNC_DB_PASSWORD',
    'RIDESYNC_DB_ROOT_PASSWORD',
    'RIDESYNC_DOCUMENT_ENCRYPTION_KEY',
    'RIDESYNC_REPAIR_LOG_KEY',
    'RIDESYNC_METRICS_TOKEN',
    'RIDESYNC_VERIFICATION_SERVICE_TOKEN',
    'RIDESYNC_WEBSOCKET_URL',
    'RIDESYNC_WS_SHARED_TOKEN',
];
foreach ($requiredFailFastSecrets as $key) {
    pr_expect(str_contains($compose, '${' . $key . ':?'), 'docker-compose.yml does not fail fast for ' . $key, $failures);
}
pr_note(count(array_filter($failures, static fn($failure) => str_starts_with($failure, 'docker-compose.yml does not fail fast for'))) === 0 ? 'OK' : 'FAIL', 'compose fails fast on production secrets');

$readinessChecks = [
    'database',
    'schema',
    'storage',
    'logs',
    'rate_limits',
    'crypto',
    'document_crypto',
    'repair_log_crypto',
    'secure_cookies',
    'metrics_token',
    'websocket_config',
    'verification_service_config',
];
foreach ($readinessChecks as $check) {
    pr_expect(str_contains($readiness, "'" . $check . "'"), 'readiness endpoint missing check: ' . $check, $failures);
}
pr_note(count(array_filter($failures, static fn($failure) => str_starts_with($failure, 'readiness endpoint missing check:'))) === 0 ? 'OK' : 'FAIL', 'readiness endpoint checks production dependencies');

pr_expect(str_contains($apache, 'ServerTokens Prod'), 'Apache config must set ServerTokens Prod', $failures);
pr_expect(str_contains($apache, 'ServerSignature Off'), 'Apache config must set ServerSignature Off', $failures);
pr_expect(str_contains($apache, 'Require all denied') && str_contains($apache, 'storage'), 'Apache config must deny runtime storage', $failures);
pr_expect(str_contains($apache, 'DirectoryMatch') && str_contains($apache, 'includes') && str_contains($apache, 'config'), 'Apache config must deny internal directories', $failures);
pr_note(count(array_filter($failures, static fn($failure) => str_starts_with($failure, 'Apache config'))) === 0 ? 'OK' : 'FAIL', 'Apache production hardening is present');

pr_expect(str_contains($dockerfile, 'HEALTHCHECK'), 'Dockerfile must define a healthcheck', $failures);
pr_expect(str_contains($dockerfile, 'docker-php-ext-install mysqli'), 'Dockerfile must install mysqli', $failures);
pr_expect(str_contains($workflow, 'php tools/quality_gate.php --syntax-only'), 'GitHub quality workflow must run the syntax gate', $failures);
pr_expect(str_contains($workflow, 'php tools/db_bootstrap_check.php --required'), 'GitHub quality workflow must run DB bootstrap diagnostics', $failures);
pr_expect(str_contains($workflow, 'php tools/quality_gate.php') && str_contains($workflow, 'mysql:'), 'GitHub quality workflow must run the full DB-backed quality gate with MySQL', $failures);
pr_expect(str_contains($runbook, 'RIDESYNC_REPAIR_LOG_KEY'), 'production runbook must document RIDESYNC_REPAIR_LOG_KEY', $failures);
pr_expect(str_contains($runbook, 'DAST') && str_contains($runbook, 'screen-reader'), 'production runbook must require DAST and screen-reader release gates', $failures);
pr_note(count(array_filter($failures, static fn($failure) => str_contains($failure, 'Dockerfile') || str_contains($failure, 'workflow') || str_contains($failure, 'runbook'))) === 0 ? 'OK' : 'FAIL', 'deployment runbook and CI gates are documented');

if ($failures) {
    echo PHP_EOL . 'Production readiness check failures:' . PHP_EOL;
    foreach ($failures as $failure) {
        echo '- ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo PHP_EOL . 'RideSync production readiness static checks passed.' . PHP_EOL;
?>
