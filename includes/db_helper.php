<?php
require_once __DIR__ . '/../config/bootstrap.php';

function ridesync_db_prepared_statement($conn, $sql, $types = '', array $params = []) {
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        ridesync_log('error', 'Failed to prepare SQL statement', ['error' => mysqli_error($conn)]);
        return null;
    }

    if ($types !== '' && count($params) > 0) {
        $refs = [];
        foreach ($params as $key => $value) {
            $refs[$key] = &$params[$key];
        }
        mysqli_stmt_bind_param($stmt, $types, ...$refs);
    }

    return $stmt;
}

function ridesync_db_fetch_all($conn, $sql, $types = '', array $params = []) {
    $stmt = ridesync_db_prepared_statement($conn, $sql, $types, $params);
    if (!$stmt) {
        return [];
    }

    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return [];
    }

    $rows = [];
    $result = mysqli_stmt_get_result($stmt);
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $rows[] = $row;
    }
    mysqli_stmt_close($stmt);

    return $rows;
}

function ridesync_db_fetch_one($conn, $sql, $types = '', array $params = []) {
    $rows = ridesync_db_fetch_all($conn, $sql, $types, $params);
    return $rows[0] ?? null;
}

function ridesync_db_transaction($conn, callable $callback) {
    mysqli_begin_transaction($conn);

    try {
        $result = $callback($conn);
        mysqli_commit($conn);
        return $result;
    } catch (Throwable $exception) {
        mysqli_rollback($conn);
        ridesync_log_exception($exception);
        throw $exception;
    }
}

function ridesync_is_user_suspended($conn, int $userId): bool {
    if ($userId <= 0) {
        return false;
    }
    $row = ridesync_db_fetch_one($conn, "SELECT status FROM users WHERE id = ? LIMIT 1", "i", [$userId]);
    return isset($row['status']) && $row['status'] === 'suspended';
}

?>
