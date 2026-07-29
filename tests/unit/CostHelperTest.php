<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/cost_helper.php';

class CostHelperTest extends TestCase {
    public function testRidesyncNormalizeDistanceKm(): void {
        // Normal distance passes through unchanged
        $this->assertSame(5.5, ridesync_normalize_distance_km(5.5));

        // Non-numeric string falls back to provided fallback
        $this->assertSame(2.0, ridesync_normalize_distance_km("invalid", 2.0));

        // Negative value is invalid (<= 0), so falls back to default fallback (1.0),
        // then clamped to max(0.1, 1.0) = 1.0
        $this->assertSame(1.0, ridesync_normalize_distance_km(-5));

        // Negative with explicit fallback uses the fallback, clamped to min 0.1
        $this->assertSame(0.1, ridesync_normalize_distance_km(-5, 0.0));

        // Zero is treated as invalid, falls back to default (1.0)
        $this->assertSame(1.0, ridesync_normalize_distance_km(0));

        // Exceeds max 1000 gets clamped down
        $this->assertSame(1000.0, ridesync_normalize_distance_km(2000));

        // Tiny positive value is valid and clamped up to 0.1
        $this->assertSame(0.1, ridesync_normalize_distance_km(0.001));
    }

    public function testCalculateCostSplit(): void {
        // totalCost = (10km * 25.6) = 256
        // costPerPerson = 256 / 2 = 128
        $result = calculateCostSplit(2, 0, 10, 25.6);

        $this->assertSame(256.0, $result['total_cost']);
        $this->assertSame(2, $result['total_riders']);
        $this->assertSame(128.0, $result['cost_per_person']);
        $this->assertSame(128.0, $result['savings']); // 256 - 128
    }
}

