<?php

namespace App\Imports;

final class GenericSettlementCsvImporter extends AbstractSettlementCsvImporter
{
    public function __construct(private readonly array $mapping = []) {}

    protected function aliases(): array
    {
        $map = $this->mapping;

        return ['external_reference' => [$map['settlement_reference'] ?? 'settlement_reference'], 'terminal_identifier' => [$map['terminal_identifier'] ?? 'terminal_identifier'], 'settlement_date' => [$map['settlement_date'] ?? 'settlement_date'], 'gross_transaction_amount' => [$map['gross_transaction_amount'] ?? 'gross_transaction_amount'], 'provider_fee' => [$map['provider_fee'] ?? 'provider_fee'], 'expected_amount' => [$map['expected_amount'] ?? 'expected_amount'], 'actual_amount' => [$map['actual_amount'] ?? 'actual_amount'], 'status' => [$map['status'] ?? 'status']];
    }
}
