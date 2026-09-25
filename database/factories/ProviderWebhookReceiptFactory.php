<?php

namespace Database\Factories;

use App\Models\ProviderConnection;
use App\Models\ProviderWebhookReceipt;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProviderWebhookReceipt>
 */
class ProviderWebhookReceiptFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider_connection_id' => ProviderConnection::factory(),
            'event_id' => fake()->uuid(),
            'transaction_id' => Transaction::factory(),
            'status' => 'processed',
        ];
    }
}
