<?php
define('RIDESYNC_ALLOW_DB_FAILURE', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/matching_helper.php';
require_once __DIR__ . '/../includes/http_helper.php';

header('Content-Type: application/json');

$email = $_GET['email'] ?? 'rider@ridesync.com';
$step = 0;
$log = [];

try {
    $appliedAlters = [];
    $matchCols = [
        'pickup_lat' => "ALTER TABLE matches ADD COLUMN pickup_lat DECIMAL(10,8) NULL",
        'pickup_lng' => "ALTER TABLE matches ADD COLUMN pickup_lng DECIMAL(11,8) NULL",
        'dropoff_lat' => "ALTER TABLE matches ADD COLUMN dropoff_lat DECIMAL(10,8) NULL",
        'dropoff_lng' => "ALTER TABLE matches ADD COLUMN dropoff_lng DECIMAL(11,8) NULL",
        'detour_distance_km' => "ALTER TABLE matches ADD COLUMN detour_distance_km DECIMAL(8,2) NULL",
        'detour_time_minutes' => "ALTER TABLE matches ADD COLUMN detour_time_minutes INT NULL",
        'source' => "ALTER TABLE matches ADD COLUMN source ENUM('search', 'route_watch') NOT NULL DEFAULT 'search'",
    ];

    foreach ($matchCols as $col => $sql) {
        $chk = mysqli_query($conn, "SHOW COLUMNS FROM matches LIKE '{$col}'");
        if ($chk && mysqli_num_rows($chk) === 0) {
            if (mysqli_query($conn, $sql)) {
                $appliedAlters[] = "Added $col to matches";
            } else {
                $appliedAlters[] = "Failed $col: " . mysqli_error($conn);
            }
        }
    }

    $cols = [];
    $res = mysqli_query($conn, "DESCRIBE matches");
    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) {
            $cols[] = $r['Field'];
        }
    }
    echo json_encode(['ok' => true, 'applied' => $appliedAlters, 'matches_columns' => $cols]);
    exit;
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    exit;
}
    $userId = (int) $user['id'];
    $log[] = "User found: id = $userId";

    $step = 2;
    $log[] = "Step 2: Fetching rides with live_status";
    $stmt = $conn->prepare("
        SELECT r.*,
               COALESCE(ls.live_status, 'searching') AS live_status,
               ls.driver_id,
               (SELECT COUNT(*) FROM matches WHERE ride_id = r.id) AS match_count,
               (SELECT COUNT(*) FROM matches WHERE ride_id = r.id AND status = 'accepted') AS accepted_count
        FROM rides r
        LEFT JOIN ride_live_status ls ON ls.ride_id = r.id
        WHERE r.user_id = ?
        ORDER BY r.travel_date DESC, r.travel_time DESC
    ");
    if (!$stmt) {
        throw new RuntimeException("Prepare rides failed: " . $conn->error);
    }
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $ridesResult = $stmt->get_result();
    $rides = [];
    while ($row = $ridesResult->fetch_assoc()) {
        $rides[] = $row;
    }
    $stmt->close();
    $log[] = "Fetched " . count($rides) . " rides for user $userId";

    $step = 3;
    $rideIds = array_map(static fn($ride) => (int) $ride['id'], $rides);
    $log[] = "Ride IDs: " . json_encode($rideIds);

    if (count($rideIds) > 0) {
        $step = 4;
        $placeholders = implode(',', array_fill(0, count($rideIds), '?'));
        $types = str_repeat('i', count($rideIds));
        $m_stmt = $conn->prepare("
            SELECT m.ride_id, m.id AS match_id, m.status AS match_status, m.match_score, m.route_overlap_percent,
                   m.pickup_lat, m.pickup_lng, m.dropoff_lat, m.dropoff_lng, m.detour_distance_km, m.detour_time_minutes, m.source,
                   m.created_at AS requested_at,
                   u.name, u.email, u.college, u.gender
            FROM matches m
            JOIN users u ON m.matched_user_id = u.id
            WHERE m.ride_id IN ({$placeholders})
            ORDER BY m.ride_id ASC, m.created_at DESC
        ");
        if (!$m_stmt) {
            throw new RuntimeException("Prepare matches failed: " . $conn->error);
        }
        $bindValues = $rideIds;
        $bindParams = [$types];
        foreach ($bindValues as $index => $value) {
            $bindParams[] = &$bindValues[$index];
        }
        call_user_func_array([$m_stmt, 'bind_param'], $bindParams);
        $m_stmt->execute();
        $matchRows = $m_stmt->get_result();
        $countMatches = 0;
        while ($match = $matchRows->fetch_assoc()) {
            $countMatches++;
        }
        $m_stmt->close();
        $log[] = "Fetched $countMatches matches for rides";
    }

    $step = 5;
    $log[] = "Step 5: Testing match request counters";
    $requestedCount = 0;
    $cntStmt = $conn->prepare("SELECT COUNT(*) AS total FROM matches WHERE matched_user_id = ?");
    if ($cntStmt) {
        $cntStmt->bind_param("i", $userId);
        $cntStmt->execute();
        $requestedCount += (int) ($cntStmt->get_result()->fetch_assoc()['total'] ?? 0);
        $cntStmt->close();
    }
    $log[] = "Total requested count: $requestedCount";

    echo json_encode([
        'ok' => true,
        'userId' => $userId,
        'rideCount' => count($rides),
        'log' => $log,
    ]);
} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'failedAtStep' => $step,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'log' => $log,
    ]);
}
