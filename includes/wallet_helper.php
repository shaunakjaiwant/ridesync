<?php
require_once __DIR__ . '/matching_helper.php';

function ridesync_wallet_schema_ready($conn) {
    return ridesync_table_exists($conn, 'wallet_accounts')
        && ridesync_table_exists($conn, 'wallet_transactions');
}

function ridesync_wallet_account_id($conn, $userId) {
    if (!ridesync_wallet_schema_ready($conn)) {
        return null;
    }

    $userId = (int) $userId;
    if ($userId <= 0) {
        return null;
    }

    $stmt = mysqli_prepare($conn,
        "INSERT INTO wallet_accounts (user_id)
         VALUES (?)
         ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP"
    );
    mysqli_stmt_bind_param($stmt, "i", $userId);
    mysqli_stmt_execute($stmt);

    $stmt = mysqli_prepare($conn, "SELECT id FROM wallet_accounts WHERE user_id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "i", $userId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    return $row ? (int) $row['id'] : null;
}

function ridesync_wallet_record($conn, $userId, $transactionType, $amount, $description, $referenceType, $referenceId, $rideId = null, $driverId = null) {
    if (!ridesync_wallet_schema_ready($conn)) {
        return false;
    }

    $walletId = ridesync_wallet_account_id($conn, $userId);
    $amount = round(max(0, (float) $amount), 2);
    $allowedTypes = ['credit', 'debit', 'hold', 'release', 'fare_due', 'cash_paid', 'adjustment'];

    if (!$walletId || $amount <= 0 || !in_array($transactionType, $allowedTypes, true)) {
        return false;
    }

    $referenceType = substr(trim((string) $referenceType), 0, 40);
    $referenceId = (int) $referenceId;
    $description = substr(trim((string) $description), 0, 255);
    $rideId = $rideId !== null ? (int) $rideId : null;
    $driverId = $driverId !== null ? (int) $driverId : null;

    $stmt = mysqli_prepare($conn,
        "INSERT INTO wallet_transactions
            (wallet_id, user_id, ride_id, driver_id, transaction_type, amount, description, reference_type, reference_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE description = VALUES(description)"
    );
    mysqli_stmt_bind_param($stmt, "iiiisdssi", $walletId, $userId, $rideId, $driverId, $transactionType, $amount, $description, $referenceType, $referenceId);
    return mysqli_stmt_execute($stmt);
}

function ridesync_wallet_record_fare_due($conn, $userId, $rideId, $driverId, $amount, $description, $referenceType, $referenceId) {
    return ridesync_wallet_record($conn, $userId, 'fare_due', $amount, $description, $referenceType, $referenceId, $rideId, $driverId);
}

function ridesync_wallet_summary($conn, $userId) {
    $summary = [
        'balance' => 0.0,
        'fare_due' => 0.0,
        'cash_paid' => 0.0,
        'pending_due' => 0.0,
        'transactions' => 0,
    ];

    if (!ridesync_wallet_schema_ready($conn)) {
        return $summary;
    }

    ridesync_wallet_account_id($conn, $userId);

    $stmt = mysqli_prepare($conn,
        "SELECT balance FROM wallet_accounts WHERE user_id = ? LIMIT 1"
    );
    mysqli_stmt_bind_param($stmt, "i", $userId);
    mysqli_stmt_execute($stmt);
    $account = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if ($account) {
        $summary['balance'] = (float) $account['balance'];
    }

    $stmt = mysqli_prepare($conn,
        "SELECT
            COALESCE(SUM(CASE WHEN transaction_type = 'fare_due' THEN amount ELSE 0 END), 0) AS fare_due,
            COALESCE(SUM(CASE WHEN transaction_type = 'cash_paid' THEN amount ELSE 0 END), 0) AS cash_paid,
            COUNT(*) AS transactions
         FROM wallet_transactions
         WHERE user_id = ?"
    );
    mysqli_stmt_bind_param($stmt, "i", $userId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if ($row) {
        $summary['fare_due'] = (float) $row['fare_due'];
        $summary['cash_paid'] = (float) $row['cash_paid'];
        $summary['transactions'] = (int) $row['transactions'];
        $summary['pending_due'] = max(0, $summary['fare_due'] - $summary['cash_paid']);
    }

    return $summary;
}
?>
