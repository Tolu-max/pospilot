<?php

namespace Tests\Feature;

use App\Enums\TransactionSource;
use App\Jobs\SyncConnectedGmailStatements;
use App\Models\AgentProfile;
use App\Models\GmailConnection;
use App\Models\GmailStatementMessage;
use App\Models\Provider;
use App\Models\Terminal;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\SecurityAlertNotification;
use App\Services\DailyClosingService;
use App\Services\EarningsService;
use App\Services\Gmail\GmailStatementConnector;
use App\Services\GmailStatementImportService;
use App\Services\ReconciliationService;
use App\Services\XlsxStatementReader;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GmailStatementsTest extends TestCase
{
    use RefreshDatabase;

    public function test_gmail_oauth_uses_a_separate_restricted_readonly_scope_and_exact_callback(): void
    {
        config()->set('gmail_statement.enabled', true);
        config()->set('gmail_statement.client_id', 'gmail-client-id');
        config()->set('gmail_statement.client_secret', 'gmail-client-secret');
        config()->set('gmail_statement.redirect_uri', 'https://pospilot.example/integrations/gmail/callback');
        $user = User::factory()->create(['email_verified_at' => now()]);
        AgentProfile::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])->get('/integrations/gmail/connect');

        $response->assertRedirect();
        $this->assertStringContainsString('scope=https%3A%2F%2Fwww.googleapis.com%2Fauth%2Fgmail.readonly', $response->headers->get('Location'));
        $this->assertStringContainsString('redirect_uri=https%3A%2F%2Fpospilot.example%2Fintegrations%2Fgmail%2Fcallback', $response->headers->get('Location'));
        $this->assertStringContainsString('access_type=offline', $response->headers->get('Location'));
    }

    public function test_oauth_state_is_checked_before_exchanging_code_and_tokens_are_encrypted_and_hidden(): void
    {
        config()->set('gmail_statement.enabled', true);
        config()->set('gmail_statement.client_id', 'gmail-client-id');
        config()->set('gmail_statement.client_secret', 'gmail-client-secret');
        config()->set('gmail_statement.redirect_uri', 'https://pospilot.example/integrations/gmail/callback');
        $user = User::factory()->create(['email_verified_at' => now()]);
        $agent = AgentProfile::factory()->create(['user_id' => $user->id]);
        Provider::factory()->create(['slug' => 'opay', 'name' => 'OPay']);
        Queue::fake([SyncConnectedGmailStatements::class]);
        Notification::fake();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'fictional-access-token',
                'refresh_token' => 'fictional-refresh-token',
                'expires_in' => 3600,
                'scope' => 'https://www.googleapis.com/auth/gmail.readonly',
            ]),
            'https://gmail.googleapis.com/gmail/v1/users/me/profile' => Http::response(['emailAddress' => 'owner@example.test']),
        ]);

        $stateMismatch = $this->actingAs($user)->withSession([
            'gmail_oauth_state' => Crypt::encryptString('expected-state'),
            'gmail_oauth_agent_id' => $agent->id,
        ])->get('/integrations/gmail/callback?code=fake-code&state=wrong-state');
        $stateMismatch->assertRedirect('/dashboard?screen=providers');
        Http::assertNothingSent();

        $callback = $this->actingAs($user)->withSession([
            'gmail_oauth_state' => Crypt::encryptString('expected-state'),
            'gmail_oauth_agent_id' => $agent->id,
        ])->get('/integrations/gmail/callback?code=fake-code&state=expected-state');
        $callback->assertRedirect('/dashboard?screen=providers');
        $callback->assertSessionHas('gmail_status');
        Http::assertSent(fn ($request): bool => $request->url() === 'https://oauth2.googleapis.com/token'
            && $request['redirect_uri'] === 'https://pospilot.example/integrations/gmail/callback');

        $connection = GmailConnection::query()->firstOrFail();
        $credential = $connection->credential()->firstOrFail();
        $this->assertNotSame('fictional-access-token', $credential->getRawOriginal('access_token'));
        $this->assertNotSame('fictional-refresh-token', $credential->getRawOriginal('refresh_token'));
        $this->assertArrayNotHasKey('access_token', $connection->toArray());
        $this->assertArrayNotHasKey('refresh_token', $connection->toArray());
        $this->assertArrayNotHasKey('access_token', $credential->toArray());
        $this->assertArrayNotHasKey('refresh_token', $credential->toArray());
        $this->assertSame('o••••@example.test', $connection->gmail_address_masked);
        $this->assertDatabaseMissing('gmail_connections', ['agent_profile_id' => $agent->id, 'access_token' => 'fictional-access-token']);
        Queue::assertPushed(SyncConnectedGmailStatements::class, fn (SyncConnectedGmailStatements $job): bool => $job->connectionId === $connection->id);
        Notification::assertSentTo($user, SecurityAlertNotification::class);
    }

    public function test_gmail_routes_stay_hidden_when_feature_flag_is_disabled(): void
    {
        config()->set('gmail_statement.enabled', false);
        $user = User::factory()->create(['email_verified_at' => now()]);
        AgentProfile::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])->get('/integrations/gmail/connect')->assertNotFound();
        $this->actingAs($user)->postJson('/integrations/gmail/sync')->assertNotFound();
    }

    public function test_manual_sync_queues_only_the_authenticated_agents_gmail_connection(): void
    {
        config()->set('gmail_statement.enabled', true);
        $firstUser = User::factory()->create(['email_verified_at' => now()]);
        $firstAgent = AgentProfile::factory()->create(['user_id' => $firstUser->id, 'selected_provider_slugs' => ['opay']]);
        $secondUser = User::factory()->create(['email_verified_at' => now()]);
        $secondAgent = AgentProfile::factory()->create(['user_id' => $secondUser->id, 'selected_provider_slugs' => ['opay']]);
        Provider::factory()->create(['slug' => 'opay', 'name' => 'OPay']);
        $firstConnection = $this->createGmailConnection($firstAgent, ['status' => 'connected']);
        $secondConnection = $this->createGmailConnection($secondAgent, ['status' => 'sync_error']);
        Queue::fake([SyncConnectedGmailStatements::class]);

        $this->actingAs($firstUser)->postJson('/integrations/gmail/sync')->assertAccepted()->assertJson(['status' => 'sync_queued']);
        $this->actingAs($secondUser)->postJson('/integrations/gmail/sync')->assertAccepted()->assertJson(['status' => 'sync_queued']);

        $this->assertSame('sync_queued', $firstConnection->fresh()->status);
        $this->assertSame('sync_queued', $secondConnection->fresh()->status);
        Queue::assertPushed(SyncConnectedGmailStatements::class, 2);
    }

    public function test_first_gmail_sync_is_limited_to_30_days_and_older_import_is_explicit(): void
    {
        $agent = AgentProfile::factory()->create(['selected_provider_slugs' => ['opay']]);
        Provider::factory()->create(['slug' => 'opay', 'name' => 'OPay']);
        $connection = $this->createGmailConnection($agent, [
            'provider_rules' => ['opay' => ['sender_email' => 'statements@opay.example.test']],
            'status' => 'connected',
        ]);
        Http::fake(['https://gmail.googleapis.com/*' => Http::sequence()->push(['messages' => []])->push(['messages' => []])]);

        app(GmailStatementConnector::class)->sync($connection);
        app(GmailStatementConnector::class)->sync($connection->fresh(), true);

        $queries = Http::recorded()->map(function ($pair): string {
            parse_str((string) parse_url($pair[0]->url(), PHP_URL_QUERY), $query);

            return (string) ($query['q'] ?? '');
        });
        $this->assertCount(2, $queries);
        $this->assertStringContainsString('newer_than:30d', $queries[0]);
        $this->assertStringNotContainsString('365d', $queries[0]);
        $this->assertStringContainsString('newer_than:365d', $queries[1]);
        $this->assertStringContainsString('before:', $queries[1]);
        $this->assertNotNull($connection->fresh()->last_synced_at);
    }

    public function test_targeted_sync_job_retries_a_connection_after_a_previous_sync_error(): void
    {
        config()->set('gmail_statement.enabled', true);
        $agent = AgentProfile::factory()->create();
        $connection = $this->createGmailConnection($agent, ['status' => 'sync_error']);

        (new SyncConnectedGmailStatements($connection->id))->handle(app(GmailStatementConnector::class));

        $this->assertSame('connected', $connection->fresh()->status);
        $this->assertNotNull($connection->fresh()->last_synced_at);
        $this->assertSame('no_new_statements', $connection->fresh()->last_sync_status);
    }

    public function test_multiple_provider_rules_reject_a_sender_shared_by_distinct_providers(): void
    {
        config()->set('gmail_statement.enabled', true);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $agent = AgentProfile::factory()->create(['user_id' => $user->id]);
        Provider::factory()->create(['slug' => 'opay', 'name' => 'OPay']);
        Provider::factory()->create(['slug' => 'palmpay', 'name' => 'PalmPay']);

        $this->actingAs($user)->putJson('/api/gmail/connection/rules', [
            'selected_provider_slugs' => ['opay', 'palmpay'],
            'provider_rules' => [
                'opay' => ['sender_email' => 'statements@example.test'],
                'palmpay' => ['sender_email' => 'statements@example.test'],
            ],
        ])->assertUnprocessable();
        $this->assertNull($agent->fresh()->selected_provider_slugs);

        $this->actingAs($user)->putJson('/api/gmail/connection/rules', [
            'selected_provider_slugs' => ['opay', 'palmpay'],
            'provider_rules' => [
                'opay' => ['sender_email' => 'statements@opay.example.test'],
                'palmpay' => ['sender_email' => 'statements@palmpay.example.test'],
            ],
        ])->assertOk();
        $this->assertSame(['opay', 'palmpay'], $agent->fresh()->selected_provider_slugs);
    }

    public function test_disconnect_revokes_and_deletes_the_owned_gmail_token_but_keeps_connection_history(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $agent = AgentProfile::factory()->create(['user_id' => $user->id]);
        $connection = $this->createGmailConnection($agent, ['status' => 'connected']);
        Http::fake(['https://oauth2.googleapis.com/revoke' => Http::response([], 200)]);
        Notification::fake();

        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])->delete('/integrations/gmail')->assertRedirect('/dashboard?screen=providers');

        $this->assertSame('disconnected', $connection->fresh()->status);
        $this->assertDatabaseMissing('gmail_credentials', ['gmail_connection_id' => $connection->id]);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://oauth2.googleapis.com/revoke'
            && $request['token'] === 'fictional-refresh-token');
        Notification::assertSentTo($user, SecurityAlertNotification::class);
    }

    public function test_gmail_sync_ignores_unrelated_sender_and_retains_only_safe_csv_headers_for_mapping(): void
    {
        Storage::fake('local');
        $agent = AgentProfile::factory()->create(['selected_provider_slugs' => ['opay']]);
        $provider = Provider::factory()->create(['slug' => 'opay', 'name' => 'OPay']);
        $connection = $this->createGmailConnection($agent, [
            'provider_rules' => ['opay' => ['sender_email' => 'statements@opay.example.test']],
            'status' => 'connected',
        ]);
        $csv = "Reference,Amount,Date,Status,Terminal\nFAKE-REFERENCE-ONLY,5000.00,2026-09-27 10:30:00,successful,OPAY-TERM-4821\n";
        $attachment = rtrim(strtr(base64_encode($csv), '+/', '-_'), '=');
        Http::fake([
            'https://gmail.googleapis.com/*' => Http::sequence()
                ->push(['messages' => [['id' => 'unrelated-message'], ['id' => 'statement-message']]])
                ->push(['payload' => ['headers' => [['name' => 'From', 'value' => 'OPay <not-the-configured-sender@example.test>'], ['name' => 'Subject', 'value' => 'OPay statement']]]])
                ->push(['internalDate' => (string) now()->getTimestampMs(), 'payload' => [
                    'headers' => [['name' => 'From', 'value' => 'OPay Statements <statements@opay.example.test>'], ['name' => 'Subject', 'value' => 'OPay statement']]],
                ])
                ->push(['internalDate' => (string) now()->getTimestampMs(), 'payload' => [
                    'headers' => [['name' => 'From', 'value' => 'OPay Statements <statements@opay.example.test>']],
                    'parts' => [['filename' => 'statement.csv', 'mimeType' => 'text/csv', 'body' => ['attachmentId' => 'attachment-one', 'size' => strlen($csv)]]],
                ]])
                ->push(['data' => $attachment])
                ->push(['messages' => [['id' => 'statement-message'], ['id' => 'future-statement-message']]])
                ->push(['payload' => ['headers' => [['name' => 'From', 'value' => 'OPay Statements <statements@opay.example.test>'], ['name' => 'Subject', 'value' => 'OPay statement']]]])
                ->push(['internalDate' => (string) now()->getTimestampMs(), 'payload' => [
                    'headers' => [['name' => 'From', 'value' => 'OPay Statements <statements@opay.example.test>']],
                    'parts' => [['filename' => 'statement.csv', 'mimeType' => 'text/csv', 'body' => ['attachmentId' => 'future-attachment', 'size' => strlen($csv)]]],
                ]])
                ->push(['data' => $attachment]),
        ]);

        app(GmailStatementConnector::class)->sync($connection);

        $this->assertSame(1, $connection->messages()->count());
        $statement = $connection->messages()->firstOrFail();
        $this->assertSame('needs_setup', $statement->status);
        $this->assertSame(['Reference', 'Amount', 'Date', 'Status', 'Terminal'], $statement->headers);
        $this->assertArrayNotHasKey('FAKE-REFERENCE-ONLY', $statement->toArray());
        $this->assertNotSame('statement-message', $statement->getRawOriginal('gmail_message_id'));
        $this->assertNotSame('attachment-one', $statement->getRawOriginal('gmail_attachment_id'));
        $encryptedTemporary = Storage::disk('local')->get($statement->temporary_file_path);
        $this->assertStringNotContainsString('FAKE-REFERENCE-ONLY', $encryptedTemporary);
        Http::assertSent(function ($request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return parse_url($request->url(), PHP_URL_PATH) === '/gmail/v1/users/me/messages'
                && str_contains((string) ($query['q'] ?? ''), 'from:statements@opay.example.test')
                && str_contains((string) ($query['q'] ?? ''), 'has:attachment')
                && str_contains((string) ($query['q'] ?? ''), 'subject:');
        });

        $terminal = Terminal::factory()->create([
            'agent_profile_id' => $agent->id,
            'provider_id' => $provider->id,
            'terminal_identifier' => 'OPAY-TERM-4821',
        ]);
        $imports = app(GmailStatementImportService::class);
        $profile = $imports->saveMapping($statement, [
            'external_reference' => 'Reference',
            'amount' => 'Amount',
            'transaction_at' => 'Date',
            'transaction_status' => 'Status',
            'terminal_identifier' => 'Terminal',
        ], $terminal->id);
        $this->assertNotSame('OPAY-TERM-4821', $profile->match_identifier_fingerprint);
        $imports->process($statement, $profile);

        $connection->update(['last_synced_at' => null, 'status' => 'connected']);
        app(GmailStatementConnector::class)->sync($connection);

        $future = $connection->messages()->get()->first(fn (GmailStatementMessage $candidate): bool => $candidate->gmail_message_id === 'future-statement-message');
        $this->assertNotNull($future);
        $this->assertSame('duplicate_attachment', $future->status);
        $this->assertSame(1, Transaction::where('agent_profile_id', $agent->id)->count());
    }

    public function test_gmail_sync_marks_repeated_attachment_content_as_duplicate_before_setup(): void
    {
        Storage::fake('local');
        $agent = AgentProfile::factory()->create(['selected_provider_slugs' => ['opay']]);
        Provider::factory()->create(['slug' => 'opay', 'name' => 'OPay']);
        $connection = $this->createGmailConnection($agent, [
            'provider_rules' => ['opay' => ['sender_email' => 'statements@opay.example.test']],
            'status' => 'connected',
        ]);
        $csv = "Reference,Amount,Date,Status\nFICTIONAL-REF-1,5000.00,2026-09-27 10:30:00,successful\n";
        $attachment = rtrim(strtr(base64_encode($csv), '+/', '-_'), '=');
        $headers = ['headers' => [
            ['name' => 'From', 'value' => 'OPay <statements@opay.example.test>'],
            ['name' => 'Subject', 'value' => 'OPay statement'],
        ]];
        Http::fake(['https://gmail.googleapis.com/*' => Http::sequence()
            ->push(['messages' => [['id' => 'older-message'], ['id' => 'newer-message']]])
            ->push(['internalDate' => (string) now()->subMinute()->getTimestampMs(), 'payload' => $headers])
            ->push(['internalDate' => (string) now()->getTimestampMs(), 'payload' => $headers])
            ->push(['payload' => ['parts' => [['filename' => 'statement.csv', 'body' => ['attachmentId' => 'newer-attachment', 'size' => strlen($csv)]]]]])
            ->push(['data' => $attachment])
            ->push(['payload' => ['parts' => [['filename' => 'statement.csv', 'body' => ['attachmentId' => 'older-attachment', 'size' => strlen($csv)]]]]])
            ->push(['data' => $attachment]),
        ]);

        app(GmailStatementConnector::class)->sync($connection);

        $messages = $connection->messages()->get();
        $this->assertSame(2, $messages->count());
        $this->assertSame(1, $messages->where('status', 'needs_setup')->count());
        $this->assertSame(1, $messages->where('status', 'duplicate_attachment')->count());
        $this->assertSame(0, Transaction::where('agent_profile_id', $agent->id)->count());
    }

    public function test_pdf_with_an_updated_pos_parser_is_retried_and_exposes_safe_mapping_headers(): void
    {
        Storage::fake('local');
        $agent = AgentProfile::factory()->create(['selected_provider_slugs' => ['opay']]);
        $provider = Provider::factory()->create(['slug' => 'opay', 'name' => 'OPay']);
        $connection = $this->createGmailConnection($agent, [
            'provider_rules' => ['opay' => ['sender_email' => 'statements@opay.example.test']],
            'status' => 'connected',
        ]);
        $messageId = 'previously-unrecognized-pdf';
        $attachmentId = 'fictional-pdf-attachment';
        $processedMessageFingerprint = hash_hmac('sha256', $connection->id.'|message|'.$messageId, (string) config('app.key'));
        $dedupeFingerprint = hash_hmac('sha256', $connection->id.'|'.$messageId.'|'.$attachmentId, (string) config('app.key'));
        $record = GmailStatementMessage::create([
            'gmail_connection_id' => $connection->id,
            'agent_profile_id' => $agent->id,
            'provider_id' => $provider->id,
            'dedupe_fingerprint' => $dedupeFingerprint,
            'processed_message_fingerprint' => $processedMessageFingerprint,
            'gmail_message_id' => $messageId,
            'gmail_attachment_id' => $attachmentId,
            'file_name' => 'statement.pdf',
            'file_type' => 'pdf',
            'headers' => ['Old table extraction'],
            'status' => 'unsupported_schema',
            'failure_code' => 'pdf_table_unrecognized',
            'received_at' => now()->subDay(),
        ]);
        $pdf = $this->opayPositionedPdf();
        $attachment = rtrim(strtr(base64_encode($pdf), '+/', '-_'), '=');
        Http::fake(['https://gmail.googleapis.com/*' => Http::sequence()
            ->push(['messages' => [['id' => $messageId]]])
            ->push(['internalDate' => (string) now()->getTimestampMs(), 'payload' => ['headers' => [
                ['name' => 'From', 'value' => 'OPay <statements@opay.example.test>'],
                ['name' => 'Subject', 'value' => 'OPay statement'],
            ]]])
            ->push(['payload' => ['parts' => [['filename' => 'statement.pdf', 'body' => ['attachmentId' => $attachmentId, 'size' => strlen($pdf)]]]]])
            ->push(['data' => $attachment]),
        ]);

        app(GmailStatementConnector::class)->sync($connection);

        $this->assertSame('needs_setup', $record->fresh()->status);
        $this->assertSame(['Transaction Reference', 'Amount', 'Date', 'Status', 'Transaction Type', 'Provider Account Identifier'], $record->fresh()->headers);
        $this->assertNotNull($record->fresh()->masked_account_identifier);
        $this->assertNotSame('0123456789', $record->fresh()->masked_account_identifier);
        $this->assertStringNotContainsString('0123456789', json_encode($record->fresh()->toArray(), JSON_THROW_ON_ERROR));
        $this->assertNotNull($record->fresh()->temporary_file_path);
        $this->assertSame(0, Transaction::where('agent_profile_id', $agent->id)->count());
    }

    public function test_opay_personal_wallet_pdf_is_imported_only_as_separate_wallet_activity(): void
    {
        config()->set('gmail_statement.enabled', true);
        Storage::fake('local');
        $user = User::factory()->create(['email_verified_at' => now()]);
        $agent = AgentProfile::factory()->create(['user_id' => $user->id, 'selected_provider_slugs' => ['opay']]);
        $provider = Provider::factory()->create(['slug' => 'opay', 'name' => 'OPay']);
        $connection = $this->createGmailConnection($agent, [
            'provider_rules' => ['opay' => ['sender_email' => 'statements@opay.example.test']],
            'status' => 'connected',
        ]);
        $messageId = 'fictional-wallet-statement';
        $attachmentId = 'fictional-wallet-pdf';
        $pdf = $this->opayPositionedPdf('Wallet withdrawal', 'Wallet');
        $attachment = rtrim(strtr(base64_encode($pdf), '+/', '-_'), '=');
        Http::fake(['https://gmail.googleapis.com/*' => Http::sequence()
            ->push(['messages' => [['id' => $messageId]]])
            ->push(['internalDate' => (string) now()->getTimestampMs(), 'payload' => ['headers' => [
                ['name' => 'From', 'value' => 'OPay <statements@opay.example.test>'],
                ['name' => 'Subject', 'value' => 'OPay statement'],
            ]]])
            ->push(['payload' => ['parts' => [['filename' => 'statement.pdf', 'body' => ['attachmentId' => $attachmentId, 'size' => strlen($pdf)]]]]])
            ->push(['data' => $attachment]),
        ]);

        app(GmailStatementConnector::class)->sync($connection);

        $message = GmailStatementMessage::where('gmail_connection_id', $connection->id)->firstOrFail();
        $this->assertSame('needs_setup', $message->status);
        $this->assertSame('personal_wallet_statement', $message->failure_code);
        $this->assertNotNull($message->temporary_file_path);
        $this->assertSame(['Transaction Reference', 'Amount', 'Date', 'Status', 'Transaction Type', 'Provider Account Identifier'], $message->headers);
        $this->assertNotSame('0123456789', $message->masked_account_identifier);
        $this->assertSame(0, Transaction::where('agent_profile_id', $agent->id)->count());

        $this->actingAs($user)->postJson('/api/gmail/statements/'.$message->id.'/mapping', [
            'activity_scope' => 'pos_terminal',
            'terminal_id' => Terminal::factory()->create(['agent_profile_id' => $agent->id, 'provider_id' => $provider->id])->id,
        ])->assertStatus(422);

        $this->actingAs($user)->postJson('/api/gmail/statements/'.$message->id.'/mapping', [
            'activity_scope' => 'personal_wallet',
            'column_mapping' => [
                'external_reference' => 'Transaction Reference',
                'amount' => 'Amount',
                'transaction_at' => 'Date',
                'transaction_status' => 'Status',
                'transaction_type' => 'Transaction Type',
                'provider_account_identifier' => 'Provider Account Identifier',
            ],
        ])->assertOk()->assertJson(['status' => 'processed', 'rows_imported' => 1, 'rows_duplicate' => 0, 'rows_failed' => 0]);

        $walletTransaction = Transaction::where('agent_profile_id', $agent->id)->sole();
        $this->assertNull($walletTransaction->terminal_id);
        $this->assertSame('wallet_debit', $walletTransaction->transaction_type);
        $this->assertSame('personal_wallet', $walletTransaction->metadata['activity_scope']);
        $this->assertFalse($walletTransaction->provider_fee_supplied);
        $this->assertSame('0.00', $walletTransaction->customer_charge);
        $this->assertArrayNotHasKey('provider_account_identifier', $walletTransaction->metadata);
        $this->assertNotSame('0123456789', $walletTransaction->metadata['provider_account_identifier_fingerprint'] ?? null);

        $second = GmailStatementMessage::create([
            'gmail_connection_id' => $connection->id,
            'agent_profile_id' => $agent->id,
            'provider_id' => $provider->id,
            'dedupe_fingerprint' => hash('sha256', 'overlapping-wallet-statement'),
            'gmail_message_id' => 'encrypted-overlapping-message',
            'gmail_attachment_id' => 'encrypted-overlapping-attachment',
            'file_name' => 'wallet-statement.pdf',
            'file_type' => 'pdf',
            'headers' => $message->headers,
            'status' => 'needs_setup',
            'failure_code' => 'personal_wallet_statement',
        ]);
        $imports = app(GmailStatementImportService::class);
        $imports->retainForMapping($second, "Transaction Reference,Amount,Date,Status,Transaction Type,Provider Account Identifier\nFICTIONAL-REF-1,5000.00,2026-09-28 09:15:00,successful,wallet_debit,0123456789\n");
        $profile = $message->fresh()->mappingProfile;
        $imports->process($second, $profile);

        $this->assertSame(1, Transaction::where('agent_profile_id', $agent->id)->count());
        $this->assertSame(1, $second->fresh()->rows_duplicate);
        $earnings = app(EarningsService::class)->summarize($agent);
        $this->assertSame(0, $earnings['successful_transaction_count']);
        $this->assertSame('0.00', $earnings['estimated_net_earnings']);
        $this->assertSame('0.00', app(ReconciliationService::class)->expectedSettlementFor($agent, $provider->id, date: '2026-09-28'));
        $closing = app(DailyClosingService::class)->preview($agent, CarbonImmutable::parse('2026-09-28'));
        $this->assertSame('0.00', $closing['transaction_volume']);
        $this->assertSame('0.00', $closing['expected_electronic_position']);
    }

    public function test_mapped_csv_uses_existing_ingestion_and_keeps_missing_fees_unverified_and_idempotent(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['email_verified_at' => now()]);
        $agent = AgentProfile::factory()->create(['user_id' => $user->id]);
        $provider = Provider::factory()->create(['slug' => 'opay', 'name' => 'OPay']);
        $terminal = Terminal::factory()->create([
            'agent_profile_id' => $agent->id,
            'provider_id' => $provider->id,
            'terminal_identifier' => 'OPAY-TERM-4821',
        ]);
        $connection = $this->createGmailConnection($agent, ['provider_rules' => [], 'status' => 'connected']);
        $contents = "Reference,Amount,Date,Status,Terminal\nOP-REF-TEST-1,5000.00,2026-09-27 10:30:00,successful,OPAY-TERM-4821\n";
        $first = GmailStatementMessage::create([
            'gmail_connection_id' => $connection->id,
            'agent_profile_id' => $agent->id,
            'provider_id' => $provider->id,
            'dedupe_fingerprint' => hash('sha256', 'message-one'),
            'gmail_message_id' => 'encrypted-message-one',
            'gmail_attachment_id' => 'encrypted-attachment-one',
            'file_name' => 'statement.csv',
            'file_type' => 'csv',
            'headers' => ['Reference', 'Amount', 'Date', 'Status', 'Terminal'],
            'status' => 'needs_setup',
        ]);
        $imports = app(GmailStatementImportService::class);
        $imports->retainForMapping($first, $contents);
        $profile = $imports->saveMapping($first, [
            'external_reference' => 'Reference',
            'amount' => 'Amount',
            'transaction_at' => 'Date',
            'transaction_status' => 'Status',
            'terminal_identifier' => 'Terminal',
        ], $terminal->id);

        $this->assertNotSame('OPAY-TERM-4821', $profile->match_identifier_fingerprint);
        $this->assertSame('••••4821', $profile->masked_match_identifier);
        $imports->process($first, $profile);

        $this->assertSame(1, Transaction::where('agent_profile_id', $agent->id)->count());
        $transaction = Transaction::where('agent_profile_id', $agent->id)->firstOrFail();
        $this->assertSame(TransactionSource::Statement, $transaction->source);
        $this->assertFalse($transaction->provider_fee_supplied);
        $this->assertSame($terminal->id, $transaction->terminal_id);
        $this->assertNull($first->fresh()->temporary_file_path);

        $second = GmailStatementMessage::create([
            'gmail_connection_id' => $connection->id,
            'agent_profile_id' => $agent->id,
            'provider_id' => $provider->id,
            'dedupe_fingerprint' => hash('sha256', 'message-two'),
            'gmail_message_id' => 'encrypted-message-two',
            'gmail_attachment_id' => 'encrypted-attachment-two',
            'file_name' => 'statement.csv',
            'file_type' => 'csv',
            'headers' => ['Reference', 'Amount', 'Date', 'Status', 'Terminal'],
            'status' => 'needs_setup',
        ]);
        $imports->retainForMapping($second, $contents);
        $imports->process($second, $profile);

        $this->assertSame(1, Transaction::where('agent_profile_id', $agent->id)->count());
        $this->assertSame(1, $second->fresh()->rows_duplicate);
    }

    public function test_xlsx_reader_extracts_a_sanitized_first_worksheet_without_formula_values(): void
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pospilot-statement-'.bin2hex(random_bytes(6)).'.zip';
        $archive = new \PharData($path);
        $archive->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Statement" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $archive->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml" Type="worksheet"/></Relationships>');
        $archive->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>Reference</t></is></c><c r="B1" t="inlineStr"><is><t>Amount</t></is></c></row><row r="2"><c r="A2" t="inlineStr"><is><t>FAKE-REF-1</t></is></c><c r="B2"><v>500.00</v></c></row></sheetData></worksheet>');
        unset($archive);

        try {
            $csv = app(XlsxStatementReader::class)->toCsv(file_get_contents($path));
        } finally {
            if (file_exists($path)) {
                unlink($path);
            }
        }

        $this->assertSame("Reference,Amount\nFAKE-REF-1,500.00\n", $csv);
    }

    private function createGmailConnection(AgentProfile $agent, array $overrides = []): GmailConnection
    {
        $connection = GmailConnection::create(array_merge([
            'agent_profile_id' => $agent->id,
            'status' => 'connected',
            'provider_rules' => [],
        ], $overrides));

        $connection->credential()->create([
            'access_token' => 'fictional-access-token',
            'refresh_token' => 'fictional-refresh-token',
            'token_expires_at' => now()->addHour(),
        ]);

        return $connection;
    }

    private function opayPositionedPdf(string $description = 'POS purchase', string $channel = 'POS'): string
    {
        $isWithdrawal = str_contains(strtolower($description), 'withdraw');
        $elements = [
            [80, 740, 'Account Number'], [180, 740, '0123456789'],
            [50, 700, 'Trans. Time'], [120, 700, 'Value Date'], [185, 700, 'Description'], [250, 700, 'Debit(₦)'],
            [300, 700, 'Credit(₦)'], [340, 700, 'Balance After'], [390, 700, 'Channel'], [455, 700, 'Transaction Reference'],
            [50, 680, '09:15:00 AM'], [120, 680, '09/28/2026'], [185, 680, $description], [250, 680, $isWithdrawal ? '5,000.00' : '-'],
            [300, 680, $isWithdrawal ? '-' : '5,000.00'], [340, 680, '17,000.00'], [390, 680, $channel], [455, 680, 'FICTIONAL-REF-1'],
        ];
        $stream = '';
        foreach ($elements as [$x, $y, $text]) {
            $escapedText = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
            $stream .= 'BT /F1 10 Tf '.$x.' '.$y.' Td ('.$escapedText.') Tj ET ';
        }
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
            '<< /Length '.strlen($stream).' >>'."\nstream\n".$stream."\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
        }
        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 6\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n".$xrefOffset."\n%%EOF";

        return $pdf;
    }
}
