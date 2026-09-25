<?php

namespace App\Data;

use App\Enums\TransactionAdjustmentDirection;
use App\Enums\TransactionAdjustmentSource;
use App\Enums\TransactionAdjustmentType;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

final readonly class NormalizedTransactionAdjustmentData
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public TransactionAdjustmentType $type,
        public string $amount,
        public TransactionAdjustmentDirection $direction,
        public TransactionAdjustmentSource $source,
        public ?string $providerComponentCode = null,
        public ?string $calculationRule = null,
        public array $metadata = [],
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        foreach (['type', 'amount', 'direction', 'source'] as $key) {
            if (! array_key_exists($key, $data)) {
                throw new InvalidArgumentException("Normalized transaction adjustment {$key} is required.");
            }
        }

        try {
            $type = $data['type'] instanceof TransactionAdjustmentType ? $data['type'] : TransactionAdjustmentType::from((string) $data['type']);
            $direction = $data['direction'] instanceof TransactionAdjustmentDirection ? $data['direction'] : TransactionAdjustmentDirection::from((string) $data['direction']);
            $source = $data['source'] instanceof TransactionAdjustmentSource ? $data['source'] : TransactionAdjustmentSource::from((string) $data['source']);
            $amount = BigDecimal::of((string) $data['amount']);
            $amount = $amount->toScale(2, RoundingMode::Unnecessary);
        } catch (\Throwable) {
            throw new InvalidArgumentException('Normalized transaction adjustment has an invalid type, amount, direction, or source.');
        }

        if ($amount->compareTo(0) < 0) {
            throw new InvalidArgumentException('Normalized transaction adjustment amount cannot be negative; provide its direction separately.');
        }

        if ($type === TransactionAdjustmentType::CustomerCharge && $direction !== TransactionAdjustmentDirection::Credit) {
            throw new InvalidArgumentException('Customer charge adjustments must be credits.');
        }

        if (in_array($type, [TransactionAdjustmentType::ProviderFee, TransactionAdjustmentType::VatTax, TransactionAdjustmentType::Levy, TransactionAdjustmentType::SettlementFee], true)
            && $direction !== TransactionAdjustmentDirection::Debit) {
            throw new InvalidArgumentException('Provider fee, tax, levy, and settlement fee adjustments must be debits.');
        }

        $calculationRule = self::nullableString($data['calculation_rule'] ?? null);

        if ($source === TransactionAdjustmentSource::Calculated && $calculationRule === null) {
            throw new InvalidArgumentException('Calculated transaction adjustments require a documented calculation rule.');
        }

        return new self(
            type: $type,
            amount: $amount->__toString(),
            direction: $direction,
            source: $source,
            providerComponentCode: self::nullableString($data['provider_component_code'] ?? null),
            calculationRule: $calculationRule,
            metadata: (array) ($data['metadata'] ?? []),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'amount' => $this->amount,
            'direction' => $this->direction->value,
            'source' => $this->source->value,
            'provider_component_code' => $this->providerComponentCode,
            'calculation_rule' => $this->calculationRule,
            'metadata' => $this->metadata,
        ];
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null || trim((string) $value) === '' ? null : trim((string) $value);
    }
}
