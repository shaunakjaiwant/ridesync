<?php
// =============================================
// COST SPLITTING CALCULATOR
// =============================================

if (!defined('RIDESYNC_FARE_RATE_PER_KM')) {
    define('RIDESYNC_FARE_RATE_PER_KM', 25.6);
}

if (!defined('RIDESYNC_TIME_ADJUSTMENT_PER_MINUTE')) {
    define('RIDESYNC_TIME_ADJUSTMENT_PER_MINUTE', 1.25);
}

function ridesync_fare_rate_per_km() {
    return RIDESYNC_FARE_RATE_PER_KM;
}

function ridesync_time_adjustment_per_minute() {
    return RIDESYNC_TIME_ADJUSTMENT_PER_MINUTE;
}

function ridesync_route_seed($origin, $destination) {
    return abs(crc32(strtolower(trim($origin) . '|' . trim($destination))));
}

function ridesync_estimate_route_distance($origin, $destination) {
    // String-based fallback used only when real GPS coordinates are unavailable.
    // Uses a deterministic formula scaled to Karnataka route length distributions.
    $originLength = max(4, strlen(trim($origin)));
    $destinationLength = max(4, strlen(trim($destination)));
    $seed = ridesync_route_seed($origin, $destination);
    $distance = (($originLength + $destinationLength) * 0.52) + (($seed % 120) / 10);

    return round(max(4.5, min(58, $distance)), 1);
}

/**
 * Calculate real geographic distance between two lat/lng points using the
 * Haversine formula. Returns distance in kilometres.
 */
function ridesync_haversine_distance($lat1, $lon1, $lat2, $lon2): float {
    $lat1 = deg2rad((float) $lat1);
    $lon1 = deg2rad((float) $lon1);
    $lat2 = deg2rad((float) $lat2);
    $lon2 = deg2rad((float) $lon2);

    $dlat = $lat2 - $lat1;
    $dlon = $lon2 - $lon1;

    $a = sin($dlat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dlon / 2) ** 2;
    $c = 2 * asin(sqrt($a));

    return round($c * 6371, 2); // Earth radius 6371 km
}

function ridesync_normalize_distance_km($distanceKm, $fallbackKm = 1) {
    if (!is_numeric($distanceKm) || (float) $distanceKm <= 0) {
        $distanceKm = $fallbackKm;
    }

    return round(max(0.1, min(1000, (float) $distanceKm)), 2);
}

function ridesync_estimate_total_ride_fare($distanceKm) {
    return (int) ceil(ridesync_normalize_distance_km($distanceKm) * ridesync_fare_rate_per_km());
}

function ridesync_fare_preview_label($distanceKm) {
    $distanceKm = ridesync_normalize_distance_km($distanceKm);
    return formatCost(ridesync_estimate_total_ride_fare($distanceKm)) . ' ride fare @ ' . formatFareRate() . '/km';
}

function calculateCostSplit($totalRiders, $baseFare = 0, $distanceKm = 0, $ratePerKm = null) {
    $totalRiders = max(1, (int) $totalRiders);
    $ratePerKm = $ratePerKm === null ? ridesync_fare_rate_per_km() : (float) $ratePerKm;
    $totalCost = ($distanceKm > 0) ? ($baseFare + ($distanceKm * $ratePerKm)) : $baseFare;
    $costPerPerson = ceil($totalCost / $totalRiders);

    return [
        'total_cost' => (float) $totalCost,
        'total_riders' => $totalRiders,
        'cost_per_person' => (float) $costPerPerson,
        'savings' => (float) max(0, $totalCost - $costPerPerson)
    ];
}

