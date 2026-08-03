<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/route_overlap_helper.php';

/**
 * Unit tests for route_overlap_helper.php utility functions & algorithms.
 * No database connection required.
 */
class RouteOverlapHelperTest extends TestCase
{
    // ── Polyline Snapping Tests ─────────────────────────────────────────────

    public function testSnapPointToPolylineFindsClosestPoint(): void
    {
        $polyline = [
            [12.9800, 75.3000],
            [12.9850, 75.3100],
            [12.9900, 75.3200],
            [12.9950, 75.3300],
            [13.0000, 75.3400],
        ];

        // Target point very close to [12.9900, 75.3200]
        $targetLat = 12.9902;
        $targetLng = 75.3201;

        $snapped = ridesync_snap_point_to_polyline($targetLat, $targetLng, $polyline);

        $this->assertNotNull($snapped);
        $this->assertSame(2, $snapped['point_index']);
        $this->assertSame(12.9900, $snapped['snapped_lat']);
        $this->assertSame(75.3200, $snapped['snapped_lng']);
        $this->assertLessThan(0.1, $snapped['distance_km']);
    }

    // ── Route Overlap Matching Tests ─────────────────────────────────────────

    public function testComputeRouteOverlapSuccessForValidPartialPath(): void
    {
        $ride = [
            'origin' => 'SDMIT Ujire',
            'destination' => 'Mangaluru Central',
            'origin_lat' => 12.9800,
            'origin_lng' => 75.3000,
            'destination_lat' => 13.0000,
            'destination_lng' => 75.3400,
            'travel_date' => '2026-09-01',
            'travel_time' => '09:00:00',
            'encoded_polyline' => json_encode([
                [12.9800, 75.3000],
                [12.9850, 75.3100],
                [12.9900, 75.3200],
                [12.9950, 75.3300],
                [13.0000, 75.3400],
            ]),
        ];

        // Search B starts near segment index 1 and ends near segment index 3
        $search = [
            'origin' => 'Belthangady Near',
            'destination' => 'Bantwal Near',
            'origin_lat' => 12.9851,
            'origin_lng' => 75.3102,
            'destination_lat' => 12.9952,
            'destination_lng' => 75.3301,
            'travel_date' => '2026-09-01',
            'travel_time' => '09:05:00',
        ];

        $res = ridesync_compute_route_overlap($ride, $search);

        $this->assertTrue($res['is_match']);
        $this->assertGreaterThan(0, $res['overlap_percent']);
        $this->assertNotNull($res['pickup_lat']);
        $this->assertNotNull($res['dropoff_lat']);
        $this->assertLessThanOrEqual(RIDESYNC_ROUTE_PROXIMITY_THRESHOLD_KM, $res['pickup_distance_km']);
        $this->assertLessThanOrEqual(RIDESYNC_ROUTE_PROXIMITY_THRESHOLD_KM, $res['dropoff_distance_km']);
    }

    public function testComputeRouteOverlapRejectsReverseDirection(): void
    {
        $ride = [
            'origin_lat' => 12.9800,
            'origin_lng' => 75.3000,
            'destination_lat' => 13.0000,
            'destination_lng' => 75.3400,
            'travel_date' => '2026-09-01',
            'encoded_polyline' => json_encode([
                [12.9800, 75.3000],
                [12.9850, 75.3100],
                [12.9900, 75.3200],
                [12.9950, 75.3300],
                [13.0000, 75.3400],
            ]),
        ];

        // Search B travels in reverse direction (origin snaps to index 3, dest snaps to index 1)
        $reverseSearch = [
            'origin_lat' => 12.9951,
            'origin_lng' => 75.3301,
            'destination_lat' => 12.9851,
            'destination_lng' => 75.3101,
            'travel_date' => '2026-09-01',
        ];

        $res = ridesync_compute_route_overlap($ride, $reverseSearch);

        $this->assertFalse($res['is_match']);
        $this->assertStringContainsString('Reverse direction', $res['reason']);
    }

    public function testComputeRouteOverlapRejectsFarProximity(): void
    {
        $ride = [
            'origin_lat' => 12.9800,
            'origin_lng' => 75.3000,
            'destination_lat' => 13.0000,
            'destination_lng' => 75.3400,
            'travel_date' => '2026-09-01',
            'encoded_polyline' => json_encode([
                [12.9800, 75.3000],
                [13.0000, 75.3400],
            ]),
        ];

        // Search B origin is ~50 km away from A's route
        $farSearch = [
            'origin_lat' => 13.5000,
            'origin_lng' => 76.0000,
            'destination_lat' => 13.0000,
            'destination_lng' => 75.3400,
            'travel_date' => '2026-09-01',
        ];

        $res = ridesync_compute_route_overlap($ride, $farSearch);

        $this->assertFalse($res['is_match']);
        $this->assertStringContainsString('proximity threshold', $res['reason']);
    }

    public function testComputeRouteOverlapRejectsDateMismatch(): void
    {
        $ride = [
            'origin_lat' => 12.9800,
            'origin_lng' => 75.3000,
            'destination_lat' => 13.0000,
            'destination_lng' => 75.3400,
            'travel_date' => '2026-09-01',
        ];

        $mismatchedSearch = [
            'origin_lat' => 12.9810,
            'origin_lng' => 75.3010,
            'destination_lat' => 12.9990,
            'destination_lng' => 75.3390,
            'travel_date' => '2026-09-02', // Different date
        ];

        $res = ridesync_compute_route_overlap($ride, $mismatchedSearch);

        $this->assertFalse($res['is_match']);
        $this->assertSame('Travel date mismatch', $res['reason']);
    }
}
