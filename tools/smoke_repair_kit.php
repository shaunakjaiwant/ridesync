<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/admin_helper.php';
require_once __DIR__ . '/../includes/services/RepairKitService.php';

$result = mysqli_query(
    $conn,
    "SELECT id, role
     FROM admin_users
     WHERE status = 'active'
     ORDER BY FIELD(role, 'super_admin', 'moderator'), id
     LIMIT 1"
);
$admin = $result ? mysqli_fetch_assoc($result) : null;
if (!$admin) {
    fwrite(STDERR, "No active admin available for Repair Kit smoke\n");
    exit(1);
}

$snapshot = RideSyncRepairKitService::snapshot($conn, ['force' => true]);
$actions = $snapshot['actions'] ?? [];
$summary = $snapshot['summary'] ?? [];
$execute = in_array('--execute', $argv ?? [], true);

$requiredActions = [
    'deep_scan',
    'force_health_recheck',
    'flush_cache',
    'repair_queues',
    'ai_recovery',
    'repair_indexes',
    'platform_recovery',
    'queue_full_restart',
    'queue_rollback',
];
$actionKeys = array_column($actions, 'key');
$missingActions = array_values(array_diff($requiredActions, $actionKeys));

$ok = is_array($summary)
    && array_key_exists('repair_score', $summary)
    && array_key_exists('critical_findings', $summary)
    && array_key_exists('warning_findings', $summary)
    && empty($missingActions)
    && RideSyncRepairKitService::schemaReady($conn);

if (!$ok) {
    fwrite(STDERR, "[FAIL] Repair Kit smoke failed\n");
    if (!RideSyncRepairKitService::schemaReady($conn)) {
        fwrite(STDERR, "- repair_kit_runs schema is not ready\n");
    }
    if (!empty($missingActions)) {
        fwrite(STDERR, "- missing actions: " . implode(', ', $missingActions) . "\n");
    }
    fwrite(STDERR, "- summary: " . json_encode($summary, JSON_UNESCAPED_SLASHES) . "\n");
    exit(1);
}

if ($execute) {
    $runResult = RideSyncRepairKitService::execute($conn, (int) $admin['id'], 'deep_scan', '');
    if (empty($runResult['ok'])) {
        fwrite(STDERR, "[FAIL] Repair Kit execution failed: " . ($runResult['message'] ?? 'unknown') . "\n");
        exit(1);
    }

    $runId = (int) ($runResult['details']['run_id'] ?? 0);
    if ($runId <= 0) {
        fwrite(STDERR, "[FAIL] Repair Kit execution did not return a run id\n");
        exit(1);
    }

    $stmt = mysqli_prepare($conn, "SELECT status, log_ciphertext, log_hash FROM repair_kit_runs WHERE id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'i', $runId);
    mysqli_stmt_execute($stmt);
    $runRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if (!$runRow || $runRow['status'] !== 'succeeded' || trim((string) $runRow['log_ciphertext']) === '' || strlen((string) $runRow['log_hash']) !== 64) {
        fwrite(STDERR, "[FAIL] Repair Kit run log was not persisted correctly\n");
        exit(1);
    }
}

echo "[OK] repair kit snapshot rendered with " . count($actions) . " action(s), score " . (int) $summary['repair_score'] . "\n";
?>
