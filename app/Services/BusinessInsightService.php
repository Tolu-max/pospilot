<?php

namespace App\Services;

use App\Enums\DailyClosingStatus;
use App\Models\AgentProfile;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class BusinessInsightService
{
    public function __construct(private readonly AgentFinancialSummaryService $summaryService) {}

    public function explainToday(AgentProfile $agent): ?string
    {
        if (! config('services.cencori.enabled')) {
            return null;
        }

        $apiKey = config('services.cencori.api_key');
        if (! is_string($apiKey) || trim($apiKey) === '') {
            return null;
        }

        $summary = $this->summaryService->today($agent);
        $providerFeesKnown = $summary['is_final'] === true;
        $closingVariance = $agent->dailyClosings()
            ->whereDate('closing_date', today())
            ->where('status', DailyClosingStatus::Finalized->value)
            ->value('total_variance');
        $aggregate = [
            'successful_transaction_count' => $summary['successful_transaction_count'],
            'transaction_volume' => $summary['transaction_volume'],
            'customer_charges' => $summary['customer_charges_collected'],
            'provider_fees' => $providerFeesKnown ? $summary['provider_fees'] : null,
            'provider_fees_known' => $providerFeesKnown,
            'estimated_earnings' => $summary['estimated_net_earnings'],
            'earnings_provisional' => ! $summary['is_final'],
            'expenses_total' => $summary['expenses'],
            'reconciliation_issue_count' => $summary['reconciliation_issue_count'],
            'closing_variance' => $closingVariance === null ? null : (string) $closingVariance,
        ];
        $baseUrl = rtrim((string) config('services.cencori.base_url'), '/');

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->withToken($apiKey)
                ->timeout((int) config('services.cencori.timeout', 20))
                ->post($baseUrl.'/chat/completions', [
                    'model' => (string) config('services.cencori.model', 'gpt-4o'),
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'Explain POS business day summaries to a Nigerian POS agent in plain language using at most 3 short sentences. POSPilot has already calculated every supplied value: never calculate, alter, estimate, or invent numbers. Refer only to supplied values. If provider fees are unknown or earnings_provisional is true, explicitly say earnings are provisional and fees are incomplete. If a value is null, say it was not recorded; never treat it as zero. Give one practical next step only when the supplied summary supports it.',
                        ],
                        [
                            'role' => 'user',
                            'content' => json_encode($aggregate, JSON_THROW_ON_ERROR),
                        ],
                    ],
                    'temperature' => 0.2,
                    'max_tokens' => 140,
                ]);

            if (! $response->successful()) {
                Log::warning('Cencori business insight request failed.', ['status' => $response->status()]);

                return null;
            }

            $content = $response->json('choices.0.message.content');
            if (! is_string($content) || trim($content) === '') {
                Log::warning('Cencori business insight returned an empty response.');

                return null;
            }

            return mb_substr(trim($content), 0, 800);
        } catch (ConnectionException) {
            Log::warning('Cencori business insight connection failed.');

            return null;
        } catch (Throwable) {
            Log::warning('Cencori business insight could not be completed.');

            return null;
        }
    }
}
