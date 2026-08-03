<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/sos_helper.php';

class SosAlertTest extends TestCase {

    public function testInvalidSosInputsRejection(): void {
        $dummyConn = $this->createMock(mysqli::class);

        // Invalid rideId <= 0
        $this->assertFalse(ridesync_create_sos_alert($dummyConn, 0, 'user', 1));
        $this->assertFalse(ridesync_create_sos_alert($dummyConn, -5, 'user', 1));

        // Invalid triggered_by_id <= 0
        $this->assertFalse(ridesync_create_sos_alert($dummyConn, 1, 'user', 0));

        // Invalid triggered_by_type
        $this->assertFalse(ridesync_create_sos_alert($dummyConn, 1, 'guest', 1));

        // Invalid alertId for resolution
        $this->assertFalse(ridesync_resolve_sos_alert($dummyConn, 0));
        $this->assertFalse(ridesync_resolve_sos_alert($dummyConn, -1));
    }

    public function testSosAlertNullLookup(): void {
        $dummyConn = $this->createMock(mysqli::class);
        $this->assertNull(ridesync_get_sos_alert_by_id($dummyConn, 0));
    }
}
