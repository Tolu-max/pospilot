<?php

namespace App\Services;

use App\Contracts\GmailCredentialStore;
use App\Exceptions\GmailCredentialStoreUnavailable;
use App\Models\GmailConnection;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class EncryptedFilesystemGmailCredentialStore implements GmailCredentialStore
{
    public function store(GmailConnection $connection, array $tokens): void
    {
        $accessToken = $tokens['access_token'] ?? null;
        $refreshToken = $tokens['refresh_token'] ?? null;
        $expiresIn = $tokens['expires_in'] ?? null;

        if (! is_string($accessToken) || trim($accessToken) === ''
            || (! is_string($refreshToken) && $refreshToken !== null)
            || ! is_int($expiresIn) || $expiresIn < 1) {
            throw new GmailCredentialStoreUnavailable;
        }

        $path = $this->pathFor($connection);

        $this->withLock($path, LOCK_EX, function (FilesystemAdapter $disk) use ($path, $accessToken, $refreshToken, $expiresIn): void {
            $existing = $this->readCredential($disk, $path, allowMissing: true);
            $refreshToken = is_string($refreshToken) && trim($refreshToken) !== ''
                ? $refreshToken
                : ($existing['refresh_token'] ?? null);

            try {
                $payload = json_encode([
                    'access_token' => $accessToken,
                    'refresh_token' => $refreshToken,
                    'token_expires_at' => now()->addSeconds($expiresIn)->toIso8601String(),
                ], JSON_THROW_ON_ERROR);

                $written = $disk->put($path, Crypt::encryptString($payload), ['visibility' => 'private']);
                if (! $written) {
                    throw new GmailCredentialStoreUnavailable;
                }

                if (! @chmod($disk->path($path), 0600)) {
                    $disk->delete($path);

                    throw new GmailCredentialStoreUnavailable;
                }
            } catch (GmailCredentialStoreUnavailable $exception) {
                throw $exception;
            } catch (Throwable) {
                throw new GmailCredentialStoreUnavailable;
            }
        });
    }

    public function retrieve(GmailConnection $connection): array
    {
        $path = $this->pathFor($connection);

        return $this->withLock($path, LOCK_SH, fn (FilesystemAdapter $disk): array => $this->readCredential($disk, $path));
    }

    public function forget(GmailConnection $connection): void
    {
        $path = $this->pathFor($connection);

        $this->withLock($path, LOCK_EX, function (FilesystemAdapter $disk) use ($path): void {
            try {
                if ($disk->exists($path) && ! $disk->delete($path)) {
                    throw new GmailCredentialStoreUnavailable;
                }
            } catch (GmailCredentialStoreUnavailable $exception) {
                throw $exception;
            } catch (Throwable) {
                throw new GmailCredentialStoreUnavailable;
            }
        });
    }

    private function pathFor(GmailConnection $connection): string
    {
        $ownedConnectionExists = GmailConnection::query()
            ->whereKey($connection->getKey())
            ->where('agent_profile_id', $connection->agent_profile_id)
            ->exists();
        $applicationKey = config('app.key');

        if (! $ownedConnectionExists || ! is_string($applicationKey) || $applicationKey === '') {
            throw new GmailCredentialStoreUnavailable;
        }

        return 'connections/'.hash_hmac('sha256', (string) $connection->getKey(), $applicationKey).'.enc';
    }

    private function readCredential(FilesystemAdapter $disk, string $path, bool $allowMissing = false): array
    {
        try {
            if (! $disk->exists($path)) {
                if ($allowMissing) {
                    return [];
                }

                throw new GmailCredentialStoreUnavailable;
            }

            $payload = json_decode(Crypt::decryptString($disk->get($path)), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($payload)) {
                throw new GmailCredentialStoreUnavailable;
            }

            $accessToken = $payload['access_token'] ?? null;
            $refreshToken = $payload['refresh_token'] ?? null;
            $expiresAt = $payload['token_expires_at'] ?? null;

            if (! is_string($accessToken) || $accessToken === ''
                || (! is_string($refreshToken) && $refreshToken !== null)
                || ! is_string($expiresAt)) {
                throw new GmailCredentialStoreUnavailable;
            }

            return [
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'token_expires_at' => Carbon::parse($expiresAt),
            ];
        } catch (GmailCredentialStoreUnavailable $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new GmailCredentialStoreUnavailable;
        }
    }

    private function withLock(string $path, int $operation, callable $callback): mixed
    {
        try {
            $disk = Storage::disk('gmail_credentials');
            $disk->makeDirectory('connections');
            $absoluteLockPath = $disk->path($path.'.lock');
            $lockHandle = @fopen($absoluteLockPath, 'c+b');

            if ($lockHandle === false) {
                throw new GmailCredentialStoreUnavailable;
            }

            if (! @chmod($absoluteLockPath, 0600)) {
                fclose($lockHandle);

                throw new GmailCredentialStoreUnavailable;
            }
            if (! flock($lockHandle, $operation)) {
                fclose($lockHandle);

                throw new GmailCredentialStoreUnavailable;
            }

            try {
                return $callback($disk);
            } finally {
                flock($lockHandle, LOCK_UN);
                fclose($lockHandle);
            }
        } catch (GmailCredentialStoreUnavailable $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new GmailCredentialStoreUnavailable;
        }
    }
}
