<?php

namespace App\Services;

use App\Data\NormalizedTransactionData;
use App\Enums\TransactionSource;
use App\Enums\TransactionStatus;
use App\Models\Provider;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class MoniepointTransactionNormalizer
{
    /** @param array<string, mixed> $payload */
    public function normalize(Provider $provider, array $payload, string $providerEventId): NormalizedTransactionData
    {
        $data = $payload['data'] ?? null;

        if (! is_array($data) || ! isset($payload['eventType'], $data['amount'], $data['transactionTime'], $data['transactionStatus'], $data['transactionType'])) {
            throw new InvalidArgumentException('Moniepoint transaction payload is missing documented transaction fields.');
        }

        $amountInKobo = (string) $data['amount'];

        if (! preg_match('/^\d+$/', $amountInKobo)) {
            throw new InvalidArgumentException('Moniepoint transaction amount must be an integer number of kobo.');
        }

        $amount = BigDecimal::of($amountInKobo)->dividedBy(100, 2, RoundingMode::Unnecessary)->__toString();
        $status = $this->status($data);
        $transactionType = $this->transactionType((string) $payload['eventType']);
        $businessId = $data['businessId'] ?? null;
        $terminalSerial = $data['terminalSerial'] ?? null;
        $reference = $data['transactionReference'] ?? null;

        return NormalizedTransactionData::fromArray($provider, [
            'external_reference' => is_scalar($reference) ? (string) $reference : null,
            'terminal_identifier' => is_scalar($terminalSerial) ? (string) $terminalSerial : null,
            'transaction_type' => $transactionType,
            'amount' => $amount,
            'provider_fee' => '0.00',
            'transaction_status' => $status->value,
            'transaction_at' => CarbonImmutable::parse((string) $data['transactionTime']),
            'source' => TransactionSource::Webhook->value,
            'metadata' => [
                'provider_event_id' => $providerEventId,
                'event_type' => (string) $payload['eventType'],
                'business_id' => is_scalar($businessId) ? (string) $businessId : null,
                'provider_fee_supplied' => false,
            ],
        ]);
    }

    /** @param array<string, mixed> $data */
    private function status(array $data): TransactionStatus
    {
        $transactionStatus = strtoupper((string) $data['transactionStatus']);

        if (in_array($transactionStatus, ['APPROVED', 'COMPLETED'], true)) {
            return TransactionStatus::Successful;
        }

        if ($transactionStatus === 'PENDING') {
            return TransactionStatus::Pending;
        }

        $responseCode = (string) ($data['responseCode'] ?? '');

        return match ($responseCode) {
            '00' => TransactionStatus::Successful,
            '09' => TransactionStatus::Pending,
            default => throw new InvalidArgumentException('Moniepoint transaction status is not documented for normalization.'),
        };
    }

    private function transactionType(string $eventType): string
    {
        return match ($eventType) {
            'V1_POS_WITHDRAWAL_TRANSACTION' => 'withdrawal',
            'V1_POS_PURCHASE_TRANSACTION', 'V1_POS_BILL_PAYMENT_TRANSACTION', 'V1_POS_AIRTIME_TRANSACTION', 'V1_POS_BOOM_TRANSACTION', 'V1_POS_PAY_CODE_TRANSACTION' => 'payment',
            'V1_POS_TRANSFER_TRANSACTION', 'V1_POS_CARD_TRANSFER_TRANSACTION', 'V1_TRANSFER_TRANSACTION' => 'transfer',
            'V1_POS_COLLECTION_TRANSACTION' => 'deposit',
            default => throw new InvalidArgumentException('Moniepoint webhook event type is not documented for transaction ingestion.'),
        };
    }
}
