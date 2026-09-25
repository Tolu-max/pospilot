<?php

namespace App\Data;

use App\Enums\CustomerChargeSource;
use App\Enums\SettlementStatus;
use App\Enums\TransactionSource;
use App\Enums\TransactionStatus;
use App\Models\Provider;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class NormalizedTransactionData
{
    public function __construct(
        public Provider $provider,
        public ?string $externalReference,
        public ?string $terminalIdentifier,
        public string $transactionType,
        public string $amount,
        public string $providerFee,
        public ?string $customerCharge,
        public TransactionStatus $status,
        public CarbonImmutable $transactionAt,
        public TransactionSource $source,
        public ?SettlementStatus $settlementStatus = null,
        public ?CarbonImmutable $settledAt = null,
        public CustomerChargeSource|string|null $customerChargeSource = null,
        public array $metadata = [],
        /** @var list<NormalizedTransactionAdjustmentData> */
        public array $adjustments = [],
        public bool $providerFeeComponentsComplete = false,
        public bool $providerFeeSupplied = true,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(Provider $provider, array $data): self
    {
        foreach (['amount', 'transaction_at'] as $required) {
            if (! isset($data[$required]) || $data[$required] === '') {
                throw new InvalidArgumentException("Normalized transaction {$required} is required.");
            }
        }
        $status = $data['transaction_status'] ?? $data['status'] ?? null;
        if (! $status) {
            throw new InvalidArgumentException('Normalized transaction status is required.');
        }
        $source = $data['source'] ?? TransactionSource::Manual->value;
        $transactionSource = $source instanceof TransactionSource ? $source : TransactionSource::from((string) $source);
        $chargeSource = $data['customer_charge_source'] ?? null;
        $amount = (string) $data['amount'];
        $providerFee = (string) ($data['provider_fee'] ?? $data['providerFee'] ?? '0.00');
        $customerCharge = isset($data['customer_charge']) || array_key_exists('customerCharge', $data) ? (($data['customer_charge'] ?? $data['customerCharge']) === null ? null : (string) ($data['customer_charge'] ?? $data['customerCharge'])) : null;
        self::validateDecimal($amount, 'amount', true);
        self::validateDecimal($providerFee, 'provider fee');
        if ($customerCharge !== null) {
            self::validateDecimal($customerCharge, 'customer charge');
            if (BigDecimal::of($customerCharge)->compareTo(0) < 0) {
                throw new InvalidArgumentException('Normalized customer charge cannot be negative.');
            }
        }
        $adjustments = array_map(function (mixed $adjustment): NormalizedTransactionAdjustmentData {
            if (! is_array($adjustment)) {
                throw new InvalidArgumentException('Normalized transaction adjustments must be objects.');
            }

            return NormalizedTransactionAdjustmentData::fromArray($adjustment);
        }, (array) ($data['adjustments'] ?? []));

        if (collect($adjustments)->contains(fn (NormalizedTransactionAdjustmentData $adjustment): bool => $adjustment->source->value === 'provider')
            && ! in_array($transactionSource, [TransactionSource::Csv, TransactionSource::Api, TransactionSource::Webhook], true)) {
            throw new InvalidArgumentException('Provider-reported transaction adjustments require a provider import, API, or verified webhook source.');
        }
        $componentsComplete = $data['provider_fee_components_complete'] ?? false;

        if (! is_bool($componentsComplete)) {
            throw new InvalidArgumentException('Normalized provider fee component completeness must be boolean.');
        }

        $metadata = (array) ($data['metadata'] ?? []);
        $suppliedProviderFee = $data['provider_fee'] ?? $data['providerFee'] ?? null;
        $providerFeeSupplied = $data['provider_fee_supplied']
            ?? ($metadata['provider_fee_supplied'] ?? ($suppliedProviderFee !== null && $suppliedProviderFee !== '' || $componentsComplete));

        if (! is_bool($providerFeeSupplied)) {
            throw new InvalidArgumentException('Normalized provider fee availability must be boolean.');
        }

        if ($componentsComplete
            && BigDecimal::of($providerFee)->compareTo(0) > 0
            && collect($adjustments)->every(fn (NormalizedTransactionAdjustmentData $adjustment): bool => $adjustment->direction->value !== 'debit')) {
            throw new InvalidArgumentException('A complete provider fee component breakdown must account for the reported legacy provider fee.');
        }

        $customerChargeComponentRows = collect($adjustments)
            ->filter(fn (NormalizedTransactionAdjustmentData $adjustment): bool => $adjustment->type->value === 'customer_charge');
        $customerChargeComponents = $customerChargeComponentRows
            ->reduce(fn (string $total, NormalizedTransactionAdjustmentData $adjustment): string => Money::add($total, $adjustment->amount), '0.00');

        if ($customerCharge !== null && $customerChargeComponentRows->isNotEmpty() && Money::compare($customerCharge, $customerChargeComponents) !== 0) {
            throw new InvalidArgumentException('The normalized customer charge does not match its component breakdown.');
        }

        return new self(
            provider: $provider,
            externalReference: self::nullableString($data['external_reference'] ?? $data['externalReference'] ?? null),
            terminalIdentifier: self::nullableString($data['terminal_identifier'] ?? $data['terminalIdentifier'] ?? null),
            transactionType: (string) ($data['transaction_type'] ?? $data['transactionType'] ?? 'transfer'),
            amount: $amount,
            providerFee: $providerFee,
            customerCharge: $customerCharge,
            status: $status instanceof TransactionStatus ? $status : TransactionStatus::from((string) $status),
            transactionAt: $data['transaction_at'] instanceof CarbonImmutable ? $data['transaction_at'] : CarbonImmutable::parse((string) $data['transaction_at']),
            source: $transactionSource,
            settlementStatus: isset($data['settlement_status']) ? ($data['settlement_status'] instanceof SettlementStatus ? $data['settlement_status'] : SettlementStatus::from((string) $data['settlement_status'])) : null,
            settledAt: ! empty($data['settled_at']) ? CarbonImmutable::parse((string) $data['settled_at']) : null,
            customerChargeSource: $chargeSource instanceof CustomerChargeSource || $chargeSource === null ? $chargeSource : CustomerChargeSource::from((string) $chargeSource),
            metadata: $metadata,
            adjustments: $adjustments,
            providerFeeComponentsComplete: $componentsComplete,
            providerFeeSupplied: $providerFeeSupplied,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['external_reference' => $this->externalReference, 'terminal_identifier' => $this->terminalIdentifier, 'transaction_type' => $this->transactionType, 'amount' => $this->amount, 'provider_fee' => $this->providerFee, 'provider_fee_supplied' => $this->providerFeeSupplied, 'customer_charge' => $this->customerCharge, 'transaction_status' => $this->status->value, 'transaction_at' => $this->transactionAt->toIso8601String(), 'settlement_status' => $this->settlementStatus?->value, 'settled_at' => $this->settledAt?->toIso8601String(), 'source' => $this->source->value, 'customer_charge_source' => $this->customerChargeSource instanceof CustomerChargeSource ? $this->customerChargeSource->value : $this->customerChargeSource, 'adjustments' => array_map(fn (NormalizedTransactionAdjustmentData $adjustment): array => $adjustment->toArray(), $this->adjustments), 'provider_fee_components_complete' => $this->providerFeeComponentsComplete, 'metadata' => $this->metadata];
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null || trim((string) $value) === '' ? null : trim((string) $value);
    }

    private static function validateDecimal(string $value, string $label, bool $positive = false): void
    {
        try {
            $decimal = BigDecimal::of($value);
        } catch (\Throwable) {
            throw new InvalidArgumentException("Normalized transaction {$label} must be a decimal value.");
        } if ($positive && $decimal->compareTo(0) <= 0) {
            throw new InvalidArgumentException('Normalized transaction amount must be greater than zero.');
        }
    }
}
