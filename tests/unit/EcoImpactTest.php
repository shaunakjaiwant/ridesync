<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/eco_helper.php';

class EcoImpactTest extends TestCase {

    public function testSoloDriverNoSavings(): void {
        $impact = ridesync_calculate_ride_eco_impact(15.0, 1);
        $this->assertSame(0.0, $impact['co2_saved_kg']);
        $this->assertSame(0, $impact['co2_saved_g']);
        $this->assertSame('0.0 kg CO2 saved', $impact['formatted']);
    }

    public function testTwoRidersCO2Savings(): void {
        // Distance 10 km, 2 riders sharing 1 car:
        // CO2 saved = (2 - 1) * 10 km * 0.12 kg/km = 1.2 kg CO2
        $impact = ridesync_calculate_ride_eco_impact(10.0, 2);
        $this->assertSame(1.2, $impact['co2_saved_kg']);
        $this->assertSame(1200, $impact['co2_saved_g']);
        $this->assertSame('1.2 kg CO2 saved', $impact['formatted']);
    }

    public function testFourRidersCO2Savings(): void {
        // Distance 25 km, 4 riders sharing 1 car:
        // CO2 saved = (4 - 1) * 25 km * 0.12 kg/km = 9.0 kg CO2
        $impact = ridesync_calculate_ride_eco_impact(25.0, 4);
        $this->assertSame(9.0, $impact['co2_saved_kg']);
        $this->assertSame(9000, $impact['co2_saved_g']);
        $this->assertSame('9.0 kg CO2 saved', $impact['formatted']);
    }

    public function testZeroDistance(): void {
        $impact = ridesync_calculate_ride_eco_impact(0.0, 3);
        $this->assertSame(0.0, $impact['co2_saved_kg']);
    }
}
