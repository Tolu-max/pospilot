<?php

namespace Tests\Feature;

use App\Enums\TransactionSource;
use App\Jobs\SyncConnectedGmailStatements;
use App\Models\AgentProfile;
use App\Models\GmailConnection;
use App\Models\GmailStatementMessage;
use App\Models\Provider;
use App\Models\ProviderAccount;
use App\Models\StatementMappingProfile;
use App\Models\Terminal;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\SecurityAlertNotification;
use App\Services\Gmail\GmailPdfStatementReader;
use App\Services\Gmail\GmailStatementConnector;
use App\Services\Gmail\PdfStatementTableReader;
use App\Services\GmailStatementImportService;
use App\Services\XlsxStatementReader;
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

    public function test_older_statement_check_queues_only_the_owners_connection(): void
    {
        config()->set('gmail_statement.enabled', true);
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $agent = AgentProfile::factory()->create(['user_id' => $owner->id, 'selected_provider_slugs' => ['opay']]);
        $otherAgent = AgentProfile::factory()->create(['selected_provider_slugs' => ['opay']]);
        $connection = $this->createGmailConnection($agent, ['status' => 'connected']);
        $otherConnection = $this->createGmailConnection($otherAgent, ['status' => 'connected']);
        Queue::fake([SyncConnectedGmailStatements::class]);

        $this->actingAs($owner)->postJson('/integrations/gmail/import-older')
            ->assertAccepted()->assertJson(['status' => 'sync_queued']);

        $this->assertSame('sync_queued', $connection->fresh()->status);
        $this->assertSame('connected', $otherConnection->fresh()->status);
        Queue::assertPushed(SyncConnectedGmailStatements::class, 1);
        Queue::assertPushed(SyncConnectedGmailStatements::class, fn (SyncConnectedGmailStatements $job): bool => $job->connectionId === $connection->id && $job->includeOlder);
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

    public function test_sync_job_marks_an_interrupted_sync_as_retryable(): void
    {
        config()->set('gmail_statement.enabled', false);
        $agent = AgentProfile::factory()->create();
        $connection = $this->createGmailConnection($agent, ['status' => 'syncing']);
        $otherConnection = $this->createGmailConnection(AgentProfile::factory()->create(), ['status' => 'syncing']);
        $connection->timestamps = false;
        $connection->forceFill(['updated_at' => now()->subMinutes(11)])->save();

        (new SyncConnectedGmailStatements($connection->id))->handle(app(GmailStatementConnector::class));

        $this->assertSame('sync_error', $connection->fresh()->status);
        $this->assertSame('gmail_sync_interrupted', $connection->fresh()->last_error_code);
        $this->assertSame('syncing', $otherConnection->fresh()->status);
    }

    public function test_multiple_provider_rules_reject_a_sender_shared_by_distinct_providers(): void
    {
        config()->set('gmail_statement.enabled', true);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $agent = AgentProfile::factory()->create(['user_id' => $user->id]);
        Provider::factory()->create(['slug' => 'opay', 'name' => 'OPay']);
        Provider::factory()->create(['slug' => 'palmpay', 'name' => 'PalmPay']);
        Provider::factory()->create(['slug' => 'moniepoint', 'name' => 'Moniepoint']);

        $this->actingAs($user)->putJson('/api/gmail/connection/rules', [
            'selected_provider_slugs' => ['opay', 'palmpay', 'moniepoint'],
            'provider_rules' => [
                'opay' => ['sender_email' => 'statements@example.test'],
                'palmpay' => ['sender_email' => 'statements@example.test'],
                'moniepoint' => ['sender_email' => 'statements@example.test'],
            ],
        ])->assertUnprocessable();
        $this->assertNull($agent->fresh()->selected_provider_slugs);

        $this->actingAs($user)->putJson('/api/gmail/connection/rules', [
            'selected_provider_slugs' => ['opay', 'palmpay', 'moniepoint'],
            'provider_rules' => [
                'opay' => ['sender_email' => 'statements@opay.example.test', 'account_type' => 'business_pos'],
                'palmpay' => ['sender_email' => 'statements@palmpay.example.test', 'account_type' => 'personal_dedicated_pos'],
                'moniepoint' => ['sender_email' => 'statements@moniepoint.example.test', 'account_type' => 'mixed_personal_pos'],
            ],
        ])->assertOk();
        $this->assertSame(['opay', 'palmpay', 'moniepoint'], $agent->fresh()->selected_provider_slugs);
        $this->assertSame('business_pos', data_get($agent->fresh()->statement_sender_rules, 'opay.account_type'));
        $this->assertSame('personal_dedicated_pos', data_get($agent->fresh()->statement_sender_rules, 'palmpay.account_type'));
        $this->assertSame('mixed_personal_pos', data_get($agent->fresh()->statement_sender_rules, 'moniepoint.account_type'));
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
        $pdf = $this->opayPositionedPdf('POS purchase', 'POS', [
            ['POS purchase', 'POS', 'FICTIONAL-REF-2', '-', '2,000.00'],
            ['POS purchase', 'POS', 'FICTIONAL-REF-3', '-', '3,000.00'],
            ['POS purchase', 'POS', 'FICTIONAL-REF-4', '-', '4,000.00'],
            ['POS purchase', 'POS', 'FICTIONAL-REF-5', '-', '5,000.00'],
            ['POS purchase', 'POS', 'FICTIONAL-REF-6', '-', '6,000.00'],
            ['Wallet withdrawal', 'Wallet', 'WALLET-TEST-REF', '1,000.00', '-'],
        ]);
        $attachment = rtrim(strtr(base64_encode($pdf), '+/', '-_'), '=');
        Http::fake(['https://gmail.googleapis.com/*' => Http::sequence()
            ->push(['messages' => [['id' => $messageId]]])
            ->push(['internalDate' => (string) now()->getTimestampMs(), 'payload' => ['headers' => [
                ['name' => 'From', 'value' => 'OPay <statements@opay.example.test>'],
                ['name' => 'Subject', 'value' => 'OPay statement'],
            ]]])
            ->push(['payload' => ['parts' => [['filename' => 'statement.pdf', 'body' => ['attachmentId' => $attachmentId, 'size' => strlen($pdf)]]]]])
            ->push(['data' => $attachment])
            ->push(['messages' => []]),
        ]);

        app(GmailStatementConnector::class)->sync($connection);

        $this->assertSame('processed', $record->fresh()->status);
        $this->assertSame(['Transaction Reference', 'Amount', 'Date', 'Status', 'Transaction Type', 'Activity Classification', 'Activity Pattern', 'Provider Account Identifier'], $record->fresh()->headers);
        $this->assertNotNull($record->fresh()->masked_account_identifier);
        $this->assertNotSame('0123456789', $record->fresh()->masked_account_identifier);
        $this->assertStringNotContainsString('0123456789', json_encode($record->fresh()->toArray(), JSON_THROW_ON_ERROR));
        $this->assertNull($record->fresh()->temporary_file_path);
        $transactions = Transaction::where('agent_profile_id', $agent->id)->get();
        $this->assertCount(6, $transactions);
        $this->assertFalse($transactions->contains(fn (Transaction $transaction): bool => $transaction->external_reference === 'WALLET-TEST-REF'));
        $this->assertTrue($transactions->every(fn (Transaction $transaction): bool => $transaction->terminal_id === null));
        $this->assertTrue($transactions->every(fn (Transaction $transaction): bool => $transaction->provider_account_id !== null));
        $this->assertTrue($transactions->every(fn (Transaction $transaction): bool => $transaction->provider_fee_supplied === false));
        $this->assertTrue($transactions->every(fn (Transaction $transaction): bool => $transaction->sourceRecords()->where('source_type', 'provider_statement')->exists()));
        $transaction = $transactions->firstOrFail();
        $this->assertSame('high_provider_evidence', $record->fresh()->mappingProfile->confidence);
        $this->assertSame($transaction->provider_account_id, $record->fresh()->mappingProfile->provider_account_id);

        app(GmailStatementConnector::class)->sync($connection->fresh());

        $this->assertSame(6, Transaction::where('agent_profile_id', $agent->id)->count());
        $this->assertSame(0, $record->fresh()->rows_duplicate);
    }

    public function test_opay_personal_wallet_pdf_stays_excluded_from_pos_transactions(): void
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
        $this->assertSame('processed', $message->status);
        $this->assertSame('no_pos_activity', $message->failure_code);
        $this->assertSame(1, $message->activity_summary['wallet']);
        $this->assertNull($message->temporary_file_path);
        $this->assertNull($message->headers);
        $this->assertSame(0, Transaction::where('agent_profile_id', $agent->id)->count());

        $this->actingAs($user)->postJson('/api/gmail/statements/'.$message->id.'/mapping', [
            'activity_scope' => 'pos_terminal',
            'terminal_id' => Terminal::factory()->create(['agent_profile_id' => $agent->id, 'provider_id' => $provider->id])->id,
        ])->assertUnprocessable();

        $scopeMessage = GmailStatementMessage::create([
            'gmail_connection_id' => $connection->id,
            'agent_profile_id' => $agent->id,
            'provider_id' => $provider->id,
            'dedupe_fingerprint' => hash('sha256', 'invalid-activity-scope'),
            'gmail_message_id' => 'encrypted-invalid-scope-message',
            'gmail_attachment_id' => 'encrypted-invalid-scope-attachment',
            'file_name' => 'statement.csv',
            'file_type' => 'csv',
            'status' => 'needs_setup',
        ]);
        $this->actingAs($user)->postJson('/api/gmail/statements/'.$scopeMessage->id.'/mapping', [
            'activity_scope' => 'personal_wallet',
        ])->assertUnprocessable()->assertJsonValidationErrors('activity_scope');

        $second = GmailStatementMessage::create([
            'gmail_connection_id' => $connection->id,
            'agent_profile_id' => $agent->id,
            'provider_id' => $provider->id,
            'dedupe_fingerprint' => hash('sha256', 'overlapping-wallet-statement'),
            'gmail_message_id' => 'encrypted-overlapping-message',
            'gmail_attachment_id' => 'encrypted-overlapping-attachment',
            'file_name' => 'statement.pdf',
            'file_type' => 'pdf',
            'status' => 'needs_setup',
            'failure_code' => 'personal_wallet_statement',
        ]);
        $legacyProfile = StatementMappingProfile::create([
            'agent_profile_id' => $agent->id,
            'provider_id' => $provider->id,
            'file_type' => 'pdf',
            'schema_fingerprint' => hash('sha256', 'legacy-wallet-schema'),
            'match_identifier_fingerprint' => hash('sha256', 'legacy-wallet-account'),
            'column_mapping' => ['_activity_scope' => 'personal_wallet'],
            'status' => 'active',
        ]);
        app(GmailStatementImportService::class)->process($second, $legacyProfile);

        $this->assertSame('needs_setup', $second->fresh()->status);
        $this->assertSame(0, $second->fresh()->rows_imported);
        $this->assertSame(0, Transaction::where('agent_profile_id', $agent->id)->count());

        GmailStatementMessage::create([
            'gmail_connection_id' => $connection->id,
            'agent_profile_id' => $agent->id,
            'provider_id' => $provider->id,
            'statement_mapping_profile_id' => $legacyProfile->id,
            'dedupe_fingerprint' => hash('sha256', 'legacy-personal-wallet-import'),
            'gmail_message_id' => 'encrypted-legacy-wallet-message',
            'gmail_attachment_id' => 'encrypted-legacy-wallet-attachment',
            'file_name' => 'statement.pdf',
            'file_type' => 'pdf',
            'status' => 'processed',
            'rows_imported' => 1,
        ]);

        $this->actingAs($user)->getJson('/api/gmail/connection')->assertOk()
            ->assertJsonPath('statement_counts.processed', 0)
            ->assertJsonPath('statement_counts.rows_imported', 0);
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

    public function test_owner_classification_imports_selected_opay_activity_and_excludes_wallet_rows(): void
    {
        config()->set('gmail_statement.enabled', true);
        Storage::fake('local');
        $user = User::factory()->create(['email_verified_at' => now()]);
        $agent = AgentProfile::factory()->create(['user_id' => $user->id, 'selected_provider_slugs' => ['opay']]);
        $provider = Provider::factory()->create(['slug' => 'opay', 'name' => 'OPay']);
        $terminal = Terminal::factory()->create(['agent_profile_id' => $agent->id, 'provider_id' => $provider->id]);
        $connection = $this->createGmailConnection($agent);
        $pdf = $this->opayPositionedPdf('POS purchase', 'POS', [
            ['Wallet withdrawal', 'Wallet', 'WALLET-TEST-REF', '1,000.00', '-'],
            ['Transfer received', 'Online banking', 'AMBIGUOUS-TEST-REF', '-', '2,500.00'],
        ]);
        $pages = app(GmailPdfStatementReader::class)->extractPositionedPages($pdf);
        $table = app(PdfStatementTableReader::class)->toCsvFromPositionedPages($pages, 'opay');
        $message = GmailStatementMessage::create([
            'gmail_connection_id' => $connection->id,
            'agent_profile_id' => $agent->id,
            'provider_id' => $provider->id,
            'dedupe_fingerprint' => hash('sha256', 'classified-opay-statement'),
            'gmail_message_id' => 'encrypted-classified-message',
            'gmail_attachment_id' => 'encrypted-classified-attachment',
            'file_name' => 'statement.pdf',
            'file_type' => 'pdf',
            'headers' => $table['headers'],
            'activity_summary' => $table['activity_summary'],
            'status' => 'needs_setup',
            'failure_code' => 'activity_classification_required',
        ]);
        app(GmailStatementImportService::class)->retainForMapping($message, $table['csv']);

        $statementRows = array_map('str_getcsv', array_slice(explode("\n", trim($table['csv'])), 1));
        $walletPatternId = $statementRows[1][6];
        $this->actingAs($user)->postJson('/api/gmail/statements/'.$message->id.'/mapping', [
            'terminal_id' => $terminal->id,
            'account_type' => 'mixed_personal_pos',
            'column_mapping' => [
                'external_reference' => 'Transaction Reference',
                'amount' => 'Amount',
                'transaction_at' => 'Date',
                'transaction_status' => 'Status',
                'provider_account_identifier' => 'Provider Account Identifier',
            ],
            'activity_patterns' => [$walletPatternId],
        ])->assertUnprocessable();
        $this->assertSame(0, Transaction::where('agent_profile_id', $agent->id)->count());

        $this->actingAs($user)->postJson('/api/gmail/statements/'.$message->id.'/mapping', [
            'terminal_id' => $terminal->id,
            'column_mapping' => [
                'external_reference' => 'Transaction Reference',
                'amount' => 'Amount',
                'transaction_at' => 'Date',
                'transaction_status' => 'Status',
                'transaction_type' => 'Transaction Type',
                'provider_account_identifier' => 'Provider Account Identifier',
            ],
            'activity_classifications' => [$table['activity_summary']['patterns'][0]['id'] => 'pos'],
        ])->assertOk()->assertJsonPath('rows_imported', 2)->assertJsonPath('rows_excluded', 1);

        $transactions = Transaction::where('agent_profile_id', $agent->id)->get();
        $this->assertCount(2, $transactions);
        $this->assertEqualsCanonicalizing(['FICTIONAL-REF-1', 'AMBIGUOUS-TEST-REF'], $transactions->pluck('external_reference')->all());
        $this->assertTrue($transactions->every(fn (Transaction $transaction): bool => ! $transaction->provider_fee_supplied));
        $this->assertFalse($transactions->contains(fn (Transaction $transaction): bool => $transaction->external_reference === 'WALLET-TEST-REF'));
        $this->assertSame('processed', $message->fresh()->status);
        $this->assertSame('mixed_personal_pos', $message->fresh()->mappingProfile->account_type);
        $this->assertSame('owner_confirmed', $message->fresh()->mappingProfile->confidence);
        $this->assertSame('reviewed', $message->fresh()->mappingProfile->classification_status);
        $this->assertContains($table['activity_summary']['patterns'][0]['id'], $message->fresh()->mappingProfile->known_pos_transaction_patterns);
        $this->assertNull($message->fresh()->temporary_file_path);
        $this->assertStringNotContainsString('Wallet withdrawal', json_encode($message->fresh()->toArray(), JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('Transfer received', json_encode($message->fresh()->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_statement_list_prioritizes_owner_setup_and_hides_duplicate_attachment_rows(): void
    {
        config()->set('gmail_statement.enabled', true);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $agent = AgentProfile::factory()->create(['user_id' => $user->id]);
        $provider = Provider::factory()->create(['slug' => 'opay', 'name' => 'OPay']);
        $connection = $this->createGmailConnection($agent);
        $createMessage = function (string $status, int $daysAgo, string $key) use ($agent, $connection, $provider): GmailStatementMessage {
            return GmailStatementMessage::create([
                'gmail_connection_id' => $connection->id,
                'agent_profile_id' => $agent->id,
                'provider_id' => $provider->id,
                'dedupe_fingerprint' => hash('sha256', $key),
                'gmail_message_id' => 'safe-test-message-'.$key,
                'gmail_attachment_id' => 'safe-test-attachment-'.$key,
                'file_name' => 'statement.pdf',
                'file_type' => 'pdf',
                'status' => $status,
                'received_at' => now()->subDays($daysAgo),
            ]);
        };
        $setup = $createMessage('needs_setup', 2, 'setup');
        $unsupported = $createMessage('unsupported_schema', 0, 'unsupported');
        $processed = $createMessage('processed', 0, 'processed');
        $duplicate = $createMessage('duplicate_attachment', 0, 'duplicate');

        $response = $this->actingAs($user)->getJson('/api/gmail/statements')->assertOk();

        $response->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.id', $setup->id)
            ->assertJsonPath('data.1.id', $unsupported->id)
            ->assertJsonPath('data.2.id', $processed->id);
        $this->assertNotContains($duplicate->id, collect($response->json('data'))->pluck('id')->all());
    }

    public function test_new_ambiguous_opay_pattern_waits_for_owner_review_even_when_a_profile_exists(): void
    {
        Storage::fake('local');
        $agent = AgentProfile::factory()->create(['selected_provider_slugs' => ['opay']]);
        $provider = Provider::factory()->create(['slug' => 'opay', 'name' => 'OPay']);
        $terminal = Terminal::factory()->create(['agent_profile_id' => $agent->id, 'provider_id' => $provider->id]);
        $connection = $this->createGmailConnection($agent, [
            'provider_rules' => ['opay' => ['sender_email' => 'statements@opay.example.test']],
            'status' => 'connected',
        ]);
        $imports = app(GmailStatementImportService::class);
        $priorPattern = hash('sha256', 'bank|transfer');
        $sampleCsv = "Transaction Reference,Amount,Date,Status,Transaction Type,Activity Classification,Activity Pattern,Provider Account Identifier\nTEST-REF,1.00,2026-09-28,successful,pos_credit,ambiguous,{$priorPattern},0123456789\n";
        $schema = $imports->inspect($sampleCsv);
        StatementMappingProfile::create([
            'agent_profile_id' => $agent->id,
            'provider_id' => $provider->id,
            'terminal_id' => $terminal->id,
            'file_type' => 'pdf',
            'schema_fingerprint' => $schema['fingerprint'],
            'match_identifier_fingerprint' => hash_hmac('sha256', '0123456789', (string) config('app.key')),
            'column_mapping' => [
                'external_reference' => 'Transaction Reference',
                'amount' => 'Amount',
                'transaction_at' => 'Date',
                'transaction_status' => 'Status',
                'transaction_type' => 'Transaction Type',
                'provider_account_identifier' => 'Provider Account Identifier',
                '_pos_activity_patterns' => [$priorPattern],
                '_reviewed_activity_patterns' => [$priorPattern],
            ],
            'status' => 'active',
        ]);

        $messageId = 'new-ambiguous-pattern-message';
        $attachmentId = 'new-ambiguous-pattern-attachment';
        $pdf = $this->opayPositionedPdf('POS purchase', 'POS', [
            ['Cash deposit', 'Online banking', 'NEW-PATTERN-REF', '-', '1,500.00'],
        ]);
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
        $this->assertSame('activity_classification_required', $message->failure_code);
        $this->assertNotNull($message->temporary_file_path);
        $this->assertCount(0, Transaction::where('agent_profile_id', $agent->id)->get());
    }

    public function test_saved_personal_pos_account_pattern_is_reused_for_future_gmail_statements_without_a_terminal(): void
    {
        Storage::fake('local');
        $agent = AgentProfile::factory()->create(['selected_provider_slugs' => ['opay']]);
        $provider = Provider::factory()->create(['slug' => 'opay', 'name' => 'OPay']);
        $connection = $this->createGmailConnection($agent, [
            'provider_rules' => ['opay' => ['sender_email' => 'statements@opay.example.test', 'account_type' => 'personal_dedicated_pos']],
            'status' => 'connected',
        ]);
        $imports = app(GmailStatementImportService::class);
        $pdf = $this->opayPositionedPdf('POS purchase', 'POS', [
            ['Wallet withdrawal', 'Wallet', 'WALLET-TEST-REF', '1,000.00', '-'],
            ['Cash deposit', 'Online banking', 'AMBIGUOUS-TEST-REF', '-', '1,500.00'],
            ['Transfer received', 'Online banking', 'PERSONAL-EXCLUDED-REF', '-', '2,000.00'],
        ]);
        $table = app(PdfStatementTableReader::class)->toCsvFromPositionedPages(app(GmailPdfStatementReader::class)->extractPositionedPages($pdf), 'opay');
        $statementRows = array_map('str_getcsv', array_slice(explode("\n", trim($table['csv'])), 1));
        $posPatternId = collect($statementRows)->first(fn (array $row): bool => $row[0] === 'AMBIGUOUS-TEST-REF')[6];
        $excludedPatternId = collect($statementRows)->first(fn (array $row): bool => $row[0] === 'PERSONAL-EXCLUDED-REF')[6];
        $schema = $imports->inspect($table['csv']);
        $accountFingerprint = hash_hmac('sha256', '0123456789', (string) config('app.key'));
        $providerAccount = ProviderAccount::create([
            'agent_profile_id' => $agent->id,
            'provider_id' => $provider->id,
            'display_name' => 'OPay personal POS account',
            'account_type' => 'personal_dedicated_pos',
            'identifier_fingerprint' => $accountFingerprint,
            'masked_identifier' => '••••6789',
        ]);
        $profile = StatementMappingProfile::create([
            'agent_profile_id' => $agent->id,
            'provider_id' => $provider->id,
            'provider_account_id' => $providerAccount->id,
            'account_type' => 'personal_dedicated_pos',
            'file_type' => 'pdf',
            'schema_fingerprint' => $schema['fingerprint'],
            'match_identifier_fingerprint' => $accountFingerprint,
            'column_mapping' => [
                'external_reference' => 'Transaction Reference',
                'amount' => 'Amount',
                'transaction_at' => 'Date',
                'transaction_status' => 'Status',
                'transaction_type' => 'Transaction Type',
                'provider_account_identifier' => 'Provider Account Identifier',
                '_pos_activity_patterns' => [$posPatternId],
                '_reviewed_activity_patterns' => [$posPatternId, $excludedPatternId],
            ],
            'known_pos_transaction_patterns' => [$posPatternId],
            'excluded_patterns' => [$excludedPatternId],
            'confidence' => 'owner_confirmed',
            'classification_status' => 'reviewed',
            'status' => 'active',
        ]);

        $messageId = 'future-personal-pos-statement';
        $attachmentId = 'future-personal-pos-attachment';
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
        $this->assertSame('processed', $message->status);
        $this->assertSame($profile->id, $message->statement_mapping_profile_id);
        $this->assertSame(2, Transaction::where('agent_profile_id', $agent->id)->count());
        $this->assertEqualsCanonicalizing(['FICTIONAL-REF-1', 'AMBIGUOUS-TEST-REF'], Transaction::where('agent_profile_id', $agent->id)->pluck('external_reference')->all());
        $this->assertTrue(Transaction::where('agent_profile_id', $agent->id)->get()->every(fn (Transaction $transaction): bool => $transaction->terminal_id === null));
        $this->assertSame(2, Transaction::where('provider_account_id', $providerAccount->id)->count());
        $this->assertSame('personal_dedicated_pos', $providerAccount->fresh()->account_type);
        $this->assertFalse(Transaction::where('agent_profile_id', $agent->id)->where('external_reference', 'WALLET-TEST-REF')->exists());
        $this->assertFalse(Transaction::where('agent_profile_id', $agent->id)->where('external_reference', 'PERSONAL-EXCLUDED-REF')->exists());
    }

    public function test_personal_dedicated_account_does_not_auto_classify_unreviewed_mixed_activity(): void
    {
        config()->set('gmail_statement.enabled', true);
        Storage::fake('local');
        $user = User::factory()->create(['email_verified_at' => now()]);
        $agent = AgentProfile::factory()->create(['user_id' => $user->id, 'selected_provider_slugs' => ['opay']]);
        $provider = Provider::factory()->create(['slug' => 'opay', 'name' => 'OPay']);
        $connection = $this->createGmailConnection($agent, ['provider_rules' => ['opay' => ['account_type' => 'personal_dedicated_pos']]]);
        $patternId = hash('sha256', 'ambiguous|transfer-in');
        $csv = "Transaction Reference,Amount,Date,Status,Transaction Type,Activity Classification,Activity Pattern\nAMBIGUOUS-REF-1,2500.00,2026-09-28 09:00:00,successful,pos_credit,ambiguous,{$patternId}\n";
        $message = GmailStatementMessage::create([
            'gmail_connection_id' => $connection->id,
            'agent_profile_id' => $agent->id,
            'provider_id' => $provider->id,
            'dedupe_fingerprint' => hash('sha256', 'personal-account-needs-review'),
            'gmail_message_id' => 'encrypted-personal-account-message',
            'gmail_attachment_id' => 'encrypted-personal-account-attachment',
            'file_name' => 'statement.csv',
            'file_type' => 'csv',
            'headers' => ['Transaction Reference', 'Amount', 'Date', 'Status', 'Transaction Type', 'Activity Classification', 'Activity Pattern'],
            'activity_summary' => ['pos' => 0, 'wallet' => 0, 'ambiguous' => 1, 'patterns' => [['id' => $patternId, 'label' => 'Money in · transfer', 'rows' => 1, 'total_amount' => '2500.00', 'selectable' => true]]],
            'status' => 'needs_setup',
        ]);
        app(GmailStatementImportService::class)->retainForMapping($message, $csv);
        $payload = [
            'account_type' => 'personal_dedicated_pos',
            'column_mapping' => ['external_reference' => 'Transaction Reference', 'amount' => 'Amount', 'transaction_at' => 'Date', 'transaction_status' => 'Status', 'transaction_type' => 'Transaction Type'],
        ];

        $this->actingAs($user)->postJson('/api/gmail/statements/'.$message->id.'/mapping', [
            ...$payload,
            'activity_classifications' => [$patternId => 'needs_review'],
        ])->assertUnprocessable();
        $this->assertSame(0, Transaction::where('agent_profile_id', $agent->id)->count());
        $this->assertSame('needs_setup', $message->fresh()->status);

        $this->actingAs($user)->postJson('/api/gmail/statements/'.$message->id.'/mapping', [
            ...$payload,
            'activity_classifications' => [$patternId => 'personal_excluded'],
        ])->assertOk()->assertJsonPath('rows_imported', 0);
        $this->assertSame(0, Transaction::where('agent_profile_id', $agent->id)->count());
        $this->assertSame('personal_dedicated_pos', $message->fresh()->mappingProfile->account_type);
        $this->assertContains($patternId, $message->fresh()->mappingProfile->excluded_patterns);
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

    private function opayPositionedPdf(string $description = 'POS purchase', string $channel = 'POS', array $additionalRows = []): string
    {
        $isWithdrawal = str_contains(strtolower($description), 'withdraw');
        $elements = [
            [80, 740, 'Account Number'], [180, 740, '0123456789'],
            [50, 700, 'Trans. Time'], [120, 700, 'Value Date'], [185, 700, 'Description'], [250, 700, 'Debit(₦)'],
            [300, 700, 'Credit(₦)'], [340, 700, 'Balance After'], [390, 700, 'Channel'], [455, 700, 'Transaction Reference'],
            [50, 680, '09:15:00 AM'], [120, 680, '09/28/2026'], [185, 680, $description], [250, 680, $isWithdrawal ? '5,000.00' : '-'],
            [300, 680, $isWithdrawal ? '-' : '5,000.00'], [340, 680, '17,000.00'], [390, 680, $channel], [455, 680, 'FICTIONAL-REF-1'],
        ];
        foreach ($additionalRows as $index => [$rowDescription, $rowChannel, $reference, $debit, $credit]) {
            $y = 660 - ($index * 20);
            $elements = [...$elements,
                [50, $y, '09:15:00 AM'], [120, $y, '09/28/2026'], [185, $y, $rowDescription], [250, $y, $debit],
                [300, $y, $credit], [340, $y, '17,000.00'], [390, $y, $rowChannel], [455, $y, $reference],
            ];
        }
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
