<?php

return [
    'verification_service_url' => (string) ridesync_env('RIDESYNC_VERIFICATION_SERVICE_URL', ''),
    'verification_service_token' => (string) ridesync_env('RIDESYNC_VERIFICATION_SERVICE_TOKEN', ''),
    'async' => ridesync_env_bool('RIDESYNC_VERIFICATION_ASYNC', false),
    'inline_fallback' => ridesync_env_bool('RIDESYNC_VERIFICATION_INLINE_FALLBACK', true),
    'max_documents' => ridesync_env_int('RIDESYNC_VERIFICATION_MAX_DOCUMENTS', 12),
    'ocr_timeout_seconds' => ridesync_env_int('RIDESYNC_VERIFICATION_OCR_TIMEOUT_SECONDS', 5),
];
