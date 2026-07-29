<?php
return [
    'version' => '2026_05_01_000002_driver_verification_and_wallets',
    'description' => 'Driver verification intelligence and wallet ledger tables',
    'up' => function ($conn) {
        schema_create_wallet_tables($conn);
        schema_update_driver_document_types($conn);
        schema_create_driver_verification_tables($conn);
        return true;
    }
];
