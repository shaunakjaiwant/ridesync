<?php
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = rawurldecode($path);
$normalizedPath = str_replace('\\', '/', $path);

if ($normalizedPath === '/') {
    header('Location: /ridesync/');
    return true;
}

if ($normalizedPath !== '/ridesync' && strpos($normalizedPath, '/ridesync/') !== 0) {
    http_response_code(404);
    echo 'Not found';
    return true;
}

$blockedPrefixes = [
    '/ridesync/.git',
    '/ridesync/ai_verification_service',
    '/ridesync/config',
    '/ridesync/database',
    '/ridesync/includes',
    '/ridesync/storage/cache',
    '/ridesync/tools',
];

foreach ($blockedPrefixes as $prefix) {
    if ($normalizedPath === $prefix || strpos($normalizedPath, $prefix . '/') === 0) {
        http_response_code(404);
        echo 'Not found';
        return true;
    }
}

$blockedExtensions = [
    'bak',
    'dist',
    'env',
    'ini',
    'lock',
    'log',
    'md',
    'sql',
    'toml',
    'yml',
    'yaml',
];

$extension = strtolower(pathinfo($normalizedPath, PATHINFO_EXTENSION));

if (strpos($normalizedPath, '/.') !== false || in_array($extension, $blockedExtensions, true)) {
    http_response_code(404);
    echo 'Not found';
    return true;
}

return false;