function calculateDynamicFareBreakdown($origin, $destination, $participantCount = 2, $routeDistanceKm = null) {
    $participantCount = max(1, min(6, (int) $participantCount));
    $seed = ridesync_route_seed($origin, $destination);
    $ratePerKm = ridesync_fare_rate_per_km();
    $timeRate = ridesync_time_adjustment_per_minute();

    $routeDistanceKm = is_numeric($routeDistanceKm) ? (float) $routeDistanceKm : 0;
    $directDistance = $routeDistanceKm > 0
        ? round(ridesync_normalize_distance_km($routeDistanceKm), 1)
        : ridesync_estimate_route_distance($origin, $destination);

    $detourKm = $participantCount > 1
        ? round(min(5.8, max(0.4, (($seed % 24) / 10) + (($participantCount - 1) * 0.45))), 1)
        : 0.0;
    $chargedDistance = round($directDistance + $detourKm, 1);
    $overlapRatio = $participantCount > 1 ? min(0.88, 0.42 + ($participantCount * 0.09)) : 0;
    $sharedDistance = round($directDistance * $overlapRatio, 1);
    $personalDistance = round(max(0, $directDistance - $sharedDistance), 1);
    $overlapPercent = $participantCount > 1 ? (int) min(92, max(0, round(($sharedDistance / max(0.1, $directDistance)) * 100))) : 0;

    $timeAddedMinutes = $participantCount > 1
        ? (int) ceil(($detourKm / 18) * 60 + max(0, $participantCount - 1) * 2)
        : 0;

    $soloCost = ridesync_estimate_total_ride_fare($directDistance);
    $baseRouteFare = $soloCost;
    $fairBaseSplit = (int) ceil($baseRouteFare / $participantCount);
    $personalCost = (float) ($personalDistance * $ratePerKm);
    $sharedCostShare = $participantCount > 1 ? (float) (($sharedDistance * $ratePerKm) / $participantCount) : 0;
    $detourTotalCost = (float) ($detourKm * $ratePerKm);
    $timeTotalCost = (float) ($timeAddedMinutes * $timeRate);
    $detourCharge = (int) ceil($participantCount > 1 ? $detourTotalCost / $participantCount : 0);
    $timeAdjustment = (int) ceil($participantCount > 1 ? $timeTotalCost / $participantCount : 0);
    $totalRideCost = (int) ceil($baseRouteFare + $detourTotalCost + $timeTotalCost);
    $finalFare = (int) max(1, $fairBaseSplit + $detourCharge + $timeAdjustment);
    $allocatedTotal = (int) ($finalFare * $participantCount);
    $roundingAdjustment = $allocatedTotal - $totalRideCost;
    $savingsAmount = max(0, $soloCost - $finalFare);
    $savingsPercent = $soloCost > 0 ? (int) round(($savingsAmount / $soloCost) * 100) : 0;
    $sharedDistanceSavings = max(0, $soloCost - $fairBaseSplit);

    return [
        'origin' => $origin,
        'destination' => $destination,
        'pricing_version' => 'km_rate_v3_fair_split',
        'rate_per_km' => $ratePerKm,
        'time_rate_per_minute' => $timeRate,
        'participants' => $participantCount,
        'direct_distance_km' => $directDistance,
        'charged_distance_km' => $chargedDistance,
        'shared_distance_km' => $sharedDistance,
        'personal_distance_km' => $personalDistance,
        'detour_km' => $detourKm,
        'time_added_minutes' => $timeAddedMinutes,
        'overlap_percent' => $overlapPercent,
        'solo_cost' => $soloCost,
        'base_route_fare' => $baseRouteFare,
        'total_ride_cost' => $totalRideCost,
        'fair_base_split' => $fairBaseSplit,
        'personal_cost' => (int) ceil($personalCost),
        'shared_cost_share' => (int) ceil($sharedCostShare),
        'detour_total_cost' => (int) ceil($detourTotalCost),
        'time_total_cost' => (int) ceil($timeTotalCost),
        'shared_distance_savings' => $sharedDistanceSavings,
        'detour_charge' => $detourCharge,
        'time_adjustment' => $timeAdjustment,
        'final_fare' => $finalFare,
        'allocated_total' => $allocatedTotal,
        'rounding_adjustment' => $roundingAdjustment,
        'extra_vs_solo' => max(0, $finalFare - $soloCost),
        'savings_amount' => $savingsAmount,
        'savings_percent' => $savingsPercent
    ];
}

function formatCost($amount, $decimals = 0) {
    return '&#8377;' . number_format((float) $amount, (int) $decimals);
}

function formatFareRate($amount = null) {
    $amount = $amount === null ? ridesync_fare_rate_per_km() : (float) $amount;
    return '&#8377;' . number_format($amount, 2);
}
?>
