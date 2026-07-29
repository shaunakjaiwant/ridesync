<?php
return [
    'version' => '2026_05_15_000003_realtime_and_background_jobs',
    'description' => 'Realtime event streaming and background job queue tables',
    'up' => function ($conn) {
        schema_create_background_jobs($conn);
        schema_create_realtime_events($conn);
        return true;
    }
];
