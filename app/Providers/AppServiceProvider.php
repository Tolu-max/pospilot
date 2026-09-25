<?php

namespace App\Providers;

use App\Contracts\ProviderSecretStore;
use App\Models\ImportBatch;
use App\Models\Transaction;
use App\Policies\ImportBatchPolicy;
use App\Policies\TransactionPolicy;
use App\Services\EncryptedDatabaseProviderSecretStore;
use App\Services\UnavailableProviderSecretStore;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $driver = config('provider_secrets.driver', 'database');
        $externalStore = config('provider_secrets.external_store_class');

        if ($driver === 'external'
            && is_string($externalStore)
            && class_exists($externalStore)
            && is_subclass_of($externalStore, ProviderSecretStore::class)) {
            $this->app->bind(ProviderSecretStore::class, $externalStore);

            return;
        }

        if ($driver === 'external') {
            $this->app->bind(ProviderSecretStore::class, UnavailableProviderSecretStore::class);

            return;
        }

        $this->app->bind(ProviderSecretStore::class, EncryptedDatabaseProviderSecretStore::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);
        Gate::policy(Transaction::class, TransactionPolicy::class);
        Gate::policy(ImportBatch::class, ImportBatchPolicy::class);
    }
}
