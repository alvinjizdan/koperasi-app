<?php

namespace Database\Factories;

use App\Models\ShmLoanProduct;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShmLoanProduct>
 */
class ShmLoanProductFactory extends Factory
{
    protected $model = ShmLoanProduct::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        static $number = 1;

        return [
            'code' => 'SHM-PIN-'.str_pad((string) $number++, 3, '0', STR_PAD_LEFT),
            'name' => 'Pinjaman SHM '.fake()->word(),
            'description' => fake()->sentence(),
            'is_active' => true,
        ];
    }

    /**
     * State untuk produk pinjaman SHM yang nonaktif.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }
}
