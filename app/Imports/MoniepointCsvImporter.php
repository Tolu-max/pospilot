<?php

namespace App\Imports;

final class MoniepointCsvImporter extends AbstractTransactionCsvImporter
{
    protected function aliases(): array
    {
        return ['external_reference' => ['transaction_reference', 'transaction_id', 'reference', 'session_id'], 'amount' => ['amount', 'transaction_amount', 'value'], 'customer_charge' => ['customer_charge', 'service_charge', 'agent_charge'], 'provider_fee' => ['provider_fee', 'fee', 'commission'], 'transaction_status' => ['status', 'transaction_status', 'state'], 'transaction_at' => ['transaction_date', 'transaction_time', 'date', 'created_at'], 'terminal_identifier' => ['terminal', 'terminal_id', 'terminal_identifier'], 'transaction_type' => ['transaction_type', 'type']];
    }
}
