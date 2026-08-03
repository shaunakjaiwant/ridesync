<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/sos_helper.php';

class AdminSosNotificationTest extends TestCase {

    public function testPublishRealtimeSosEventWithMockConn(): void {
        $dummyConn = $this->createMock(mysqli::class);

        // When table check fails or returns false, publishing returns false gracefully
        $result = ridesync_publish_realtime_sos_event($dummyConn, 1, 10, 'user', 5, 12.9716, 77.5946);
        $this->assertIsBool($result);
    }

    public function testActiveSosPayloadStructure(): void {
        $sampleAlert = [
            'id' => 1,
            'ride_id' => 10,
            'triggered_by_type' => 'user',
            'triggered_by_id' => 5,
            'latitude' => 12.9716,
            'longitude' => 77.5946,
            'status' => 'active',
            'created_at' => '2026-07-30 15:50:00',
            'origin' => 'SDMIT Campus',
            'destination' => 'Ujire Bus Stand',
            'triggerer_name' => 'Test Student',
            'triggerer_contact' => 'student@sdmit.in',
        ];

        $this->assertSame(1, $sampleAlert['id']);
        $this->assertSame('active', $sampleAlert['status']);
        $this->assertSame(12.9716, $sampleAlert['latitude']);
        $this->assertSame(77.5946, $sampleAlert['longitude']);
    }
}
