<?php

namespace App\Data;

use App\Enums\SettlementStatus;
use App\Enums\TransactionSource;
use App\Models\Provider;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class NormalizedSettlementData
{
    public function __construct(
        public Provider $provider,
        public ?string $externalReference,
        public ?string $terminalIdentifier,
        public CarbonImmutable $settlementDate,
        public ?string $grossTransactionAmount,
        public string $providerFee,
        public ?string $expectedAmount,
        public ?string $actualAmount,
        public SettlementStatus $status,
        public TransactionSource $source,
        public array $metadata = [],
    ) {}

    public static function fromArray(Provider $provider, array $data): self
    {
        $date = $data['settlement_date'] ?? $data['settled_at'] ?? $data['date'] ?? null;
        if ($date === null || $date === '') {
            throw new InvalidArgumentException('Normalized settlement settlement date is required.');
        }

        $expected = self::decimalOrNull($data['expected_amount'] ?? null, 'expected amount');
        $actual = self::decimalOrNull($data['actual_amount'] ?? null, 'actual amount');
        $gross = self::decimalOrNull($data['gross_transaction_amount'] ?? $data['gross_amount'] ?? null, 'gross transaction amount');
        $fee = self::decimalOrNull($data['provider_fee'] ?? '0.00', 'provider fee') ?? '0.00';
        if ($expected === null && $actual === null) {
            throw new InvalidArgumentException('Normalized settlement requires expected or actual amount.');
        }

        $status = $data['status'] ?? SettlementStatus::Pending->value;
        $source = $data['source'] ?? TransactionSource::Manual->value;

        return new self(
            provider: $provider,
            externalReference: self::nullableString($data['external_reference'] ?? $data['settlement_reference'] ?? null),
            terminalIdentifier: self::nullableString($data['terminal_identifier'] ?? null),
            settlementDate: $date instanceof CarbonImmutable ? $date : CarbonImmutable::parse((string) $date),
            grossTransactionAmount: $gross,
            providerFee: $fee,
            expectedAmount: $expected,
            actualAmount: $actual,
            status: $status instanceof SettlementStatus ? $status : SettlementStatus::from((string) $status),
            source: $source instanceof TransactionSource ? $source : TransactionSource::from((string) $source),
            metadata: (array) ($data['metadata'] ?? []),
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null || trim((string) $value) === '' ? null : trim((string) $value);
    }

    private static function decimalOrNull(mixed $value, string $label): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        try {
            return BigDecimal::of(str_replace([',', '₦', 'NGN', 'ngn', ' '], '', (string) $value))->toScale(2, RoundingMode::HalfUp)->__toString();
        } catch (\Throwable) {
            throw new InvalidArgumentException("Normalized settlement {$label} must be a decimal value.");
        }
    }
}
