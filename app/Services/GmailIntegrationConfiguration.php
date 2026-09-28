<?php

namespace App\Services;

use App\Contracts\GmailCredentialStore;

final class GmailIntegrationConfiguration
{
    public function isConfigured(): bool
    {
        if (! filled(config('gmail_statement.client_id'))
            || ! filled(config('gmail_statement.client_secret'))
            || ! filled(config('gmail_statement.redirect_uri'))) {
            return false;
        }

        if (! app()->environment('production')) {
            return true;
        }

        $storeClass = config('gmail_statement.external_store_class');

        return config('gmail_statement.token_store') === 'external'
            && is_string($storeClass)
            && class_exists($storeClass)
            && is_subclass_of($storeClass, GmailCredentialStore::class);
    }
}
