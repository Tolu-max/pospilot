<?php

namespace Database\Factories;

use App\Models\AgentProfile;
use App\Models\Expense;
use Illuminate\Database\Eloquent\Factories\Factory;

class ExpenseFactory extends Factory
{
    protected $model = Expense::class;

    public function definition(): array
    {
        return ['agent_profile_id' => AgentProfile::factory(), 'amount' => 500, 'category' => 'data', 'description' => null, 'expense_date' => today()];
    }
}
