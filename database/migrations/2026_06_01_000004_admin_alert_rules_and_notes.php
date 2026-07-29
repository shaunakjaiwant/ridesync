<?php
return [
    'version' => '2026_06_01_000004_admin_alert_rules_and_notes',
    'description' => 'Admin alert rules, entity notes, feature flags, and repair kit runs',
    'up' => function ($conn) {
        schema_create_admin_alert_rules($conn);
        schema_create_admin_notes($conn);
        schema_create_feature_flags($conn);
        schema_create_repair_kit_runs($conn);
        return true;
    }
];
