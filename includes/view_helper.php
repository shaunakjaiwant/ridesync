<?php

function ridesync_e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ridesync_flash($key, $class) {
    if (!isset($_SESSION[$key])) {
        return;
    }

    $message = $_SESSION[$key];
    unset($_SESSION[$key]);

    echo '<div class="alert ' . ridesync_e($class) . '">' . ridesync_e($message) . '</div>';
}

function ridesync_unread_notification_count($conn, string $actorColumn, int $actorId): int {
    if (!$conn instanceof mysqli || !in_array($actorColumn, ['user_id', 'driver_id'], true) || $actorId <= 0) {
        return 0;
    }

    $cacheKey = $actorColumn . ':' . $actorId;
    $cache = $_SESSION['_unread_notification_cache'][$cacheKey] ?? null;
    if (is_array($cache) && (int) ($cache['expires_at'] ?? 0) > time()) {
        return max(0, (int) ($cache['count'] ?? 0));
    }

    try {
        $stmt = mysqli_prepare(
            $conn,
            "SELECT COUNT(*) AS total
             FROM notifications
             WHERE {$actorColumn} = ? AND is_read = 0"
        );
        if (!$stmt) {
            return 0;
        }

        mysqli_stmt_bind_param($stmt, 'i', $actorId);
        mysqli_stmt_execute($stmt);
        $count = (int) (mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['total'] ?? 0);
    } catch (Throwable $exception) {
        $count = 0;
    }

    $_SESSION['_unread_notification_cache'][$cacheKey] = [
        'count' => $count,
        'expires_at' => time() + 10,
    ];

    return $count;
}

function ridesync_forget_notification_count_cache(string $actorColumn = '', int $actorId = 0): void {
    if (!isset($_SESSION['_unread_notification_cache'])) {
        return;
    }

    if ($actorColumn === '' || $actorId <= 0) {
        unset($_SESSION['_unread_notification_cache']);
        return;
    }

    unset($_SESSION['_unread_notification_cache'][$actorColumn . ':' . $actorId]);
}

/**
 * Intelligently shortens long geocoded addresses or street descriptions
 * for compact UI cards, chips, and route badges.
 *
 * Examples:
 * "SH37, Kanyadi, Beltangadi taluk, Dakshina Kannada, Karnataka, 574240, India" -> "SH37, Kanyadi"
 * "Vajpayee circle, Sirsi, Shirasi Taluk, Uttara Kannada, Karnataka, 581401, India" -> "Vajpayee circle, Sirsi"
 * "SDMIT Campus" -> "SDMIT Campus"
 */
function ridesync_short_address(?string $address, int $maxLength = 32): string
{
    if ($address === null) {
        return '';
    }
    $address = trim($address);
    if ($address === '') {
        return '';
    }

    // Split by comma
    $parts = array_map('trim', explode(',', $address));
    $filtered = [];

    // Ignored address suffixes and noise patterns
    $ignorePatterns = [
        '/^\d{4,10}(-\d{4})?$/', // Postal codes
        '/^(india|usa|united states|uk|united kingdom|canada|australia)$/i', // Countries
        '/^(karnataka|maharashtra|kerala|tamil nadu|goa|delhi|andhra pradesh|telangana|gujarat|rajasthan)$/i', // Common Indian states
        '/\b(taluk|taluka|district|dist\.|state|province|pin\s*code|postal\s*code)\b/i', // Administrative noise
    ];

    foreach ($parts as $part) {
        if ($part === '') {
            continue;
        }
        $skip = false;
        foreach ($ignorePatterns as $pattern) {
            if (preg_match($pattern, $part)) {
                $skip = true;
                break;
            }
        }
        if (!$skip) {
            $filtered[] = $part;
        }
    }

    if (empty($filtered)) {
        $filtered = [$parts[0]];
    }

    // Try primary spot + locality/city if available and fits
    if (count($filtered) >= 2) {
        $primary = $filtered[0];
        $secondary = $filtered[1];

        // If secondary contains highway separators like "Sirsi - Kumta Highway", simplify to first token if needed
        if (str_contains($secondary, ' - ')) {
            $subParts = explode(' - ', $secondary);
            $secondary = trim($subParts[0]);
        }

        $candidate = $primary . ', ' . $secondary;
        if (mb_strlen($candidate) <= $maxLength) {
            return $candidate;
        }

        // If candidate is too long, does primary alone fit?
        if (mb_strlen($primary) <= $maxLength) {
            return $primary;
        }
    }

    $first = $filtered[0];
    if (mb_strlen($first) > $maxLength) {
        return mb_substr($first, 0, max(1, $maxLength - 1)) . '…';
    }

    return $first;
}

/**
 * Renders a clean, accessible HTML span for a shortened address
 * with a full address tooltip on hover.
 */
function ridesync_render_short_address(?string $address, int $maxLength = 32, string $extraClass = ''): string
{
    $full = trim((string) $address);
    $short = ridesync_short_address($full, $maxLength);
    $classAttr = 'class="short-address' . ($extraClass !== '' ? ' ' . htmlspecialchars($extraClass, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '') . '"';
    $titleAttr = 'title="' . htmlspecialchars($full, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
    return '<span ' . $classAttr . ' ' . $titleAttr . '>' . htmlspecialchars($short, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
}

?>
