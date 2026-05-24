<?php

return [
    'provider' => (string) ridesync_env('RIDESYNC_KYC_PROVIDER', 'mock_compliance_provider'),
    'provider_url' => (string) ridesync_env('RIDESYNC_KYC_PROVIDER_URL', ''),
    'provider_token' => (string) ridesync_env('RIDESYNC_KYC_PROVIDER_TOKEN', ''),
    'timeout_seconds' => ridesync_env_int('RIDESYNC_KYC_PROVIDER_TIMEOUT_SECONDS', 6),
    'allowed_providers' => ['hyperverge', 'signzy', 'decentro', 'surepass', 'mock_compliance_provider'],
];
