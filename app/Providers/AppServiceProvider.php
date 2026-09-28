<?php

namespace App\Providers;

use App\Contracts\GmailCredentialStore;
use App\Contracts\ProviderSecretStore;
use App\Models\ImportBatch;
use App\Models\Transaction;
use App\Policies\ImportBatchPolicy;
use App\Policies\TransactionPolicy;
use App\Services\EncryptedDatabaseGmailCredentialStore;
use App\Services\EncryptedDatabaseProviderSecretStore;
use App\Services\UnavailableProviderSecretStore;
use Illuminate\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $gmailStore = config('gmail_statement.external_store_class');
        if (config('gmail_statement.token_store') === 'external'
            && is_string($gmailStore)
            && class_exists($gmailStore)
            && is_subclass_of($gmailStore, GmailCredentialStore::class)) {
            $this->app->bind(GmailCredentialStore::class, $gmailStore);
        } else {
            $this->app->bind(GmailCredentialStore::class, EncryptedDatabaseGmailCredentialStore::class);
        }

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
        RateLimiter::for('business-insight', fn (Request $request) => Limit::perMinute(1)->by('business-insight:'.$request->user()->businessAgentProfile()?->id));
        Gate::policy(Transaction::class, TransactionPolicy::class);
        Gate::policy(ImportBatch::class, ImportBatchPolicy::class);

        VerifyEmail::toMailUsing(function (object $notifiable, string $url): MailMessage {
            $expirationMinutes = (int) config('auth.verification.expire', 60);

            return (new MailMessage)
                ->subject('Verify your POSPilot email')
                ->view(['emails.auth.transactional', 'emails.auth.transactional-text'], [
                    'preheader' => 'Confirm your email to secure your POSPilot workspace.',
                    'recipientName' => $notifiable->name,
                    'headline' => 'Confirm your email address',
                    'intro' => 'One quick step will secure your POSPilot workspace and help keep your business records private.',
                    'actionUrl' => $url,
                    'actionLabel' => 'Verify email address',
                    'closingNote' => "This link expires in {$expirationMinutes} minutes. If you did not create a POSPilot account, you can ignore this email.",
                ]);
        });

        ResetPasswordNotification::toMailUsing(function (object $notifiable, string $token): MailMessage {
            $url = url(route('password.reset', [
                'token' => $token,
                'email' => $notifiable->getEmailForPasswordReset(),
            ], false));
            $expirationMinutes = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60);

            return (new MailMessage)
                ->subject('Reset your POSPilot password')
                ->view(['emails.auth.transactional', 'emails.auth.transactional-text'], [
                    'preheader' => 'Choose a new password for your POSPilot account.',
                    'recipientName' => $notifiable->name,
                    'headline' => 'Reset your password',
                    'intro' => 'We received a request to change the password for your POSPilot account.',
                    'actionUrl' => $url,
                    'actionLabel' => 'Choose a new password',
                    'closingNote' => "This link expires in {$expirationMinutes} minutes. If you did not request a password reset, you can ignore this email.",
                ]);
        });
    }
}
