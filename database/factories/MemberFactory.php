<?php

namespace Database\Factories;

use App\Models\Member;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Member>
 */
class MemberFactory extends Factory
{
    protected $model = Member::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        static $number = 1001;

        return [
            'member_number' => 'ANG-'.($number++),
            'full_name' => fake()->name(),
            'identity_number' => fake()->numerify('350#############'),
            'phone' => fake()->phoneNumber(),
            'address' => fake()->address(),
            'joined_at' => fake()->date('Y-m-d'),
            'status' => 'active',
        ];
    }

    /**
     * State untuk anggota nonaktif.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'inactive',
        ]);
    }
}
