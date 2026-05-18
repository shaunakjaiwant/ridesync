<?php
function ridesync_asset_version(array $relativePaths): string
{
    static $versions = [];

    $relativePaths = array_values(array_unique(array_filter($relativePaths)));
    sort($relativePaths);
    $cacheKey = implode('|', $relativePaths);
    if (isset($versions[$cacheKey])) {
        return $versions[$cacheKey];
    }

    $hashContext = hash_init('sha256');
    $hasAsset = false;
    foreach ($relativePaths as $relativePath) {
        $path = RIDESYNC_ROOT . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim((string) $relativePath, '/\\'));
        if (is_file($path)) {
            $hasAsset = true;
            hash_update($hashContext, (string) $relativePath);
            hash_update_file($hashContext, $path);
        }
    }

    $version = $hasAsset ? substr(hash_final($hashContext), 0, 12) : '1';
    $versions[$cacheKey] = $version;
    return $version;
}

function ridesync_stylesheet_version(): string
{
    return ridesync_asset_version([
        'css/style.css',
        'css/theme.css',
    ]);
}

function ridesync_script_version(string $relativePath): string
{
    return ridesync_asset_version([$relativePath]);
}

function ridesync_enable_map_assets(bool $needsMapPicker = true): void
{
    $GLOBALS['ridesync_needs_maps'] = true;
    if ($needsMapPicker) {
        $GLOBALS['ridesync_needs_map_picker'] = true;
    }
}

function ridesync_page_needs_map_assets(): bool
{
    return !empty($GLOBALS['ridesync_needs_maps']);
}

function ridesync_page_needs_map_picker(): bool
{
    return !empty($GLOBALS['ridesync_needs_map_picker']);
}
