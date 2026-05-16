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

?>
