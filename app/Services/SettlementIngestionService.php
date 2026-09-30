<?php

namespace App\Services;

use App\Data\NormalizedSettlementData;
use App\Models\AgentProfile;
use App\Models\Settlement;
use App\Models\Terminal;
use Illuminate\Database\UniqueConstraintViolationException;

final class SettlementIngestionService
{
    /** @return array{status:string,settlement:?Settlement} */
    public function ingest(AgentProfile $agent, NormalizedSettlementData $data, ?int $importBatchId = null): array
    {
        $fingerprint = $this->fingerprint($data);
        $existing = $this->existing($agent, $data, $fingerprint);
        if ($existing) {
            return ['status' => 'duplicate', 'settlement' => $existing];
        }

        $terminal = $data->terminalIdentifier ? Terminal::firstOrCreate(
            ['agent_profile_id' => $agent->id, 'provider_id' => $data->provider->id, 'terminal_identifier' => $data->terminalIdentifier],
            ['name' => 'Imported terminal '.$data->terminalIdentifier],
        ) : null;

        $attributes = [
            'agent_profile_id' => $agent->id, 'provider_id' => $data->provider->id, 'terminal_id' => $terminal?->id,
            'import_batch_id' => $importBatchId, 'settlement_reference' => $data->externalReference,
            'gross_transaction_amount' => $data->grossTransactionAmount, 'provider_fee' => $data->providerFee ?? '0.00',
            'provider_fee_supplied' => $data->providerFee !== null,
            'expected_amount' => $data->expectedAmount, 'actual_amount' => $data->actualAmount,
            'settlement_date' => $data->settlementDate, 'status' => $data->status->value,
            'reconciliation_outcome' => 'pending', 'source' => $data->source->value,
            'import_fingerprint' => $fingerprint, 'metadata' => $data->metadata,
        ];
        try {
            $settlement = Settlement::create($attributes);
        } catch (UniqueConstraintViolationException) {
            $settlement = $this->existing($agent, $data, $fingerprint);

            return ['status' => 'duplicate', 'settlement' => $settlement];
        }

        return ['status' => 'imported', 'settlement' => $settlement];
    }

    public function fingerprint(NormalizedSettlementData $data): string
    {
        $reference = strtolower(trim((string) $data->externalReference));
        $identity = $reference !== ''
            ? implode('|', [$data->provider->id, 'ref', $reference])
            : implode('|', [$data->provider->id, 'fallback', strtolower(trim((string) $data->terminalIdentifier)), $data->settlementDate->toDateString(), $data->expectedAmount, $data->actualAmount, $data->grossTransactionAmount, $data->providerFee]);

        return hash('sha256', $identity);
    }

    private function existing(AgentProfile $agent, NormalizedSettlementData $data, string $fingerprint): ?Settlement
    {
        return Settlement::where('agent_profile_id', $agent->id)->where('provider_id', $data->provider->id)->where('import_fingerprint', $fingerprint)->first();
    }
}
