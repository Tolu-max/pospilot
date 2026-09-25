<?php

namespace App\Imports;

final class PalmpayCsvImporter extends AbstractTransactionCsvImporter
{
    protected function aliases(): array
    {
        return ['external_reference' => ['transaction_id', 'transaction_reference', 'reference', 'serial_number'], 'amount' => ['amount', 'transaction_amount', 'order_amount'], 'customer_charge' => ['customer_charge', 'service_charge', 'agent_fee'], 'provider_fee' => ['provider_fee', 'fee', 'handling_fee'], 'transaction_status' => ['status', 'transaction_status', 'result'], 'transaction_at' => ['transaction_time', 'transaction_date', 'date', 'created_at'], 'terminal_identifier' => ['terminal_id', 'terminal_identifier', 'device_id'], 'transaction_type' => ['transaction_type', 'type']];
    }
}
