<?php

namespace Database\Factories;

use App\Models\ChartOfAccount;
use App\Models\ShmAccountingMapping;
use App\Models\ShmLoanProduct;
use App\Models\ShmProductAccounting;
use App\Support\AccountingContext;
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

            // T006E — Buat akun default SHM & konfigurasi akuntansi bila belum ada
            $cash = ChartOfAccount::firstOrCreate(
                ['accounting_context' => AccountingContext::SHM, 'code' => '1-1101'],
                [
                    'name' => 'Kas Operasional SHM',
                    'category' => ChartOfAccount::CATEGORY_ASSET,
                    'normal_balance' => ChartOfAccount::NORMAL_DEBIT,
                    'is_active' => true,
                ]
            );

            $receivable = ChartOfAccount::firstOrCreate(
                ['accounting_context' => AccountingContext::SHM, 'code' => '1-1301'],
                [
                    'name' => 'Piutang Pokok Pinjaman SHM',
                    'category' => ChartOfAccount::CATEGORY_ASSET,
                    'normal_balance' => ChartOfAccount::NORMAL_DEBIT,
                    'is_active' => true,
                ]
            );

            $interest = ChartOfAccount::firstOrCreate(
                ['accounting_context' => AccountingContext::SHM, 'code' => '4-1101'],
                [
                    'name' => 'Pendapatan Bunga Pinjaman SHM',
                    'category' => ChartOfAccount::CATEGORY_REVENUE,
                    'normal_balance' => ChartOfAccount::NORMAL_CREDIT,
                    'is_active' => true,
                ]
            );

            $penalty = ChartOfAccount::firstOrCreate(
                ['accounting_context' => AccountingContext::SHM, 'code' => '4-1201'],
                [
                    'name' => 'Pendapatan Denda Pinjaman SHM',
                    'category' => ChartOfAccount::CATEGORY_REVENUE,
                    'normal_balance' => ChartOfAccount::NORMAL_CREDIT,
                    'is_active' => true,
                ]
            );

            $discount = ChartOfAccount::firstOrCreate(
                ['accounting_context' => AccountingContext::SHM, 'code' => '4-1901'],
                [
                    'name' => 'Diskon Pelunasan Pinjaman SHM',
                    'category' => ChartOfAccount::CATEGORY_REVENUE,
                    'normal_balance' => ChartOfAccount::NORMAL_DEBIT,
                    'is_active' => true,
                ]
            );

            ShmProductAccounting::firstOrCreate(
                [
                    'shm_loan_product_id' => $product->id,
                    'accounting_context' => AccountingContext::SHM,
                ],
                [
                    'cash_account_id' => $cash->id,
                    'receivable_account_id' => $receivable->id,
                    'interest_revenue_account_id' => $interest->id,
                    'penalty_revenue_account_id' => $penalty->id,
                    'settlement_discount_account_id' => $discount->id,
                    'is_active' => true,
                ]
            );

            // Generic mappings
            ShmAccountingMapping::firstOrCreate(
                [
                    'accounting_context' => AccountingContext::SHM,
                    'business_event' => ShmAccountingMapping::EVENT_DISBURSEMENT,
                    'shm_loan_product_id' => null,
                ],
                [
                    'debit_account_role' => ShmAccountingMapping::ROLE_RECEIVABLE,
                    'credit_account_role' => ShmAccountingMapping::ROLE_CASH,
                    'is_active' => true,
                ]
            );

            ShmAccountingMapping::firstOrCreate(
                [
                    'accounting_context' => AccountingContext::SHM,
                    'business_event' => ShmAccountingMapping::EVENT_REGULAR_PAYMENT,
                    'shm_loan_product_id' => null,
                ],
                [
                    'debit_account_role' => ShmAccountingMapping::ROLE_CASH,
                    'credit_account_role' => ShmAccountingMapping::ROLE_RECEIVABLE,
                    'is_active' => true,
                ]
            );

            ShmAccountingMapping::firstOrCreate(
                [
                    'accounting_context' => AccountingContext::SHM,
                    'business_event' => ShmAccountingMapping::EVENT_SETTLEMENT,
                    'shm_loan_product_id' => null,
                ],
                [
                    'debit_account_role' => ShmAccountingMapping::ROLE_CASH,
                    'credit_account_role' => ShmAccountingMapping::ROLE_RECEIVABLE,
                    'is_active' => true,
                ]
            );
        });
    }

    /**
     * Define the model's default state.
     *
     * Tier fixture = 2% (1–600 bulan) — angka fixture uji, bukan rate resmi;
     * rate resmi = f(produk, tenor) dari matriks produk (T82).
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
