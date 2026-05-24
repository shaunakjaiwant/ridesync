<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$root = dirname(__DIR__);
require_once $root . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'bootstrap.php';
$failures = [];

function arch_note(string $status, string $message): void
{
    echo '[' . $status . '] ' . $message . PHP_EOL;
}

function arch_expect(bool $condition, string $message, array &$failures): void
{
    if (!$condition) {
        $failures[] = $message;
    }
}

function arch_path(string $root, string $relative): string
{
    return $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
}

function arch_read(string $root, string $relative): string
{
    $path = arch_path($root, $relative);
    return is_file($path) ? (string) file_get_contents($path) : '';
}

$requiredDirectories = [
    'apps/web',
    'apps/admin',
    'apps/api',
    'apps/ai-verification',
    'backend/controllers',
    'backend/services',
    'backend/repositories',
    'backend/policies',
    'backend/middlewares',
    'backend/validators',
    'backend/events',
    'backend/jobs',
    'backend/listeners',
    'backend/dto',
    'backend/enums',
    'backend/contracts',
    'backend/helpers',
    'infrastructure/apache',
    'infrastructure/docker',
    'infrastructure/redis',
    'infrastructure/mysql',
    'infrastructure/monitoring',
    'infrastructure/prometheus',
    'infrastructure/grafana',
    'infrastructure/scripts',
    'realtime/websocket-gateway',
    'realtime/pubsub',
    'realtime/events',
    'realtime/presence',
    'ai/ocr',
    'ai/verification',
    'ai/face-matching',
    'ai/fraud-detection',
    'ai/providers',
    'ai/pipelines',
    'ai/workers',
    'database/migrations',
    'database/seeds',
    'database/factories',
    'database/procedures',
    'database/backups',
    'database/optimization',
    'storage/uploads',
    'storage/temp',
    'storage/logs',
    'storage/cache',
    'storage/exports',
    'tests/unit',
    'tests/integration',
    'tests/api',
    'tests/security',
    'tests/load',
    'tests/realtime',
    'tests/ai',
    'docs/api',
    'docs/architecture',
    'docs/deployment',
    'docs/security',
    'docs/runbooks',
    'tools/diagnostics',
    'tools/repair',
    'tools/migration',
    'tools/automation',
];

foreach ($requiredDirectories as $relative) {
    arch_expect(is_dir(arch_path($root, $relative)), 'Missing architecture directory: ' . $relative, $failures);
}
arch_note(count(array_filter($failures, static fn($failure) => str_starts_with($failure, 'Missing architecture directory:'))) === 0 ? 'OK' : 'FAIL', 'canonical directories are present');

foreach ([
    'ai_verification_service',
    'websocket_gateway',
    'ops',
    'docker/apache',
] as $legacyPath) {
    arch_expect(!file_exists(arch_path($root, $legacyPath)), 'Legacy top-level path still exists: ' . $legacyPath, $failures);
}
arch_note(count(array_filter($failures, static fn($failure) => str_starts_with($failure, 'Legacy top-level path still exists:'))) === 0 ? 'OK' : 'FAIL', 'legacy service islands are removed');

$requiredFiles = [
    'backend/bootstrap.php',
    'backend/controllers/RideStatusController.php',
    'backend/controllers/Api/V1/SearchController.php',
    'backend/services/RideLifecycleService.php',
    'backend/repositories/RideRepository.php',
    'backend/repositories/SearchRepository.php',
    'realtime/pubsub/RedisPubSubPublisher.php',
    'api/v1/search_suggestions.php',
    'docs/architecture/overview.md',
    'docs/architecture/structure.md',
    'config/app.php',
    'config/database.php',
    'config/cache.php',
    'config/websocket.php',
    'config/ai.php',
    'config/kyc.php',
    'config/security.php',
    'config/queue.php',
    'config/storage.php',
];

foreach ($requiredFiles as $relative) {
    arch_expect(is_file(arch_path($root, $relative)), 'Missing architecture artifact: ' . $relative, $failures);
}
arch_note(count(array_filter($failures, static fn($failure) => str_starts_with($failure, 'Missing architecture artifact:'))) === 0 ? 'OK' : 'FAIL', 'architecture artifacts are present');

$package = arch_read($root, 'package.json');
$compose = arch_read($root, 'docker-compose.yml');
$dockerfile = arch_read($root, 'Dockerfile');
$workflow = arch_read($root, '.github/workflows/quality.yml');
$apache = arch_read($root, 'infrastructure/apache/ridesync.conf');

arch_expect(str_contains($package, 'apps/ai-verification/scripts/selftest_service.py'), 'package scripts must use apps/ai-verification', $failures);
arch_expect(str_contains($package, 'realtime/websocket-gateway'), 'package scripts must use realtime/websocket-gateway', $failures);
arch_expect(str_contains($compose, './apps/ai-verification'), 'docker-compose.yml must build the AI service from apps/ai-verification', $failures);
arch_expect(str_contains($compose, './realtime/websocket-gateway'), 'docker-compose.yml must build the websocket gateway from realtime/websocket-gateway', $failures);
arch_expect(str_contains($dockerfile, 'infrastructure/apache/ridesync.conf'), 'Dockerfile must copy Apache config from infrastructure/apache', $failures);
if ($workflow !== '') {
    arch_expect(str_contains($workflow, 'apps/ai-verification/requirements.txt'), 'quality workflow must install AI dependencies from apps/ai-verification', $failures);
}
arch_expect(str_contains($apache, 'apps') && str_contains($apache, 'backend') && str_contains($apache, 'infrastructure') && str_contains($apache, 'realtime'), 'Apache config must deny internal architecture directories', $failures);
arch_note(count(array_filter($failures, static fn($failure) => str_contains($failure, 'must use') || str_contains($failure, 'must build') || str_contains($failure, 'must copy') || str_contains($failure, 'must install') || str_contains($failure, 'must deny'))) === 0 ? 'OK' : 'FAIL', 'runtime references use canonical paths');

$rideAction = arch_read($root, 'actions/ride_status_action.php');
arch_expect(str_contains($rideAction, 'RideStatusController'), 'ride status action must delegate to backend controller', $failures);
arch_expect(!str_contains($rideAction, 'mysqli_prepare'), 'ride status action must not contain SQL', $failures);
arch_expect(!str_contains($rideAction, 'mysqli_begin_transaction'), 'ride status action must not own transaction flow', $failures);
arch_note(count(array_filter($failures, static fn($failure) => str_starts_with($failure, 'ride status action'))) === 0 ? 'OK' : 'FAIL', 'critical action delegates to controller/service/repository');

if ($failures) {
    echo PHP_EOL . 'Architecture check failures:' . PHP_EOL;
    foreach ($failures as $failure) {
        echo '- ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo PHP_EOL . 'RideSync architecture check passed.' . PHP_EOL;
