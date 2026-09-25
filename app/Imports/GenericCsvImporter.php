<?php

namespace App\Imports;

final class GenericCsvImporter extends AbstractTransactionCsvImporter
{
    public function __construct(private readonly array $mapping = []) {}

    protected function aliases(): array
    {
        $fields = ['external_reference', 'amount', 'customer_charge', 'provider_fee', 'transaction_status', 'transaction_at', 'terminal_identifier', 'transaction_type'];

        return array_combine($fields, array_map(fn ($field) => isset($this->mapping[$field]) ? [$this->mapping[$field]] : [], $fields));
    }
}
