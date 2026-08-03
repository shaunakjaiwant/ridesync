<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/services/RepairKitService.php';

class RepairKitExecutionTest extends TestCase {

    public function testOperationsRegistryContainsAllKeys(): void {
        $ops = RideSyncRepairKitService::operations();
        $this->assertArrayHasKey('deep_scan', $ops);
        $this->assertArrayHasKey('flush_cache', $ops);
        $this->assertArrayHasKey('repair_queues', $ops);
        $this->assertArrayHasKey('ai_recovery', $ops);
        $this->assertArrayHasKey('repair_indexes', $ops);
        $this->assertArrayHasKey('optimize_tables', $ops);
        $this->assertArrayHasKey('rotate_logs', $ops);
        $this->assertArrayHasKey('storage_cleanup', $ops);
        $this->assertArrayHasKey('platform_recovery', $ops);
    }

    public function testConfirmationMismatchBlocksExecution(): void {
        $dummyConn = $this->createMock(mysqli::class);
        $res = RideSyncRepairKitService::execute($dummyConn, 1, 'flush_cache', 'WRONG_CONFIRMATION');

        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('Confirmation phrase did not match', $res['message']);
    }

    public function testUnsupportedOperationRejection(): void {
        $dummyConn = $this->createMock(mysqli::class);
        $res = RideSyncRepairKitService::execute($dummyConn, 1, 'invalid_operation_key', '');

        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('Unsupported repair operation', $res['message']);
    }
}
