<?php

namespace Database\Factories;

use App\Models\ShmCustomer;
use App\Models\ShmLoan;
use App\Models\ShmLoanProduct;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShmLoan>
 */
class ShmLoanFactory extends Factory
{
    protected $model = ShmLoan::class;

    /**
     * Define the model's default state.
     *
     * Rate default = 2% (rate resmi bunga/jasa SHM).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        static $number = 1;

        return [
            'loan_number' => 'SHM-PIN-'.str_pad((string) $number++, 6, '0', STR_PAD_LEFT),
            'shm_customer_id' => ShmCustomer::factory(),
            'shm_loan_product_id' => ShmLoanProduct::factory(),
            'principal' => fake()->numberBetween(10, 500) * 1_000_000,
            'tenor_months' => fake()->numberBetween(12, 60),
            'rate_percent' => ShmLoan::OFFICIAL_RATE_PERCENT,
            'rate_period' => null,
            'calculation_method' => null,
            'location' => fake()->city(),
            'purpose' => fake()->sentence(4),
            'status' => ShmLoan::STATUS_ACTIVE,
            'disbursed_at' => now()->subMonths(2)->toDateString(),
            'note' => null,
        ];
    }

    /**
     * State untuk pinjaman yang sudah ditutup.
     */
    public function closed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ShmLoan::STATUS_CLOSED,
        ]);
    }
}
