<?php

namespace App\Imports;

final class MoniepointSettlementCsvImporter extends AbstractSettlementCsvImporter
{
    protected function aliases(): array
    {
        return ['external_reference' => ['settlement_reference', 'settlement_batch', 'batch_reference', 'reference'], 'terminal_identifier' => ['terminal', 'terminal_id', 'terminal_identifier'], 'settlement_date' => ['settlement_date', 'settlement_time', 'date'], 'gross_transaction_amount' => ['gross_amount', 'total_transaction_amount', 'transaction_amount'], 'provider_fee' => ['provider_fee', 'fees', 'fee'], 'expected_amount' => ['expected_settlement', 'expected_amount', 'net_settlement'], 'actual_amount' => ['actual_settlement', 'settled_amount', 'amount'], 'status' => ['status', 'settlement_status']];
    }
}
