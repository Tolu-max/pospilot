<?php

namespace App\Imports;

final class OpaySettlementCsvImporter extends AbstractSettlementCsvImporter
{
    protected function aliases(): array
    {
        return ['external_reference' => ['settlement_reference', 'settlement_id', 'batch_id', 'reference'], 'terminal_identifier' => ['terminal_id', 'terminal_identifier', 'pos_id'], 'settlement_date' => ['settlement_date', 'settled_at', 'date'], 'gross_transaction_amount' => ['gross_transaction_amount', 'gross_amount', 'transaction_amount'], 'provider_fee' => ['provider_fee', 'fee', 'transaction_fee'], 'expected_amount' => ['expected_amount', 'expected_settlement', 'net_amount'], 'actual_amount' => ['actual_amount', 'actual_settlement', 'settlement_amount', 'received_amount', 'amount'], 'status' => ['status', 'settlement_status']];
    }
}
