<?php

namespace App\Services;

use App\Contracts\GmailCredentialStore;
use App\Models\GmailConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class GoogleGmailClient
{
    public function __construct(private readonly GmailCredentialStore $credentials) {}

    /** @return list<array{id:string}> */
    public function search(GmailConnection $connection, string $query): array
    {
        $messages = [];
        $pageToken = null;
        do {
            $response = $this->request($connection)->get('https://gmail.googleapis.com/gmail/v1/users/me/messages', array_filter([
                'q' => $query,
                'maxResults' => 100,
                'pageToken' => $pageToken,
            ], fn (mixed $value): bool => $value !== null));
            if (! $response->successful()) {
                throw new RuntimeException('Gmail search failed.');
            }
            foreach ((array) $response->json('messages', []) as $message) {
                if (is_string($message['id'] ?? null)) {
                    $messages[] = ['id' => $message['id']];
                }
            }
            $pageToken = $response->json('nextPageToken');
        } while (is_string($pageToken) && $pageToken !== '' && count($messages) < 200);

        return $messages;
    }

    /** @return array<string, mixed> */
    public function message(GmailConnection $connection, string $messageId): array
    {
        $response = $this->request($connection)->get("https://gmail.googleapis.com/gmail/v1/users/me/messages/{$messageId}", ['format' => 'full']);
        if (! $response->successful()) {
            throw new RuntimeException('Gmail message could not be read.');
        }

        return (array) $response->json();
    }

    /** @return array<string, mixed> */
    public function messageMetadata(GmailConnection $connection, string $messageId): array
    {
        $response = $this->request($connection)->get("https://gmail.googleapis.com/gmail/v1/users/me/messages/{$messageId}", [
            'format' => 'metadata',
            'metadataHeaders' => ['From', 'Subject', 'Date'],
        ]);
        if (! $response->successful()) {
            throw new RuntimeException('Gmail message metadata could not be read.');
        }

        return (array) $response->json();
    }

    public function attachment(GmailConnection $connection, string $messageId, string $attachmentId): string
    {
        $response = $this->request($connection)->get("https://gmail.googleapis.com/gmail/v1/users/me/messages/{$messageId}/attachments/{$attachmentId}");
        if (! $response->successful()) {
            throw new RuntimeException('Gmail attachment could not be read.');
        }
        $encoded = (string) $response->json('data', '');
        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);
        if (! is_string($decoded)) {
            throw new RuntimeException('Gmail attachment data is invalid.');
        }

        return $decoded;
    }

    public function emailAddressForAccessToken(string $accessToken): string
    {
        $response = Http::acceptJson()->withToken($accessToken)->connectTimeout(3)->timeout(10)
            ->get('https://gmail.googleapis.com/gmail/v1/users/me/profile');
        $emailAddress = $response->json('emailAddress');

        if (! $response->successful() || ! is_string($emailAddress) || ! filter_var($emailAddress, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Gmail profile could not be verified.');
        }

        return strtolower($emailAddress);
    }

    private function request(GmailConnection $connection): PendingRequest
    {
        $tokens = $this->credentials->retrieve($connection);
        if ($tokens['token_expires_at']?->lessThanOrEqualTo(now()->addMinute())) {
            $refreshToken = $tokens['refresh_token'];
            if (! is_string($refreshToken) || $refreshToken === '') {
                $connection->update(['status' => 'permission_expired', 'last_error_code' => 'permission_expired']);
                throw new RuntimeException('Gmail access needs to be reconnected.');
            }
            $response = Http::asForm()->acceptJson()->connectTimeout(3)->timeout(10)->post('https://oauth2.googleapis.com/token', [
                'client_id' => config('gmail_statement.client_id'),
                'client_secret' => config('gmail_statement.client_secret'),
                'refresh_token' => $refreshToken,
                'grant_type' => 'refresh_token',
            ]);
            $refreshed = $response->json();
            if (! $response->successful() || ! is_string($refreshed['access_token'] ?? null)) {
                $connection->update(['status' => 'permission_expired', 'last_error_code' => 'permission_expired']);
                throw new RuntimeException('Gmail access needs to be reconnected.');
            }
            $this->credentials->store($connection, [
                'access_token' => $refreshed['access_token'],
                'refresh_token' => $refreshToken,
                'expires_in' => (int) ($refreshed['expires_in'] ?? 3600),
            ]);
            $tokens['access_token'] = $refreshed['access_token'];
        }

        return Http::acceptJson()->withToken($tokens['access_token'])->connectTimeout(3)->timeout(20);
    }
}
