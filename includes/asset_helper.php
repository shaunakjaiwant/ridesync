<?php
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
