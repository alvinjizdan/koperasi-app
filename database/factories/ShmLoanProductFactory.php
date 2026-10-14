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
     * Ticket 82 — setiap produk hasil factory otomatis memiliki SATU tier
     * rate tunggal 1–600 bulan (2.00% monthly flat) sehingga fixture lama
     * yang memakai produk factory tetap lolos guard resolusi rate
     * (assertProductTenorCompatible / disbursement resolve). Produk RESMI
     * (seeder ShmLoanProductSeeder) memakai tier matriks eksak — fixture
     * yang menguji rate/gap selalu membuat tier sendiri (lihat
     * ShmLoanProductMasterTest & ShmLoanDisbursementTest).
     */
    public function configure(): static
    {
        return $this->afterCreating(function (ShmLoanProduct $product): void {
            if (! $product->rateTiers()->exists()) {
                $product->rateTiers()->create([
                    'min_tenor_months' => 1,
                    'max_tenor_months' => 600,
                    'rate_percent' => 2.00,
                    'rate_period' => 'monthly',
                    'calculation_method' => 'flat',
                ]);
            }
        });
    }

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
