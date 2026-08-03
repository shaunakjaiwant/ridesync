<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/cost_helper.php';

/**
 * Calculate estimated CO2 emissions saved for a shared ride.
 *
 * Assumption:
 * Average petrol passenger car emits approx. 120g CO2/km (0.12 kg CO2/km).
 * Citation: European Environment Agency / EPA passenger car emissions averages.
 *
 * If 1 person drives alone: Emissions = 1 * distance * 0.12 kg CO2.
 * If N people share 1 vehicle: Total emissions = 1 * distance * 0.12 kg CO2.
 * Emissions avoided vs. everyone driving solo: (N - 1) * distance * 0.12 kg CO2.
 */
function ridesync_calculate_ride_eco_impact(float $distanceKm, int $riderCount): array {
    $distanceKm = max(0.0, $distanceKm);
    $riderCount = max(1, $riderCount);

    // Baseline: 0.12 kg (120g) CO2 per km per vehicle
    $emissionRateKgPerKm = 0.12;

    if ($riderCount <= 1 || $distanceKm <= 0.0) {
        return [
            'distance_km' => round($distanceKm, 2),
            'rider_count' => $riderCount,
            'co2_saved_kg' => 0.0,
            'co2_saved_g' => 0,
            'trees_equivalent' => 0.0,
            'formatted' => '0.0 kg CO2 saved',
        ];
    }

    $co2SavedKg = ($riderCount - 1) * $distanceKm * $emissionRateKgPerKm;
    $co2SavedG = (int) round($co2SavedKg * 1000);

    // Approximation: 1 mature tree absorbs ~22 kg CO2 per year (~0.06 kg per day)
    $treesEquivalent = round($co2SavedKg / 22.0, 2);

    return [
        'distance_km' => round($distanceKm, 2),
        'rider_count' => $riderCount,
        'co2_saved_kg' => round($co2SavedKg, 2),
        'co2_saved_g' => $co2SavedG,
        'trees_equivalent' => $treesEquivalent,
        'formatted' => number_format($co2SavedKg, 1) . ' kg CO2 saved',
    ];
}

/**
 * Aggregate eco-impact for a specific user across their completed/closed rides this month.
 */
function ridesync_get_user_monthly_eco_impact($conn, int $userId): array {
    $default = [
        'total_rides' => 0,
        'total_distance_km' => 0.0,
        'co2_saved_kg' => 0.0,
        'formatted' => '0.0 kg CO2 saved this month',
    ];

    if ($userId <= 0) {
        return $default;
    }

    // 1. Rides posted by user that are completed or closed with accepted riders
    $sqlOwner = "SELECT r.id, r.route_distance_km, r.origin, r.destination,
                        (SELECT COUNT(*) FROM matches m WHERE m.ride_id = r.id AND m.status = 'accepted') AS accepted_count
                 FROM rides r
                 LEFT JOIN ride_live_status ls ON ls.ride_id = r.id
                 WHERE r.user_id = ?
                   AND (r.status = 'closed' OR ls.live_status = 'completed')
                   AND r.travel_date >= DATE_FORMAT(NOW(), '%Y-%m-01')";

    $stmt = mysqli_prepare($conn, $sqlOwner);
    if (!$stmt) {
        return $default;
    }

    mysqli_stmt_bind_param($stmt, "i", $userId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);

    $totalRides = 0;
    $totalDistance = 0.0;
    $totalCo2Saved = 0.0;

    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $acceptedCount = (int) ($row['accepted_count'] ?? 0);
            if ($acceptedCount <= 0) {
                continue;
            }

            $distance = (float) ($row['route_distance_km'] ?? 0);
            if ($distance <= 0) {
                $distance = ridesync_estimate_route_distance($row['origin'], $row['destination']);
            }

            $riderCount = $acceptedCount + 1; // Owner + accepted passengers
            $impact = ridesync_calculate_ride_eco_impact($distance, $riderCount);

            $totalRides++;
            $totalDistance += $distance;
            $totalCo2Saved += $impact['co2_saved_kg'];
        }
    }
    mysqli_stmt_close($stmt);

    // 2. Rides joined by user (accepted matches) that are completed or closed
    $sqlPassenger = "SELECT r.id, r.route_distance_km, r.origin, r.destination,
                            (SELECT COUNT(*) FROM matches m2 WHERE m2.ride_id = r.id AND m2.status = 'accepted') AS accepted_count
                     FROM matches m
                     JOIN rides r ON r.id = m.ride_id
                     LEFT JOIN ride_live_status ls ON ls.ride_id = r.id
                     WHERE m.matched_user_id = ?
                       AND m.status = 'accepted'
                       AND (r.status = 'closed' OR ls.live_status = 'completed')
                       AND r.travel_date >= DATE_FORMAT(NOW(), '%Y-%m-01')";

    $stmt2 = mysqli_prepare($conn, $sqlPassenger);
    if ($stmt2) {
        mysqli_stmt_bind_param($stmt2, "i", $userId);
        mysqli_stmt_execute($stmt2);
        $res2 = mysqli_stmt_get_result($stmt2);
        if ($res2) {
            while ($row = mysqli_fetch_assoc($res2)) {
                $acceptedCount = (int) ($row['accepted_count'] ?? 0);
                $distance = (float) ($row['route_distance_km'] ?? 0);
                if ($distance <= 0) {
                    $distance = ridesync_estimate_route_distance($row['origin'], $row['destination']);
                }

                $riderCount = $acceptedCount + 1;
                $impact = ridesync_calculate_ride_eco_impact($distance, $riderCount);

                $totalRides++;
                $totalDistance += $distance;
                $totalCo2Saved += $impact['co2_saved_kg'];
            }
        }
        mysqli_stmt_close($stmt2);
    }

    return [
        'total_rides' => $totalRides,
        'total_distance_km' => round($totalDistance, 1),
        'co2_saved_kg' => round($totalCo2Saved, 1),
        'formatted' => number_format($totalCo2Saved, 1) . ' kg CO2 saved this month',
    ];
}
