<?php

return [
    'enabled' => env('FEATURE_GMAIL_STATEMENTS', false),
    'client_id' => env('GOOGLE_GMAIL_CLIENT_ID'),
    'client_secret' => env('GOOGLE_GMAIL_CLIENT_SECRET'),
    'redirect_uri' => env('GOOGLE_GMAIL_REDIRECT_URI', rtrim((string) env('APP_URL', 'http://localhost'), '/').'/integrations/gmail/callback'),
    'scope' => 'https://www.googleapis.com/auth/gmail.readonly',
    'token_store' => env('GMAIL_TOKEN_STORE', 'database'),
    'external_store_class' => env('GMAIL_TOKEN_STORE_CLASS'),
    'max_attachment_bytes' => 10485760,
    'sync_interval_minutes' => 15,
];
