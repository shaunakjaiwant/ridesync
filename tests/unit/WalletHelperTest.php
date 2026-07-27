<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/cost_helper.php';

/**
 * Unit tests for wallet and fare calculation helpers.
 * These tests run without a database connection.
 */
class WalletHelperTest extends TestCase
{
    // ── calculateDynamicFareBreakdown ──────────────────────────────────────

    public function testDynamicFareBreakdownSoloRide(): void
    {
        $result = calculateDynamicFareBreakdown('SDMIT', 'Ujire', 1, 5.0);

        $this->assertSame(1, $result['participants']);
        $this->assertSame(0.0, $result['detour_km'], 'Solo ride should have no detour');
        $this->assertSame(0, $result['time_added_minutes'], 'Solo ride should add no time');
        $this->assertSame(0, $result['overlap_percent'], 'Solo ride has no route overlap');
        $this->assertGreaterThan(0, $result['final_fare'], 'Fare must be positive');
        $this->assertSame($result['solo_cost'], $result['final_fare'], 'Solo fare equals solo cost');
    }

    public function testDynamicFareBreakdownSharedRideIsCheeperPerPerson(): void
    {
        $solo   = calculateDynamicFareBreakdown('SDMIT', 'Ujire', 1, 15.0);
        $shared = calculateDynamicFareBreakdown('SDMIT', 'Ujire', 3, 15.0);

        $this->assertLessThan(
            $solo['final_fare'],
            $shared['final_fare'],
            'Per-person cost in a shared ride must be less than solo cost'
        );
    }

    public function testDynamicFareBreakdownUsesProvidedDistance(): void
    {
        $result = calculateDynamicFareBreakdown('A', 'B', 2, 10.0);

        $this->assertSame(10.0, $result['direct_distance_km']);
    }

    public function testDynamicFareBreakdownFallsBackToEstimateWithoutDistance(): void
    {
        $result = calculateDynamicFareBreakdown('SDMIT', 'Ujire', 2, null);

        $this->assertGreaterThan(0, $result['direct_distance_km']);
    }

    public function testDynamicFareBreakdownParticipantClamping(): void
    {
        // Should clamp to max 6
        $result = calculateDynamicFareBreakdown('A', 'B', 20, 10.0);
        $this->assertSame(6, $result['participants']);

        // Should clamp to min 1
        $result2 = calculateDynamicFareBreakdown('A', 'B', 0, 10.0);
        $this->assertSame(1, $result2['participants']);
    }

    public function testDynamicFareBreakdownReturnedKeysAreComplete(): void
    {
        $result = calculateDynamicFareBreakdown('Origin', 'Destination', 2, 8.5);

        $requiredKeys = [
            'origin', 'destination', 'pricing_version', 'rate_per_km',
            'participants', 'direct_distance_km', 'charged_distance_km',
            'shared_distance_km', 'personal_distance_km', 'detour_km',
            'time_added_minutes', 'overlap_percent', 'solo_cost',
            'base_route_fare', 'total_ride_cost', 'fair_base_split',
            'detour_charge', 'time_adjustment', 'final_fare',
            'allocated_total', 'savings_amount', 'savings_percent',
        ];

        foreach ($requiredKeys as $key) {
            $this->assertArrayHasKey($key, $result, "Missing key: {$key}");
        }
    }

    // ── calculateCostSplit ─────────────────────────────────────────────────

    public function testCostSplitWithDistanceAndRate(): void
    {
        // 10km * ₹25.6 = ₹256 total; split 2 = ₹128 each
        $result = calculateCostSplit(2, 0, 10, 25.6);

        $this->assertSame(256.0, $result['total_cost']);
        $this->assertSame(2, $result['total_riders']);
        $this->assertSame(128.0, $result['cost_per_person']);
        $this->assertSame(128.0, $result['savings']); // 256 - 128
    }

    public function testCostSplitSingleRiderNoSavings(): void
    {
        $result = calculateCostSplit(1, 0, 10, 25.6);

        $this->assertSame(256.0, $result['total_cost']);
        $this->assertSame(1, $result['total_riders']);
        $this->assertSame(256.0, $result['cost_per_person']);
        $this->assertSame(0.0, $result['savings']);
    }

    public function testCostSplitMinRidersClampedToOne(): void
    {
        $result = calculateCostSplit(0, 0, 5, 25.6);

        $this->assertSame(1, $result['total_riders']);
    }

    // ── ridesync_haversine_distance ────────────────────────────────────────

    public function testHaversineKnownDistance(): void
    {
        // SDMIT (13.3420, 75.2376) to Mangalore Central (~12.8654, 74.8424)
        // Expected ~63 km (approximate)
        $dist = ridesync_haversine_distance(13.3420, 75.2376, 12.8654, 74.8424);

        $this->assertGreaterThan(55.0, $dist, 'SDMIT-Mangalore distance should be > 55 km');
        $this->assertLessThan(75.0, $dist, 'SDMIT-Mangalore distance should be < 75 km');
    }

    public function testHaversineSamePointIsZero(): void
    {
        $dist = ridesync_haversine_distance(13.342, 75.237, 13.342, 75.237);
        $this->assertSame(0.0, $dist);
    }

    // ── ridesync_estimate_total_ride_fare ──────────────────────────────────

    public function testEstimateTotalFareIsPositive(): void
    {
        $fare = ridesync_estimate_total_ride_fare(10.0);
        $this->assertGreaterThan(0, $fare);
    }

    public function testEstimateTotalFareScalesWithDistance(): void
    {
        $short = ridesync_estimate_total_ride_fare(5.0);
        $long  = ridesync_estimate_total_ride_fare(20.0);
        $this->assertLessThan($long, $short);
    }
}
