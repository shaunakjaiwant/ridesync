<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/matching_helper.php';

/**
 * Unit tests for matching_helper.php utility functions.
 * No database connection required.
 */
class MatchingHelperTest extends TestCase
{
    // ── ridesync_float_or_null ─────────────────────────────────────────────

    public function testFloatOrNullWithValidFloat(): void
    {
        $this->assertSame(3.14, ridesync_float_or_null('3.14'));
        $this->assertSame(0.0,  ridesync_float_or_null('0'));
        $this->assertSame(-5.5, ridesync_float_or_null('-5.5'));
    }

    public function testFloatOrNullWithNullAndEmpty(): void
    {
        $this->assertNull(ridesync_float_or_null(null));
        $this->assertNull(ridesync_float_or_null(''));
    }

    public function testFloatOrNullWithNonNumeric(): void
    {
        $this->assertNull(ridesync_float_or_null('not-a-number'));
        $this->assertNull(ridesync_float_or_null('12abc'));
    }

    // ── ridesync_haversine_km ──────────────────────────────────────────────

    public function testHaversineKmWithKnownCoordinates(): void
    {
        // ~1.11 km per 0.01 degree latitude change near equator
        $dist = ridesync_haversine_km(0, 0, 0.01, 0);
        $this->assertNotNull($dist);
        $this->assertEqualsWithDelta(1.11, $dist, 0.05);
    }

    public function testHaversineKmSamePointReturnsZero(): void
    {
        $dist = ridesync_haversine_km(13.342, 75.237, 13.342, 75.237);
        $this->assertSame(0.0, $dist);
    }

    public function testHaversineKmWithNonNumericReturnsNull(): void
    {
        $this->assertNull(ridesync_haversine_km('bad', 75.0, 13.0, 75.5));
    }

    // ── ridesync_estimate_route_distance ──────────────────────────────────

    public function testEstimateRouteDistanceIsPositive(): void
    {
        $dist = ridesync_estimate_route_distance('SDMIT', 'Ujire');
        $this->assertGreaterThan(0, $dist);
    }

    public function testEstimateRouteDistanceIsDeterministic(): void
    {
        $dist1 = ridesync_estimate_route_distance('Mangaluru', 'Dharmasthala');
        $dist2 = ridesync_estimate_route_distance('Mangaluru', 'Dharmasthala');
        $this->assertSame($dist1, $dist2);
    }

    public function testEstimateRouteDistanceIsWithinBounds(): void
    {
        foreach ([
            ['SDMIT', 'Ujire'],
            ['Mangaluru Airport', 'NITK Surathkal'],
            ['KSRTC Bus Stand', 'Dharmasthala'],
        ] as [$origin, $dest]) {
            $dist = ridesync_estimate_route_distance($origin, $dest);
            $this->assertGreaterThanOrEqual(4.5, $dist, "{$origin} → {$dest} below minimum");
            $this->assertLessThanOrEqual(58.0, $dist, "{$origin} → {$dest} above maximum");
        }
    }

    // ── ridesync_normalize_distance_km ────────────────────────────────────

    public function testNormalizeValidPassthrough(): void
    {
        $this->assertSame(5.5, ridesync_normalize_distance_km(5.5));
        $this->assertSame(0.1, ridesync_normalize_distance_km(0.001));   // clamp up
        $this->assertSame(1000.0, ridesync_normalize_distance_km(9999)); // clamp down
    }

    public function testNormalizeFallsBackForInvalidInput(): void
    {
        $this->assertSame(2.0, ridesync_normalize_distance_km('invalid', 2.0));
        $this->assertSame(1.0, ridesync_normalize_distance_km(null));    // default fallback
        $this->assertSame(1.0, ridesync_normalize_distance_km(0));       // zero treated as invalid
        $this->assertSame(1.0, ridesync_normalize_distance_km(-10));     // negative treated as invalid
    }
}
