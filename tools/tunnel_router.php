<?php
declare(strict_types=1);

function ridesync_tunnel_security_headers(): void
{
    if (headers_sent()) {
        return;
    }

    header_remove('X-Powered-By');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-site');
    header("Content-Security-Policy: default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none'; form-action 'self'; script-src 'self'; style-src 'self'; style-src-attr 'unsafe-inline'; img-src 'self' data: blob: https://*.tile.openstreetmap.org; font-src 'self' data:; connect-src 'self' https://nominatim.openstreetmap.org https://router.project-osrm.org; media-src 'self'; worker-src 'self' blob:");
}

function ridesync_tunnel_not_found(): bool
{
    ridesync_tunnel_security_headers();
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    return true;
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = rawurldecode($path);
$normalizedPath = str_replace('\\', '/', $path);

if ($normalizedPath === '/') {
    header('Location: /ridesync/');
    return true;
}

if ($normalizedPath !== '/ridesync' && strpos($normalizedPath, '/ridesync/') !== 0) {
    return ridesync_tunnel_not_found();
}

$blockedPrefixes = [
    '/ridesync/.git',
    '/ridesync/ai_verification_service',
    '/ridesync/config',
    '/ridesync/database',
    '/ridesync/docs',
    '/ridesync/includes',
    '/ridesync/ops',
    '/ridesync/storage/cache',
    '/ridesync/tests',
    '/ridesync/tools',
    '/ridesync/websocket_gateway',
];

foreach ($blockedPrefixes as $prefix) {
    if ($normalizedPath === $prefix || strpos($normalizedPath, $prefix . '/') === 0) {
        return ridesync_tunnel_not_found();
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
    return ridesync_tunnel_not_found();
}

$relativePath = substr($normalizedPath, strlen('/ridesync/'));
if ($relativePath !== false && $relativePath !== '') {
    $filePath = realpath(__DIR__ . '/../' . str_replace('/', DIRECTORY_SEPARATOR, $relativePath));
    $rootPath = realpath(__DIR__ . '/..');
    if ($filePath !== false && $rootPath !== false && str_starts_with($filePath, $rootPath . DIRECTORY_SEPARATOR) && is_file($filePath)) {
        if (strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) === 'php') {
            return false;
        }

        $mimeTypes = [
            'css' => 'text/css; charset=utf-8',
            'gif' => 'image/gif',
            'ico' => 'image/x-icon',
            'jpeg' => 'image/jpeg',
            'jpg' => 'image/jpeg',
            'js' => 'application/javascript; charset=utf-8',
            'png' => 'image/png',
            'svg' => 'image/svg+xml',
            'webp' => 'image/webp',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
        ];
        $assetExtension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        ridesync_tunnel_security_headers();
        header('Content-Type: ' . ($mimeTypes[$assetExtension] ?? 'application/octet-stream'));
        header('Cache-Control: public, max-age=604800, immutable');
        readfile($filePath);
        return true;
    }
}

return false;
