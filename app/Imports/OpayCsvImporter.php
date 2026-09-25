<?php

namespace App\Imports;

final class OpayCsvImporter extends AbstractTransactionCsvImporter
{
    protected function aliases(): array
    {
        return ['external_reference' => ['transaction_id', 'transaction_reference', 'reference', 'order_no'], 'amount' => ['amount', 'transaction_amount', 'transaction_value'], 'customer_charge' => ['customer_charge', 'service_charge', 'customer_fee', 'agent_fee'], 'provider_fee' => ['provider_fee', 'fee', 'transaction_fee'], 'transaction_status' => ['status', 'transaction_status', 'transaction_state'], 'transaction_at' => ['transaction_time', 'transaction_date', 'date', 'created_at'], 'terminal_identifier' => ['terminal_id', 'terminal_identifier', 'pos_id'], 'transaction_type' => ['transaction_type', 'type']];
    }
}
