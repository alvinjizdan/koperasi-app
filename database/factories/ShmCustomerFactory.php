<?php

namespace Database\Factories;

use App\Models\ShmCustomer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShmCustomer>
 */
class ShmCustomerFactory extends Factory
{
    protected $model = ShmCustomer::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        static $number = 1;

        return [
            'customer_number' => 'NSB-'.str_pad((string) $number++, 6, '0', STR_PAD_LEFT),
            'name' => fake()->name(),
            'nik' => fake()->numerify('350#############'),
            'phone' => fake()->phoneNumber(),
            'email' => fake()->safeEmail(),
            'address' => fake()->address(),
            'is_active' => true,
        ];
    }

    /**
     * State untuk nasabah nonaktif.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
