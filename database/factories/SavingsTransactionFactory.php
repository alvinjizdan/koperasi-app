<?php

namespace Database\Factories;

use App\Models\SavingsAccount;
use App\Models\SavingsTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavingsTransaction>
 */
class SavingsTransactionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'transaction_number' => 'TRX-'.fake()->unique()->numerify('##########'),
            'savings_account_id' => SavingsAccount::factory(),
            'type' => 'deposit',
            'amount' => fake()->numberBetween(10000, 1000000),
            'transaction_date' => now()->format('Y-m-d'),
            'description' => fake()->optional()->sentence(),
            'processed_by' => User::factory(),
            'status' => 'completed',
            'idempotency_key' => null,
        ];
    }

    public function withdrawal(): static
    {
        return $this->state(['type' => 'withdrawal']);
    }
}
