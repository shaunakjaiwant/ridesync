<?php
require_once __DIR__ . '/../config/bootstrap.php';

$root = realpath(__DIR__ . '/..');
$failures = [];

function qg_note($status, $message) {
    echo '[' . $status . '] ' . $message . PHP_EOL;
}

function qg_run($command, $cwd, &$output = null) {
    $descriptor = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptor, $pipes, $cwd);
    if (!is_resource($process)) {
        $output = 'Could not start command: ' . $command;
        return 1;
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    $output = trim($stdout . ($stderr !== '' ? PHP_EOL . $stderr : ''));

    return $code;
}

function qg_collect_php_files($root) {
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            static function ($current) {
                if (!$current->isDir()) {
                    return true;
                }

                return !in_array($current->getFilename(), ['.git', 'node_modules', 'vendor', '.venv', '__pycache__'], true);
            }
        )
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
            $files[] = $file->getPathname();
        }
    }

    sort($files);
    return $files;
}

foreach (qg_collect_php_files($root) as $file) {
    $output = '';
    $code = qg_run('php -l ' . escapeshellarg($file), $root, $output);
    if ($code !== 0) {
        $failures[] = 'PHP lint failed: ' . $file . PHP_EOL . $output;
    }
}
qg_note(count($failures) === 0 ? 'OK' : 'FAIL', 'PHP syntax lint');

$pythonFiles = [
    $root . DIRECTORY_SEPARATOR . 'apps' . DIRECTORY_SEPARATOR . 'ai-verification' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'main.py',
    $root . DIRECTORY_SEPARATOR . 'apps' . DIRECTORY_SEPARATOR . 'ai-verification' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'worker.py',
    $root . DIRECTORY_SEPARATOR . 'apps' . DIRECTORY_SEPARATOR . 'ai-verification' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'providers.py',
    $root . DIRECTORY_SEPARATOR . 'apps' . DIRECTORY_SEPARATOR . 'ai-verification' . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'selftest_service.py',
    $root . DIRECTORY_SEPARATOR . 'apps' . DIRECTORY_SEPARATOR . 'ai-verification' . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'validate_provider_contract.py',
];
$pythonFiles = array_values(array_filter($pythonFiles, 'is_file'));
if (!empty($pythonFiles)) {
    $output = '';
    $command = 'python -c ' . escapeshellarg('import ast, pathlib, sys; [ast.parse(pathlib.Path(p).read_text(), filename=p) for p in sys.argv[1:]]')
        . ' ' . implode(' ', array_map('escapeshellarg', $pythonFiles));
    $code = qg_run($command, $root, $output);
    if ($code !== 0) {
        $failures[] = 'Python verification service syntax check failed.' . PHP_EOL . $output;
    }
    qg_note($code === 0 ? 'OK' : 'FAIL', 'AI verification service syntax');
}

$skipAiService = in_array('--syntax-only', $argv ?? [], true)
    || filter_var(getenv('RIDESYNC_SKIP_AI_SERVICE_TESTS') ?: 'false', FILTER_VALIDATE_BOOLEAN);
if ($skipAiService) {
    qg_note('SKIP', 'AI verification service self-test disabled');
} else {
    $output = '';
    $code = qg_run('python apps/ai-verification/scripts/selftest_service.py', $root, $output);
    if ($code !== 0) {
        $failures[] = 'AI verification service self-test failed.' . PHP_EOL . $output;
    }
    qg_note($code === 0 ? 'OK' : 'FAIL', 'AI verification service self-test');
}

$requiredOperationalFiles = [
    '.env.example',
    '.github/workflows/quality.yml',
    'Dockerfile',
    'docker-compose.yml',
    'docker-compose.monitoring.yml',
    'infrastructure/apache/ridesync.conf',
    'apps/ai-verification/Dockerfile',
    'apps/ai-verification/scripts/selftest_service.py',
    'apps/ai-verification/scripts/validate_provider_contract.py',
    'api/metrics.php',
    'api/realtime_token.php',
    'api/v1/health.php',
    'api/v1/readiness.php',
    'docs/device_lab_test_plan.md',
    'docs/dast_security_test_plan.md',
    'docs/dast_zap_validation_2026-05-21.md',
    'docs/openapi.yaml',
    'docs/production_hardening_report_2026-05-24.md',
    'docs/production_ops_validation_report.md',
    'docs/production_runbook.md',
    'docs/screen_reader_test_plan.md',
    'infrastructure/scripts/backup_mysql.sh',
    'infrastructure/scripts/restore_mysql.sh',
    'infrastructure/scripts/cron/ridesync-backup.cron',
    'infrastructure/scripts/cron/ridesync-maintenance.cron',
    'infrastructure/monitoring/prometheus.yml',
    'infrastructure/monitoring/alerts.yml',
    'infrastructure/monitoring/alertmanager.yml',
    'infrastructure/scripts/systemd/ridesync-backup.service',
    'infrastructure/scripts/systemd/ridesync-backup.timer',
    'infrastructure/scripts/systemd/ridesync-queue-worker.service',
    'includes/api_helper.php',
    'tools/db_bootstrap_check.php',
    'tools/production_readiness_check.php',
    'tools/prune_runtime_tables.php',
    'realtime/websocket-gateway/Dockerfile',
    'realtime/websocket-gateway/package.json',
    'realtime/websocket-gateway/package-lock.json',
    'realtime/websocket-gateway/server.js',
    'realtime/websocket-gateway/scripts/smoke_start.js',
    'tests/load/k6-smoke.js',
    'tests/load/k6-production.js',
    'tests/api/negative_api.php',
    'tests/api/openapi_contract.php',
    'tests/regression/ride_status_and_match_integrity.php',
    'tests/regression/session_principal_integrity.php',
    'tests/regression/security_surface_hardening.php',
];

