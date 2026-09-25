<?php

namespace App\Imports;

final class PalmpaySettlementCsvImporter extends AbstractSettlementCsvImporter
{
    protected function aliases(): array
    {
        return ['external_reference' => ['settlement_reference', 'settlement_id', 'batch_reference', 'reference'], 'terminal_identifier' => ['terminal_id', 'terminal_identifier', 'pos_id'], 'settlement_date' => ['settlement_date', 'settlement_date_time', 'date'], 'gross_transaction_amount' => ['gross_transaction_amount', 'gross_amount', 'total_amount'], 'provider_fee' => ['provider_fee', 'service_fee', 'fee'], 'expected_amount' => ['expected_settlement', 'expected_amount', 'net_amount'], 'actual_amount' => ['actual_settlement', 'actual_amount', 'received_amount'], 'status' => ['status', 'settlement_status']];
    }
}
