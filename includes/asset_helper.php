<?php
function ridesync_asset_version(array $relativePaths): int
{
    static $versions = [];

    $relativePaths = array_values(array_unique(array_filter($relativePaths)));
    sort($relativePaths);
    $cacheKey = implode('|', $relativePaths);
    if (isset($versions[$cacheKey])) {
        return $versions[$cacheKey];
    }

    $version = 1;
    foreach ($relativePaths as $relativePath) {
        $path = RIDESYNC_ROOT . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim((string) $relativePath, '/\\'));
        if (is_file($path)) {
            $version = max($version, (int) filemtime($path));
        }
    }

    $versions[$cacheKey] = $version;
    return $version;
}

function ridesync_stylesheet_version(): int
{
    return ridesync_asset_version([
        'css/style.css',
        'css/theme.css',
    ]);
}

function ridesync_script_version(string $relativePath): int
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
