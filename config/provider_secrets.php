<?php

return [
    // Production deployments must bind ProviderSecretStore to a KMS/secret-manager adapter.
    'driver' => env('PROVIDER_SECRET_STORE', 'database'),
    'external_store_class' => env('PROVIDER_SECRET_STORE_CLASS'),
];
