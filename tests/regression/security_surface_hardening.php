<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../includes/asset_helper.php';

$root = realpath(__DIR__ . '/../..');
$failures = [];

function sh_note($status, $message) {
    echo '[' . $status . '] ' . $message . PHP_EOL;
}

function sh_expect($condition, $message, array &$failures) {
    if (!$condition) {
        $failures[] = $message;
    }
}

function sh_read($root, $relativePath) {
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    return is_file($path) ? file_get_contents($path) : '';
}

$htaccess = sh_read($root, '.htaccess');
$apacheConf = sh_read($root, 'infrastructure/apache/ridesync.conf');
$assetHelper = sh_read($root, 'includes/asset_helper.php');
$bootstrap = sh_read($root, 'config/bootstrap.php');
$tunnelRouter = sh_read($root, 'tools/tunnel_router.php');
$riderLogin = sh_read($root, 'pages/login.php');

sh_expect(str_contains($htaccess, 'RewriteRule (^|/)\. - [F,L]'), 'Root .htaccess does not block dot-directory paths such as .git/config', $failures);
sh_expect(str_contains($apacheConf, '/(\.|ai|apps|backend|config|database|docs|includes|infrastructure|realtime|tests|tools)'), 'Docker Apache config does not block internal architecture directories', $failures);
sh_expect(str_contains($tunnelRouter, "'/ridesync/.git'"), 'Tunnel router does not block .git paths for local security scans', $failures);
sh_expect(str_contains($tunnelRouter, 'ridesync_tunnel_security_headers'), 'Tunnel router does not attach security headers to blocked/static responses', $failures);
sh_expect(str_contains($tunnelRouter, "Content-Security-Policy: default-src 'self'"), 'Tunnel router does not emit CSP for non-PHP responses', $failures);
sh_expect(str_contains($riderLogin, '$requestedRole') && str_contains($riderLogin, "pages/login.php?role=rider"), 'Rider login does not canonicalize unexpected role query parameters', $failures);

$styleVersion = ridesync_stylesheet_version();
$scriptVersion = ridesync_script_version('js/script.js');
foreach (['stylesheet' => $styleVersion, 'script' => $scriptVersion] as $label => $version) {
    sh_expect((bool) preg_match('/^[a-f0-9]{12}$/', $version), "{$label} asset version is not a 12-character content hash", $failures);
    sh_expect(!preg_match('/^\d{10}$/', $version), "{$label} asset version still looks like a Unix timestamp", $failures);
}
sh_expect(!str_contains($assetHelper, 'filemtime('), 'Asset helper still exposes file modification timestamps in cache-busting parameters', $failures);

foreach ([
    'includes/header.php',
    'includes/public_header.php',
    'includes/admin_header.php',
    'includes/driver_header.php',
    'includes/footer.php',
    'includes/admin_footer.php',
    'includes/driver_footer.php',
    'config/bootstrap.php',
] as $relativePath) {
    $contents = sh_read($root, $relativePath);
    foreach (['fonts.googleapis.com', 'fonts.gstatic.com', 'unpkg.com'] as $externalHost) {
        sh_expect(!str_contains($contents, $externalHost), "{$relativePath} still references external host {$externalHost}", $failures);
    }
}

foreach ([
    'assets/vendor/leaflet/1.9.4/leaflet.css',
    'assets/vendor/leaflet/1.9.4/leaflet.js',
    'assets/vendor/leaflet/1.9.4/images/marker-icon.png',
    'assets/vendor/leaflet/1.9.4/images/marker-icon-2x.png',
    'assets/vendor/leaflet/1.9.4/images/marker-shadow.png',
] as $relativePath) {
    sh_expect(is_file($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath)), "Missing local Leaflet asset {$relativePath}", $failures);
}

sh_expect(!str_contains($bootstrap, "'unsafe-inline',"), 'CSP source arrays still include unsafe-inline as a broad style source', $failures);
sh_expect(str_contains($bootstrap, "style-src-attr 'unsafe-inline'"), 'CSP does not confine inline style compatibility to style-src-attr', $failures);
sh_expect(str_contains($bootstrap, "style-src ' . implode"), 'CSP style-src is no longer generated from the allowlisted style source array', $failures);

if (!empty($failures)) {
    echo PHP_EOL . 'Security surface hardening regression failures:' . PHP_EOL;
    foreach ($failures as $failure) {
        echo '- ' . $failure . PHP_EOL;
    }
    exit(1);
}

sh_note('OK', 'web-root dot-directory denial is enforced in Apache config');
sh_note('OK', 'tunnel router mirrors security header and deny rules');
sh_note('OK', 'rider login rejects suspicious role query values');
sh_note('OK', 'asset cache busters use content hashes');
sh_note('OK', 'public templates no longer depend on external font/CDN assets');
sh_note('OK', 'CSP avoids broad style-src unsafe-inline');
echo PHP_EOL . 'RideSync security surface hardening regressions passed.' . PHP_EOL;
?>
