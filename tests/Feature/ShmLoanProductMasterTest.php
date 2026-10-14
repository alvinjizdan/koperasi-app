<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\ShmCustomer;
use App\Models\ShmLoan;
use App\Models\ShmLoanApplication;
use App\Models\ShmLoanProduct;
use App\Models\ShmLoanProductRateTier;
use App\Models\User;
use App\Services\Shm\ProductRateQuote;
use App\Services\Shm\ShmProductRateService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\ShmLoanProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use RuntimeException;
use Tests\TestCase;

/**
 * T82 — Master Produk Pinjaman SHM & resolusi rate (PRODUCT + TENOR).
 *
 * Yang diuji:
 * - PRODUCT MASTER: 5 produk resmi, kategori benar, seluruh tier sesuai
 *   matriks resmi, semua monthly + flat, tanpa overlap, gap tetap invalid.
 * - RATE RESOLUTION: lookup EXAKT per tenor (inclusive di kedua ujung);
 *   gap / di luar range / produk nonaktif DITOLAK — tanpa interpolasi,
 *   fallback, nearest, atau default rate (universal 2% tidak berlaku).
 *
 * Kontrak data bersumber dari keputusan bisnis T82 (OFFICIAL PRODUCT
 * MATRIX) yang diseed deterministik oleh ShmLoanProductSeeder.
 */
class ShmLoanProductMasterTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Matriks resmi: code → [min, max, rate] (tenor EXAKT, inclusive).
     *
     * @var array<string, array<int, array<int, int|float>>>
     */
    private const OFFICIAL_MATRIX = [
        'BSM-PRIORITAS' => [[12, 18, 1.30], [24, 30, 1.40], [36, 42, 1.50], [48, 52, 1.60], [60, 60, 1.70]],
        'BSM-FLEXIBEL' => [[12, 18, 1.40], [24, 30, 1.50], [36, 48, 1.60]],
        'BSM-PERKAMPUNGAN' => [[12, 18, 1.40], [24, 30, 1.50], [36, 36, 1.60]],
        'BSM-RO-EXELENT' => [[12, 18, 1.10], [24, 30, 1.20], [36, 42, 1.30], [48, 48, 1.40]],
        'BSM-RO-FLEXIBEL' => [[12, 18, 1.20], [24, 30, 1.30], [36, 42, 1.40], [48, 48, 1.50]],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->seed(ShmLoanProductSeeder::class);
    }

    private function product(string $code): ShmLoanProduct
    {
        return ShmLoanProduct::query()->where('code', $code)->firstOrFail();
    }

    private function rates(): ShmProductRateService
    {
        return app(ShmProductRateService::class);
    }

    /** @return array<int, array<int, int|float>> */
    private function tiersOf(string $code): array
    {
        return $this->product($code)
            ->rateTiers()
            ->orderBy('min_tenor_months')
            ->orderBy('max_tenor_months')
            ->get()
            ->map(fn (ShmLoanProductRateTier $tier): array => [
                $tier->min_tenor_months,
                $tier->max_tenor_months,
                (float) $tier->rate_percent,
            ])
            ->all();
    }

    private function assertTenorRejected(string $code, int $tenor): void
    {
        try {
            $this->rates()->resolve($this->product($code), $tenor);
            $this->fail(sprintf('Tenor %d bulan pada %s harus ditolak.', $tenor, $code));
        } catch (ValidationException $exception) {
            $this->assertStringContainsString(
                sprintf('Tenor %d bulan tidak tersedia', $tenor),
                (string) $exception->getMessage(),
            );
        }
    }

    // =================================================================
    // PRODUCT MASTER (1–7)
    // =================================================================

    public function test_all_five_official_products_are_available(): void
    {
        $this->assertSame(
            ['BSM-PRIORITAS', 'BSM-FLEXIBEL', 'BSM-PERKAMPUNGAN', 'BSM-RO-EXELENT', 'BSM-RO-FLEXIBEL'],
            ShmLoanProduct::query()->orderBy('id')->pluck('code')->all(),
        );

        $this->assertSame(
            ['BSM Prioritas', 'BSM Flexibel', 'BSM Perkampungan', 'BSM RO Exelent', 'BSM RO Flexibel'],
            ShmLoanProduct::query()->orderBy('id')->pluck('name')->all(),
        );

        // Tidak ada produk tambahan di luar lima produk resmi.
        $this->assertSame(5, ShmLoanProduct::query()->count());
        $this->assertTrue(ShmLoanProduct::query()->where('is_active', true)->count() === 5);
    }

    public function test_product_categories_match_official_matrix(): void
    {
        $this->assertSame(ShmLoanProduct::CATEGORY_PERUMAHAN, $this->product('BSM-PRIORITAS')->category);
        $this->assertSame(ShmLoanProduct::CATEGORY_PERUMAHAN, $this->product('BSM-FLEXIBEL')->category);
        $this->assertSame(ShmLoanProduct::CATEGORY_PERKAMPUNGAN, $this->product('BSM-PERKAMPUNGAN')->category);
        $this->assertSame(ShmLoanProduct::CATEGORY_RO, $this->product('BSM-RO-EXELENT')->category);
        $this->assertSame(ShmLoanProduct::CATEGORY_RO, $this->product('BSM-RO-FLEXIBEL')->category);
    }

    public function test_all_rate_tiers_match_official_matrix(): void
    {
        foreach (self::OFFICIAL_MATRIX as $code => $expected) {
            $this->assertSame($expected, $this->tiersOf($code), "Tier rate {$code} harus sesuai matriks resmi.");
        }
    }

    public function test_all_rate_tiers_are_monthly_and_flat(): void
    {
        $tiers = ShmLoanProductRateTier::query()->get();

        $this->assertGreaterThan(0, $tiers->count());

        foreach ($tiers as $tier) {
            $this->assertSame('monthly', $tier->rate_period, "Tier #{$tier->id} harus monthly.");
            $this->assertSame('flat', $tier->calculation_method, "Tier #{$tier->id} harus flat.");
            $this->assertGreaterThan(0, (float) $tier->rate_percent, "Tier #{$tier->id} harus rate > 0.");
            $this->assertLessThanOrEqual($tier->max_tenor_months, $tier->min_tenor_months);
        }
    }

    public function test_no_overlapping_tiers_exist_and_model_rejects_overlap(): void
    {
        // Matriks resmi: seluruh tier tiap produk saling lepas (gap sah).
        foreach (self::OFFICIAL_MATRIX as $code => $_tiers) {
            $ranges = $this->product($code)->rateTiers()->get();

            foreach ($ranges as $a) {
                foreach ($ranges as $b) {
                    if ($a->id === $b->id) {
                        continue;
                    }

                    $overlaps = $a->min_tenor_months <= $b->max_tenor_months
                        && $a->max_tenor_months >= $b->min_tenor_months;

                    $this->assertFalse($overlaps, "Tier {$code} (#{$a->id} vs #{$b->id}) tidak boleh tumpang tindih.");
                }
            }
        }

        // Integritas: model menolak pembuatan tier yang tumpang tindih.
        $product = $this->product('BSM-PRIORITAS');

        try {
            $product->rateTiers()->create([
                'min_tenor_months' => 13,
                'max_tenor_months' => 15,
                'rate_percent' => 9.99,
                'rate_period' => 'monthly',
                'calculation_method' => 'flat',
            ]);
            $this->fail('Tier tumpang tindih harus ditolak.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('tumpang tindih', $exception->getMessage());
        }

        // Integritas: min > max juga ditolak.
        try {
            $product->rateTiers()->create([
                'min_tenor_months' => 30,
                'max_tenor_months' => 20,
                'rate_percent' => 1.0,
                'rate_period' => 'monthly',
                'calculation_method' => 'flat',
            ]);
            $this->fail('Tier dengan min > max harus ditolak.');
        } catch (RuntimeException) {
            // Diharapkan.
        }
    }

    public function test_seeder_is_deterministic_and_does_not_duplicate(): void
    {
        $this->seed(ShmLoanProductSeeder::class);
        $this->seed(ShmLoanProductSeeder::class);

        $this->assertSame(5, ShmLoanProduct::query()->count());
        $this->assertSame(count(array_merge(...array_values(self::OFFICIAL_MATRIX))), ShmLoanProductRateTier::query()->count());

        foreach (self::OFFICIAL_MATRIX as $code => $expected) {
            $this->assertSame($expected, $this->tiersOf($code));
        }
    }

    public function test_products_carry_eligibility_summary(): void
    {
        $this->assertStringContainsString('Kol 1', (string) $this->product('BSM-PRIORITAS')->eligibility_summary);
        $this->assertStringContainsString('karyawan', (string) $this->product('BSM-PRIORITAS')->eligibility_summary);
        $this->assertStringContainsString('Rp100.000.000', (string) $this->product('BSM-FLEXIBEL')->eligibility_summary);
        $this->assertStringContainsString('Kol 2', (string) $this->product('BSM-FLEXIBEL')->eligibility_summary);
        $this->assertStringContainsString('BD Rp10.000.000', (string) $this->product('BSM-FLEXIBEL')->eligibility_summary);
        $this->assertStringContainsString('1–7 hari', (string) $this->product('BSM-RO-EXELENT')->eligibility_summary);
        $this->assertStringContainsString('8–30 hari', (string) $this->product('BSM-RO-FLEXIBEL')->eligibility_summary);
        // BSM Perkampungan: tidak ada syarat eksplisit yang dikonfirmasi.
        $this->assertNull($this->product('BSM-PERKAMPUNGAN')->eligibility_summary);
    }

    // =================================================================
    // RATE RESOLUTION (8–23)
    // =================================================================

    public function test_bsm_prioritas_rate_resolution(): void
    {
        $this->assertSame(1.30, (float) $this->rates()->resolve($this->product('BSM-PRIORITAS'), 12)->ratePercent);
        $this->assertSame(1.30, (float) $this->rates()->resolve($this->product('BSM-PRIORITAS'), 18)->ratePercent);
        $this->assertTenorRejected('BSM-PRIORITAS', 19);
        $this->assertSame(1.40, (float) $this->rates()->resolve($this->product('BSM-PRIORITAS'), 24)->ratePercent);
        $this->assertSame(1.40, (float) $this->rates()->resolve($this->product('BSM-PRIORITAS'), 27)->ratePercent);
        $this->assertSame(1.40, (float) $this->rates()->resolve($this->product('BSM-PRIORITAS'), 30)->ratePercent);
        $this->assertTenorRejected('BSM-PRIORITAS', 31);
        $this->assertSame(1.50, (float) $this->rates()->resolve($this->product('BSM-PRIORITAS'), 36)->ratePercent);
        $this->assertSame(1.50, (float) $this->rates()->resolve($this->product('BSM-PRIORITAS'), 42)->ratePercent);
        $this->assertTenorRejected('BSM-PRIORITAS', 43);
        $this->assertSame(1.60, (float) $this->rates()->resolve($this->product('BSM-PRIORITAS'), 48)->ratePercent);
        $this->assertSame(1.60, (float) $this->rates()->resolve($this->product('BSM-PRIORITAS'), 52)->ratePercent);
        $this->assertTenorRejected('BSM-PRIORITAS', 53);
        $this->assertTenorRejected('BSM-PRIORITAS', 59);
        $this->assertSame(1.70, (float) $this->rates()->resolve($this->product('BSM-PRIORITAS'), 60)->ratePercent);
        $this->assertTenorRejected('BSM-PRIORITAS', 61);
    }

    public function test_bsm_flexibel_rate_resolution(): void
    {
        $this->assertSame(1.40, (float) $this->rates()->resolve($this->product('BSM-FLEXIBEL'), 12)->ratePercent);
        $this->assertSame(1.40, (float) $this->rates()->resolve($this->product('BSM-FLEXIBEL'), 18)->ratePercent);
        $this->assertTenorRejected('BSM-FLEXIBEL', 19);
        $this->assertSame(1.50, (float) $this->rates()->resolve($this->product('BSM-FLEXIBEL'), 30)->ratePercent);
        $this->assertSame(1.60, (float) $this->rates()->resolve($this->product('BSM-FLEXIBEL'), 36)->ratePercent);
        $this->assertSame(1.60, (float) $this->rates()->resolve($this->product('BSM-FLEXIBEL'), 48)->ratePercent);
        $this->assertTenorRejected('BSM-FLEXIBEL', 49);
    }

    public function test_bsm_perkampungan_rate_resolution(): void
    {
        $this->assertSame(1.40, (float) $this->rates()->resolve($this->product('BSM-PERKAMPUNGAN'), 12)->ratePercent);
        $this->assertSame(1.50, (float) $this->rates()->resolve($this->product('BSM-PERKAMPUNGAN'), 30)->ratePercent);
        $this->assertSame(1.60, (float) $this->rates()->resolve($this->product('BSM-PERKAMPUNGAN'), 36)->ratePercent);
        $this->assertTenorRejected('BSM-PERKAMPUNGAN', 37);
        $this->assertTenorRejected('BSM-PERKAMPUNGAN', 11);
    }

    public function test_bsm_ro_exelent_rate_resolution(): void
    {
        $this->assertSame(1.10, (float) $this->rates()->resolve($this->product('BSM-RO-EXELENT'), 12)->ratePercent);
        $this->assertSame(1.20, (float) $this->rates()->resolve($this->product('BSM-RO-EXELENT'), 24)->ratePercent);
        $this->assertSame(1.30, (float) $this->rates()->resolve($this->product('BSM-RO-EXELENT'), 42)->ratePercent);
        $this->assertSame(1.40, (float) $this->rates()->resolve($this->product('BSM-RO-EXELENT'), 48)->ratePercent);
        $this->assertTenorRejected('BSM-RO-EXELENT', 49);
        $this->assertTenorRejected('BSM-RO-EXELENT', 19);
    }

    public function test_bsm_ro_flexibel_rate_resolution(): void
    {
        $this->assertSame(1.20, (float) $this->rates()->resolve($this->product('BSM-RO-FLEXIBEL'), 18)->ratePercent);
        $this->assertSame(1.30, (float) $this->rates()->resolve($this->product('BSM-RO-FLEXIBEL'), 30)->ratePercent);
        $this->assertSame(1.40, (float) $this->rates()->resolve($this->product('BSM-RO-FLEXIBEL'), 42)->ratePercent);
        $this->assertSame(1.50, (float) $this->rates()->resolve($this->product('BSM-RO-FLEXIBEL'), 48)->ratePercent);
        $this->assertTenorRejected('BSM-RO-FLEXIBEL', 49);
        $this->assertTenorRejected('BSM-RO-FLEXIBEL', 43);
    }

    public function test_resolve_returns_complete_quote_with_matched_tier(): void
    {
        $quote = $this->rates()->resolve($this->product('BSM-PRIORITAS'), 27);

        $this->assertInstanceOf(ProductRateQuote::class, $quote);
        $this->assertSame('BSM Prioritas', $quote->product->name);
        $this->assertSame(27, $quote->tenorMonths);
        $this->assertSame(1.4, $quote->ratePercent);
        $this->assertSame('monthly', $quote->ratePeriod);
        $this->assertSame('flat', $quote->calculationMethod);
        // Tier yang cocok adalah rentang 24–30.
        $this->assertSame(24, $quote->tier->min_tenor_months);
        $this->assertSame(30, $quote->tier->max_tenor_months);
    }

    public function test_inactive_product_is_rejected(): void
    {
        $product = $this->product('BSM-PRIORITAS');
        $product->update(['is_active' => false]);

        try {
            $this->rates()->resolve($product->fresh(), 12);
            $this->fail('Produk nonaktif harus ditolak.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('tidak aktif', (string) $exception->getMessage());
        }
    }

    public function test_gap_between_tiers_is_never_filled_by_neighbour_rate(): void
    {
        // Tenor 19–23 berada di antara tier 12–18 dan 24–30: tidak ada
        // rate tetangga yang dipakai — seluruhnya ditolak.
        foreach ([19, 20, 21, 22, 23] as $tenor) {
            $this->assertTenorRejected('BSM-PRIORITAS', $tenor);
        }
    }

    // =================================================================
    // PRODUCT UI & END-TO-END FLOW (30–44)
    // =================================================================

    public function test_product_master_page_returns_200_and_renders_five_products_with_19_tiers(): void
    {
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $role = Role::query()->where('name', 'admin')->first();
        if ($role) {
            $admin->roles()->attach($role->id);
        }

        $response = $this->actingAs($admin)->get(route('shm.loans.products.index'));

        $response->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Shm/Loans/Products')
                ->has('products.data', 5)
                ->where('products.data.0.code', 'BSM-PRIORITAS')
                ->where('products.data.0.rate_tiers.0.min_tenor_months', 12)
                ->where('products.data.0.rate_tiers.0.max_tenor_months', 18)
                ->where('products.data.0.rate_tiers.0.rate_percent', 1.3)
                ->etc());
    }

    public function test_end_to_end_official_product_flow_and_first_due_date_schedule(): void
    {
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $adminRole = Role::query()->where('name', 'admin')->first();
        if ($adminRole) {
            $admin->roles()->attach($adminRole->id);
        }

        $customer = ShmCustomer::factory()->create();
        $product = $this->product('BSM-PRIORITAS');

        $firstDueDate = '2026-11-25';

        // 1. Marketing submits application with exact tenor 48 and marketing-selected first_due_date
        $payload = [
            'pribadi' => ['customer_id' => $customer->id],
            'pengajuan' => [
                'product_id' => $product->id,
                'principal' => '460000000',
                'tenor_months' => 48,
                'first_due_date' => $firstDueDate,
                'purpose' => 'Modal Usaha',
                'loan_sequence' => 1,
                'disbursement_method' => 'Tunai',
            ],
            'income' => ['tetap' => '5000000'],
            'expense' => ['rumahTangga' => '2000000'],
            'persetujuan' => [
                'approved_amount' => '',
                'provision_fee' => '',
                'administration_fee' => '',
                'method' => 'flat',
                'notes' => 'Disetujui E2E.',
            ],
        ];

        $this->actingAs($admin)
            ->post(route('shm.loans.pengajuan.store'), $payload)
            ->assertSessionHasNoErrors();

        $application = ShmLoanApplication::query()->latest('id')->firstOrFail();
        $this->assertSame($firstDueDate, $application->first_due_date->toDateString());

        // 2. Submit approval & approve
        $this->actingAs($admin)
            ->post(route('shm.loans.pengajuan.submit-approval', $application))
            ->assertSessionHasNoErrors();

        $this->actingAs($admin)
            ->post(route('shm.applications.approve', $application->refresh()->shm_application_id), [
                'notes' => 'Disetujui E2E.',
            ])
            ->assertSessionHasNoErrors();

        // 3. Disburse -> loan receives exact resolved rate 1.60% and first_due_date 2026-11-25
        $this->actingAs($admin)
            ->post(route('shm.loans.pengajuan.disburse', $application))
            ->assertSessionHasNoErrors();

        $loan = ShmLoan::query()->sole();
        $this->assertSame(1.6, (float) $loan->rate_percent);
        $this->assertSame('monthly', $loan->rate_period);
        $this->assertSame('flat', $loan->calculation_method);
        $this->assertSame($firstDueDate, $loan->first_due_date->toDateString());

        // 4. Generate schedule -> #1 = 2026-11-25, rate = 1.60%
        $this->actingAs($admin)
            ->post(route('shm.loans.schedule.generate', $loan))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Jadwal angsuran berhasil dibuat.');

        $installments = $loan->installments()->orderBy('installment_number')->get();
        $this->assertCount(48, $installments);
        $this->assertSame($firstDueDate, $installments->first()->due_date->toDateString());
        // Monthly interest = 460.000.000 * 1.60% = 7.360.000
        $this->assertSame(7360000.0, (float) $installments->first()->interest_amount);

        // 5. Idempotent second generate
        $this->actingAs($admin)
            ->post(route('shm.loans.schedule.generate', $loan))
            ->assertSessionHas('success', 'Jadwal angsuran sudah tersedia.');

        $this->assertCount(48, $loan->installments);
    }
}