foreach ($requiredOperationalFiles as $relativePath) {
    if (!is_file($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath))) {
        $failures[] = 'Missing operational readiness file: ' . $relativePath;
    }
}
qg_note(
    count(array_filter($failures, static fn($failure) => str_contains($failure, 'Missing operational readiness file'))) === 0 ? 'OK' : 'FAIL',
    'Operational readiness files'
);

$output = '';
$code = qg_run('php tools/production_readiness_check.php', $root, $output);
if ($code !== 0) {
    $failures[] = 'Production readiness static check failed' . PHP_EOL . $output;
}
qg_note($code === 0 ? 'OK' : 'FAIL', 'Production readiness static checks');

$output = '';
$code = qg_run('php tools/architecture_check.php', $root, $output);
if ($code !== 0) {
    $failures[] = 'Architecture check failed' . PHP_EOL . $output;
}
qg_note($code === 0 ? 'OK' : 'FAIL', 'Architecture checks');

$skipWs = in_array('--syntax-only', $argv ?? [], true)
    || filter_var(getenv('RIDESYNC_SKIP_WS_TESTS') ?: 'false', FILTER_VALIDATE_BOOLEAN);
if ($skipWs) {
    qg_note('SKIP', 'Websocket startup checks disabled');
} else {
    $output = '';
    $code = qg_run('npm run ws:check', $root, $output);
    if ($code !== 0) {
        $failures[] = 'Websocket startup check failed: npm run ws:check' . PHP_EOL . $output;
    }
    qg_note($code === 0 ? 'OK' : 'FAIL', 'Websocket startup checks');
}

$skipDb = in_array('--syntax-only', $argv ?? [], true)
    || filter_var(getenv('RIDESYNC_SKIP_DB_TESTS') ?: 'false', FILTER_VALIDATE_BOOLEAN);
if ($skipDb) {
    qg_note('SKIP', 'DB smoke checks disabled by RIDESYNC_SKIP_DB_TESTS');
} else {
    $output = '';
    $code = qg_run('php tools/db_bootstrap_check.php', $root, $output);
    if ($code !== 0) {
        $failures[] = 'DB bootstrap check failed' . PHP_EOL . $output;
    }

    foreach ([
        'php tools/smoke_check.php',
        'php tools/smoke_admin_dashboard.php overview',
        'php tools/smoke_admin_dashboard.php profiles',
        'php tools/smoke_admin_dashboard.php drivers',
        'php tools/smoke_admin_dashboard.php users',
        'php tools/smoke_admin_dashboard.php rides',
        'php tools/smoke_admin_dashboard.php requests',
        'php tools/smoke_admin_dashboard.php reports',
        'php tools/smoke_admin_dashboard.php remove',
        'php tools/smoke_admin_dashboard.php services',
        'php tools/smoke_panel_pages.php',
        'php tools/smoke_repair_kit.php',
        'php tools/smoke_admin_services_api.php',
        'php tools/smoke_admin_dashboard.php audit',
        'php tools/smoke_admin_dashboard.php bulk',
        'php tools/smoke_admin_view_as.php user',
        'php tools/smoke_admin_view_as.php driver',
        'php tools/smoke_admin_record_intelligence.php',
        'php tests/api/openapi_contract.php',
        'php tests/regression/ride_status_and_match_integrity.php',
        'php tests/regression/session_principal_integrity.php',
        'php tests/regression/security_surface_hardening.php',
    ] as $command) {
        $output = '';
        $code = qg_run($command, $root, $output);
        if ($code !== 0) {
            $failures[] = 'Smoke command failed: ' . $command . PHP_EOL . $output;
        }
    }
    qg_note(count(array_filter($failures, static fn($failure) => str_contains($failure, 'DB bootstrap check failed') || str_contains($failure, 'Smoke command failed'))) === 0 ? 'OK' : 'FAIL', 'DB smoke checks');
}

if (!empty($failures)) {
    echo PHP_EOL . 'Quality gate failures:' . PHP_EOL;
    foreach ($failures as $failure) {
        echo '- ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo PHP_EOL . 'RideSync quality gate passed.' . PHP_EOL;
