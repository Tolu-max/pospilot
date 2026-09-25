<?php

namespace App\Imports;

use App\Models\Provider;

interface SettlementCsvImporter
{
    public function resolveHeaders(array $headers): array;

    /** @return array{row_number:int,valid:bool,errors:array<int,string>,normalized:array<string,mixed>,normalized_data:object|null} */
    public function normalize(array $headers, array $row, int $rowNumber, ?Provider $provider = null): array;
}
