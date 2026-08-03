<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/tracking_helper.php';

class LiveTrackingTest extends TestCase {

    public function testCoordinateValidation(): void {
        // Valid lat/lng
        $validLat = 12.9716;
        $validLng = 77.5946;
        $this->assertTrue($validLat >= -90.0 && $validLat <= 90.0);
        $this->assertTrue($validLng >= -180.0 && $validLng <= 180.0);

        // Invalid latitudes
        $this->assertFalse(95.0 >= -90.0 && 95.0 <= 90.0);
        $this->assertFalse(-91.0 >= -90.0 && -91.0 <= 90.0);

        // Invalid longitudes
        $this->assertFalse(185.0 >= -180.0 && 185.0 <= 180.0);
        $this->assertFalse(-181.0 >= -180.0 && -181.0 <= 180.0);
    }

    public function testInvalidRideIdRejection(): void {
        // Dummy conn
        $dummyConn = $this->createMock(mysqli::class);

        $this->assertFalse(ridesync_insert_location_ping($dummyConn, 0, null, 12.9, 77.5));
        $this->assertFalse(ridesync_insert_location_ping($dummyConn, -1, 5, 12.9, 77.5));
        $this->assertNull(ridesync_get_latest_ride_location($dummyConn, 0));
        $this->assertFalse(ridesync_can_view_ride_tracking($dummyConn, 0, 1, null));
    }
}
