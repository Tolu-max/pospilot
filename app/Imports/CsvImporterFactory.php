<?php

namespace App\Imports;

use App\Models\Provider;

final class CsvImporterFactory
{
    public function make(Provider $provider, array $mapping = []): TransactionCsvImporter
    {
        return match ($provider->slug) {
            'opay' => new OpayCsvImporter, 'moniepoint' => new MoniepointCsvImporter, 'palmpay' => new PalmpayCsvImporter, default => new GenericCsvImporter($mapping)
        };
    }
}
