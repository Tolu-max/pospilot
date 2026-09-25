<?php

namespace App\Imports;

use App\Models\Provider;

interface TransactionCsvImporter
{
    /** @return array<string, int> */
    public function resolveHeaders(array $headers): array;

    /** @return array<string, mixed> */
    public function normalize(array $headers, array $row, int $rowNumber, ?Provider $provider = null): array;
}
