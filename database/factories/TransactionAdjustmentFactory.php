<?php

namespace Database\Factories;

use App\Enums\TransactionAdjustmentDirection;
use App\Enums\TransactionAdjustmentSource;
use App\Enums\TransactionAdjustmentType;
use App\Models\Transaction;
use App\Models\TransactionAdjustment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TransactionAdjustment>
 */
class TransactionAdjustmentFactory extends Factory
{
    protected $model = TransactionAdjustment::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => TransactionAdjustmentType::ProviderFee,
            'amount' => '10.00',
            'direction' => TransactionAdjustmentDirection::Debit,
            'source' => TransactionAdjustmentSource::Provider,
            'provider_component_code' => null,
            'calculation_rule' => null,
            'metadata' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (TransactionAdjustment $adjustment): void {
            if ($adjustment->transaction_id === null) {
                $adjustment->transaction()->associate(Transaction::factory()->create());
            }
        });
    }
}
