<?php

function ridesync_safe_redirect_target($target, $default, $allowedPrefix = '/ridesync/') {
    if (!is_string($target)) {
        return $default;
    }

    $target = trim($target);
    if ($target === '' || preg_match('/[\r\n]/', $target)) {
        return $default;
    }

    if (preg_match('/^[a-z][a-z0-9+\-.]*:/i', $target) || strpos($target, '//') === 0) {
        return $default;
    }

    if (strpos($target, $allowedPrefix) !== 0) {
        return $default;
    }

    return $target;
}

function ridesync_redirect_to($target, $default, $allowedPrefix = '/ridesync/') {
    header('Location: ' . ridesync_safe_redirect_target($target, $default, $allowedPrefix));
    exit();
}

function ridesync_redirect_back($default, $field = 'return_to', $source = null) {
    $source = $source ?? $_POST;
    $target = is_array($source) ? ($source[$field] ?? null) : null;
    ridesync_redirect_to($target, $default);
}

?>
