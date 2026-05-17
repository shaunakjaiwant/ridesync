<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/view_helper.php';
$cssFiles = glob(__DIR__ . '/../css/*.css') ?: [__DIR__ . '/../css/style.css'];
$styleVersion = max(array_map('filemtime', $cssFiles));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RideSync</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" type="image/png" href="/ridesync/logo-mark.png">
    <link rel="stylesheet" href="/ridesync/css/style.css?v=<?php echo $styleVersion; ?>">
</head>
<body class="public-app">

<nav class="public-navbar">
    <a href="/ridesync/index.php" class="public-logo nav-logo" aria-label="RideSync home">
        <img src="/ridesync/logo-mark.png" alt="RideSync" class="logo-img" />
        <span class="brand-copy">
            <strong>RideSync</strong>
            <span>Campus mobility</span>
        </span>
    </a>
</nav>

<main class="main-content">
