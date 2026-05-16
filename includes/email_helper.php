<?php
function ridesync_is_valid_email($email) {
    $email = trim((string) $email);

    if ($email === '' || strlen($email) > 190) {
        return false;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $parts = explode('@', $email);
    if (count($parts) !== 2) {
        return false;
    }

    $domain = $parts[1];
    if (strpos($domain, '.') === false) {
        return false;
    }

    return true;
}
?>
