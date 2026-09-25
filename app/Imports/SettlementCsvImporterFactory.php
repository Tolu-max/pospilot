<?php

namespace App\Imports;

use App\Models\Provider;

final class SettlementCsvImporterFactory
{
    public function make(Provider $provider, array $mapping = []): SettlementCsvImporter
    {
        return match ($provider->slug) {
            'opay' => new OpaySettlementCsvImporter, 'moniepoint' => new MoniepointSettlementCsvImporter, 'palmpay' => new PalmpaySettlementCsvImporter, default => new GenericSettlementCsvImporter($mapping)
        };
    }
}
