<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/matching_helper.php';

/**
 * Submit a rating for a ride participant.
 */
function ridesync_submit_rating($conn, int $rideId, string $ratedByType, int $ratedById, string $ratedUserType, int $ratedUserId, int $score, ?string $comment = null): bool {
    if ($rideId <= 0 || $ratedById <= 0 || $ratedUserId <= 0) {
        return false;
    }

    if ($score < 1 || $score > 5) {
        return false;
    }

    $validTypes = ['user', 'driver'];
    if (!in_array($ratedByType, $validTypes, true) || !in_array($ratedUserType, $validTypes, true)) {
        return false;
    }

    if ($ratedByType === $ratedUserType && $ratedById === $ratedUserId) {
        return false;
    }

    if ($comment !== null) {
        $comment = trim($comment);
        if (strlen($comment) > 500) {
            $comment = substr($comment, 0, 500);
        }
        if ($comment === '') {
            $comment = null;
        }
    }

    $stmt = mysqli_prepare($conn,
        "INSERT INTO ratings (ride_id, rated_by_type, rated_by_id, rated_user_type, rated_user_id, score, comment)
         VALUES (?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE score = VALUES(score), comment = VALUES(comment), created_at = CURRENT_TIMESTAMP"
    );

    if (!$stmt) {
        return false;
    }

    mysqli_stmt_bind_param($stmt, "isisiss", $rideId, $ratedByType, $ratedById, $ratedUserType, $ratedUserId, $score, $comment);
    $result = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    return (bool) $result;
}

/**
 * Check if a user/driver has already rated a ride.
 */
function ridesync_has_user_rated_ride($conn, int $rideId, string $ratedByType, int $ratedById): bool {
    if ($rideId <= 0 || $ratedById <= 0) {
        return false;
    }

    $stmt = mysqli_prepare($conn,
        "SELECT id FROM ratings WHERE ride_id = ? AND rated_by_type = ? AND rated_by_id = ? LIMIT 1"
    );

    if (!$stmt) {
        return false;
    }

    mysqli_stmt_bind_param($stmt, "isi", $rideId, $ratedByType, $ratedById);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $hasRated = $res && mysqli_num_rows($res) > 0;
    mysqli_stmt_close($stmt);

    return $hasRated;
}

/**
 * Fetch average rating and count for a user or driver.
 */
function ridesync_get_user_rating_summary($conn, string $userType, int $userId): array {
    $default = [
        'avg_score' => 0.0,
        'count' => 0,
        'display' => 'No ratings yet',
        'formatted' => 'N/A'
    ];

    if ($userId <= 0 || !in_array($userType, ['user', 'driver'], true)) {
        return $default;
    }

    $stmt = mysqli_prepare($conn,
        "SELECT AVG(score) as avg_score, COUNT(*) as rating_count
         FROM ratings
         WHERE rated_user_type = ? AND rated_user_id = ?"
    );

    if (!$stmt) {
        return $default;
    }

    mysqli_stmt_bind_param($stmt, "si", $userType, $userId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);

    if (!$row || (int) $row['rating_count'] === 0) {
        return $default;
    }

    $count = (int) $row['rating_count'];
    $avg = round((float) $row['avg_score'], 1);
    $unit = $count === 1 ? 'review' : 'reviews';

    return [
        'avg_score' => $avg,
        'count' => $count,
        'display' => sprintf("%.1f ★ (%d %s)", $avg, $count, $unit),
        'formatted' => sprintf("%.1f ★", $avg)
    ];
}

/**
 * Fetch recent reviews received by a user or driver.
 */
function ridesync_get_user_reviews($conn, string $userType, int $userId, int $limit = 10): array {
    if ($userId <= 0 || !in_array($userType, ['user', 'driver'], true)) {
        return [];
    }

    $limit = max(1, min(50, $limit));

    $sql = "SELECT r.id, r.ride_id, r.score, r.comment, r.created_at, r.rated_by_type, r.rated_by_id,
                   rd.origin, rd.destination,
                   CASE WHEN r.rated_by_type = 'user' THEN u.name ELSE d.name END AS rater_name,
                   CASE WHEN r.rated_by_type = 'user' THEN u.profile_photo ELSE NULL END AS rater_photo
            FROM ratings r
            LEFT JOIN rides rd ON rd.id = r.ride_id
            LEFT JOIN users u ON (r.rated_by_type = 'user' AND u.id = r.rated_by_id)
            LEFT JOIN driver_accounts d ON (r.rated_by_type = 'driver' AND d.id = r.rated_by_id)
            WHERE r.rated_user_type = ? AND r.rated_user_id = ?
            ORDER BY r.created_at DESC
            LIMIT ?";

    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return [];
    }

    mysqli_stmt_bind_param($stmt, "sii", $userType, $userId, $limit);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $reviews = [];
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $reviews[] = $row;
        }
    }
    mysqli_stmt_close($stmt);

    return $reviews;
}

/**
 * Helper to determine if a ride is completed/closed and a user can submit a rating.
 */
function ridesync_can_user_rate_ride($conn, int $rideId, string $ratedByType, int $ratedById, string $targetUserType, int $targetUserId): bool {
    if ($rideId <= 0 || $ratedById <= 0 || $targetUserId <= 0) {
        return false;
    }

    if ($ratedByType === $targetUserType && $ratedById === $targetUserId) {
        return false;
    }

    if (ridesync_has_user_rated_ride($conn, $rideId, $ratedByType, $ratedById)) {
        return false;
    }

    // Check ride status
    $stmt = mysqli_prepare($conn,
        "SELECT r.status, r.user_id AS owner_id,
                ls.live_status, ls.driver_id AS assigned_driver_id
         FROM rides r
         LEFT JOIN ride_live_status ls ON ls.ride_id = r.id
         WHERE r.id = ?
         LIMIT 1"
    );

    if (!$stmt) {
        return false;
    }

    mysqli_stmt_bind_param($stmt, "i", $rideId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $ride = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);

    if (!$ride) {
        return false;
    }

    $isClosed = ($ride['status'] === 'closed') || (($ride['live_status'] ?? '') === 'completed');
    if (!$isClosed) {
        return false;
    }

    // Fetch all participants for this ride
    $ownerId = (int) $ride['owner_id'];
    $assignedDriverId = isset($ride['assigned_driver_id']) ? (int) $ride['assigned_driver_id'] : 0;
    
    $userParticipants = [$ownerId];
    $driverParticipants = $assignedDriverId > 0 ? [$assignedDriverId] : [];

    // Get accepted match users
    $stmt = mysqli_prepare($conn, "SELECT matched_user_id FROM matches WHERE ride_id = ? AND status = 'accepted'");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $rideId);
        mysqli_stmt_execute($stmt);
        $matches = mysqli_stmt_get_result($stmt);
        if ($matches) {
            while ($m = mysqli_fetch_assoc($matches)) {
                $userParticipants[] = (int) $m['matched_user_id'];
            }
        }
        mysqli_stmt_close($stmt);
    }

    $raterValid = ($ratedByType === 'user' && in_array($ratedById, $userParticipants, true)) ||
                 ($ratedByType === 'driver' && in_array($ratedById, $driverParticipants, true));

    $targetValid = ($targetUserType === 'user' && in_array($targetUserId, $userParticipants, true)) ||
                  ($targetUserType === 'driver' && in_array($targetUserId, $driverParticipants, true));

    return $raterValid && $targetValid;
}
