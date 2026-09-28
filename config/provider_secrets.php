<?php

return [
    'moniepoint_direct_enabled' => env('FEATURE_MONIEPOINT_DIRECT', false),
    'opay_direct_enabled' => env('FEATURE_OPAY_DIRECT', false),
    'palmpay_direct_enabled' => env('FEATURE_PALMPAY_DIRECT', false),
    // The current Moniepoint reference does not confirm the callback signature contract used here.
    'moniepoint_webhooks_verified' => false,
    // Production deployments must bind ProviderSecretStore to a KMS/secret-manager adapter.
    'driver' => env('PROVIDER_SECRET_STORE', 'database'),
    'external_store_class' => env('PROVIDER_SECRET_STORE_CLASS'),
];
