<?php

namespace Database\Factories;

use App\Models\Member;
use App\Models\SavingProduct;
use App\Models\SavingsAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavingsAccount>
 */
class SavingsAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'account_number' => 'REK-'.fake()->unique()->numerify('########'),
            'member_id' => Member::factory(),
            'saving_product_id' => SavingProduct::factory(),
            'opened_at' => fake()->dateTimeBetween('-2 years', 'now')->format('Y-m-d'),
            'status' => 'active',
            'balance' => 0,
        ];
    }

    public function withBalance(int $amount): static
    {
        return $this->state(['balance' => $amount]);
    }

    public function closed(): static
    {
        return $this->state(['status' => 'closed']);
    }
}
