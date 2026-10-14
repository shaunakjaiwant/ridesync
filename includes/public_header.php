<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/asset_helper.php';
require_once __DIR__ . '/view_helper.php';
$styleVersion = ridesync_stylesheet_version();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RideSync</title>
    <link rel="icon" type="image/png" href="/ridesync/logo-mark.png">
    <link rel="stylesheet" href="/ridesync/css/theme.css?v=<?php echo $styleVersion; ?>">
    <link rel="stylesheet" href="/ridesync/css/style.css?v=<?php echo $styleVersion; ?>">
</head>
<body class="public-app">

<nav class="public-navbar">
    <a href="/ridesync/index.php" class="public-logo nav-logo" aria-label="RideSync home">
        <span class="nav-logo-badge">
            <img src="/ridesync/logo-mark.png" alt="RideSync" class="logo-img" />
        </span>
        <span class="brand-copy">
            <strong>RideSync</strong>
            <span class="brand-role-chip public-role-chip">Campus Mobility</span>
        </span>
    </a>
    <div class="public-nav-actions">
        <a href="/ridesync/pages/login.php" class="btn-public-signin" aria-label="Sign in to RideSync">Sign in</a>
    </div>
</nav>

<main class="main-content">

