<?php

namespace Database\Factories;

use App\Models\ShmAgent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShmAgent>
 */
class ShmAgentFactory extends Factory
{
    protected $model = ShmAgent::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        static $number = 1;

        return [
            'code' => 'AGEN-'.str_pad((string) $number++, 3, '0', STR_PAD_LEFT),
            'name' => fake()->name(),
            'nik' => fake()->numerify('350#############'),
            'is_active' => true,
        ];
    }

    /**
     * State untuk agen nonaktif.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }
}
