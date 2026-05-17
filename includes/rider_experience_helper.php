<?php
require_once __DIR__ . '/../config/bootstrap.php';

function ridesync_fetch_rider_route_suggestions(mysqli $conn, int $userId, int $limit = 6): array
{
    $limit = max(1, min(12, $limit));
    $stmt = mysqli_prepare($conn,
        "SELECT origin, destination, COUNT(*) AS use_count, MAX(id) AS sample_ride_id, MAX(created_at) AS last_used
         FROM rides
         WHERE user_id = ?
         GROUP BY origin, destination
         ORDER BY use_count DESC, last_used DESC
         LIMIT ?"
    );
    if (!$stmt) {
        return [];
    }

    mysqli_stmt_bind_param($stmt, 'ii', $userId, $limit);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $suggestions = [];

    while ($row = $result ? mysqli_fetch_assoc($result) : null) {
        $origin = trim((string) ($row['origin'] ?? ''));
        $destination = trim((string) ($row['destination'] ?? ''));
        if ($origin === '' || $destination === '') {
            continue;
        }

        $useCount = (int) ($row['use_count'] ?? 0);
        $suggestions[] = [
            'label' => $useCount > 1 ? 'Frequent route' : 'Recent route',
            'origin' => $origin,
            'destination' => $destination,
            'meta' => $useCount > 1 ? $useCount . ' rides posted' : 'Use this route again',
            'sample_ride_id' => (int) ($row['sample_ride_id'] ?? 0),
            'kind' => 'history',
        ];
    }

    mysqli_stmt_close($stmt);
    return $suggestions;
}

function ridesync_fetch_rider_college(mysqli $conn, int $userId): string
{
    $stmt = mysqli_prepare($conn, "SELECT college FROM users WHERE id = ? LIMIT 1");
    if (!$stmt) {
        return '';
    }

    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    return trim((string) ($row['college'] ?? ''));
}

function ridesync_build_rider_route_shortcuts(mysqli $conn, int $userId, int $limit = 6): array
{
    $history = ridesync_fetch_rider_route_suggestions($conn, $userId, $limit);
    $shortcuts = $history;

    $college = ridesync_fetch_rider_college($conn, $userId);
    if ($college !== '') {
        foreach ($history as $route) {
            if (strcasecmp($route['origin'], $college) === 0 || strcasecmp($route['destination'], $college) === 0) {
                continue;
            }

            $shortcuts[] = [
                'label' => 'College shortcut',
                'origin' => $college,
                'destination' => $route['destination'],
                'meta' => 'Start from your college',
                'sample_ride_id' => 0,
                'kind' => 'college',
            ];
            break;
        }
    }

    foreach ($history as $route) {
        $shortcuts[] = [
            'label' => 'Reverse route',
            'origin' => $route['destination'],
            'destination' => $route['origin'],
            'meta' => 'Return trip shortcut',
            'sample_ride_id' => 0,
            'kind' => 'reverse',
        ];
        break;
    }

    $seen = [];
    $deduped = [];
    foreach ($shortcuts as $shortcut) {
        $key = strtolower($shortcut['origin'] . '|' . $shortcut['destination']);
        if (isset($seen[$key]) || trim($shortcut['origin']) === '' || trim($shortcut['destination']) === '') {
            continue;
        }
        $seen[$key] = true;
        $deduped[] = $shortcut;
        if (count($deduped) >= $limit) {
            break;
        }
    }

    return $deduped;
}

function ridesync_fetch_rebook_prefill(mysqli $conn, int $userId, int $rideId): ?array
{
    if ($rideId <= 0) {
        return null;
    }

    $stmt = mysqli_prepare($conn,
        "SELECT origin, destination, origin_lat, origin_lng, destination_lat, destination_lng,
                route_distance_km, travel_time, seats_available
         FROM rides
         WHERE id = ? AND user_id = ?
         LIMIT 1"
    );
    if (!$stmt) {
        return null;
    }

    mysqli_stmt_bind_param($stmt, 'ii', $rideId, $userId);
    mysqli_stmt_execute($stmt);
    $ride = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if (!$ride) {
        return null;
    }

    return [
        'origin' => (string) $ride['origin'],
        'destination' => (string) $ride['destination'],
        'origin_lat' => (string) ($ride['origin_lat'] ?? ''),
        'origin_lng' => (string) ($ride['origin_lng'] ?? ''),
        'destination_lat' => (string) ($ride['destination_lat'] ?? ''),
        'destination_lng' => (string) ($ride['destination_lng'] ?? ''),
        'route_distance_km' => (string) ($ride['route_distance_km'] ?? ''),
        'travel_date' => date('Y-m-d', strtotime('+1 day')),
        'travel_time' => substr((string) ($ride['travel_time'] ?? ''), 0, 5),
        'seats_available' => (string) max(1, min(5, (int) ($ride['seats_available'] ?? 1))),
    ];
}

function ridesync_route_query(array $route, array $extra = []): string
{
    return http_build_query(array_merge([
        'origin' => $route['origin'] ?? '',
        'destination' => $route['destination'] ?? '',
    ], $extra));
}

function ridesync_default_user_trust_summary(): array
{
    return [
        'verified' => false,
        'rating_average' => null,
        'rating_count' => 0,
    ];
}

function ridesync_fetch_user_trust_summaries(mysqli $conn, array $userIds): array
{
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn($id) => $id > 0)));
    if (count($userIds) === 0) {
        return [];
    }

    $summaries = [];
    foreach ($userIds as $userId) {
        $summaries[$userId] = ridesync_default_user_trust_summary();
    }

    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $types = str_repeat('i', count($userIds));

    if (ridesync_table_exists($conn, 'user_verifications')) {
        $stmt = mysqli_prepare($conn,
            "SELECT user_id
             FROM user_verifications
             WHERE user_id IN ($placeholders) AND status = 'verified'
             GROUP BY user_id"
        );
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, $types, ...$userIds);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            while ($row = $result ? mysqli_fetch_assoc($result) : null) {
                $verifiedUserId = (int) ($row['user_id'] ?? 0);
                if (isset($summaries[$verifiedUserId])) {
                    $summaries[$verifiedUserId]['verified'] = true;
                }
            }
            mysqli_stmt_close($stmt);
        }
    }

    if (ridesync_table_exists($conn, 'user_ratings')) {
        $stmt = mysqli_prepare($conn,
            "SELECT reviewed_user_id, AVG(rating) AS rating_average, COUNT(*) AS rating_count
             FROM user_ratings
             WHERE reviewed_user_id IN ($placeholders)
             GROUP BY reviewed_user_id"
        );
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, $types, ...$userIds);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            while ($row = $result ? mysqli_fetch_assoc($result) : null) {
                $reviewedUserId = (int) ($row['reviewed_user_id'] ?? 0);
                if (isset($summaries[$reviewedUserId])) {
                    $summaries[$reviewedUserId]['rating_average'] = round((float) ($row['rating_average'] ?? 0), 1);
                    $summaries[$reviewedUserId]['rating_count'] = (int) ($row['rating_count'] ?? 0);
                }
            }
            mysqli_stmt_close($stmt);
        }
    }

    return $summaries;
}
?>
