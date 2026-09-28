<?php

namespace Database\Factories;

use App\Models\SavingProduct;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavingProduct>
 */
class SavingProductFactory extends Factory
{
    public function definition(): array
    {
        static $counter = 0;
        $counter++;

        return [
            'code' => 'SIM-'.str_pad((string) $counter, 3, '0', STR_PAD_LEFT).'-'.fake()->unique()->lexify('????'),
            'name' => 'Simpanan '.fake()->words(2, true),
            'category' => config('business-rules.savings.default_product_category', 'voluntary'),
            'description' => fake()->optional()->sentence(),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function category(string $category): static
    {
        return $this->state(['category' => $category]);
    }
}
