<?php

namespace Tests\Feature;

use App\Models\InstallmentPayment;
use App\Models\ShmCustomer;
use App\Models\ShmLoan;
use App\Models\ShmLoanInstallment;
use App\Models\ShmLoanProduct;
use App\Services\Shm\ScheduleConfigurationIncomplete;
use App\Services\Shm\ShmRepaymentScheduleService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;

/**
 * T75 (guard) + T80 (implementasi) — Repayment schedule SHM.
 *
 * T75 dipertahankan: eligibility, idempotency, atomicity, isolation dari
 * domain koperasi, dan penolakan deterministik tanpa kontrak baseline.
 *
 * T80: pembangkitan nyata mengikuti CONFIRMED BASELINE T79 — bunga flat
 * rate produk atas pokok awal (konstan), pokok equal + angsuran penyeimbang,
 * rupiah utuh tanpa akumulasi kesalahan, jatuh tempo bulanan, tanpa grace
 * period.
 *
 * T83: anchor jatuh tempo = first_due_date (Marketing-pilih) — angsuran #1
 * jatuh tepat pada first_due_date; progresi addMonthsNoOverflow dari
 * ANCHOR ASLI (tanpa drift); anchor null DITOLAK tanpa fallback ke
 * disbursed_at; pembangkitan tetap idempoten/atomik.
 */
class ShmRepaymentScheduleTest extends TestCase
{
    use RefreshDatabase;

    private const PRINCIPAL = '100000000.00';

    private function createLoan(array $overrides = []): ShmLoan
    {
        $customer = ShmCustomer::factory()->create();
        $product = ShmLoanProduct::factory()->create();

        return ShmLoan::query()->create(array_merge([
            'loan_number' => 'L-SHM-'.uniqid(),
            'shm_customer_id' => $customer->id,
            'shm_loan_product_id' => $product->id,
            'principal' => self::PRINCIPAL,
            'tenor_months' => 12,
            // Rate = nilai tier produk (T82 — rate = f(product, tenor)).
            // 2.0000 di sini hanyalah salah satu nilai rate produk yang sah;
            // rate BUKAN lagi konstanta universal.
            'rate_percent' => '2.0000',
            // Kontrak baseline T79 yang tereksplicit pada record pinjaman —
            // prasyarat pembangkitan (T80 §4).
            'rate_period' => 'monthly',
            'calculation_method' => 'flat',
            'status' => ShmLoan::STATUS_ACTIVE,
            'disbursed_at' => now()->toDateString(),
            // T83 — ANCHOR jadwal = first_due_date (Marketing-pilih).
            'first_due_date' => now()->addMonthNoOverflow(1)->toDateString(),
        ], $overrides));
    }

    /**
     * Pinjaman layak dengan tanggal pencairan deterministik.
     *
     * T83 — anchor jatuh tempo = first_due_date (parameter terpisah); default
     * mengikuti tanggal pencairan agar tes lama tetap intent-nya jelas.
     */
    private function createEligibleLoan(
        string $disbursedAt,
        array $overrides = [],
        ?string $firstDueDate = null,
    ): ShmLoan {
        return $this->createLoan(array_merge([
            'disbursed_at' => $disbursedAt,
            'first_due_date' => $firstDueDate ?? $disbursedAt,
        ], $overrides));
    }

    private function seedInstallments(ShmLoan $loan, int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            ShmLoanInstallment::query()->create([
                'shm_loan_id' => $loan->id,
                'installment_number' => $i,
                'due_date' => now()->addMonthsNoOverflow($i)->toDateString(),
                'principal_amount' => '8333333.33',
                'interest_amount' => '200000.00',
                'total_amount' => '8533333.33',
                'status' => ShmLoanInstallment::STATUS_SCHEDULED,
            ]);
        }
    }

    // =================================================================
    // T75 guards — DIPERTAHANKAN
    // =================================================================

    public function test_ac01_missing_calculation_contract_is_rejected_deterministically(): void
    {
        // Pinjaman aktif cair tanpa periode/metode eksplisit: penolakan
        // deterministik dengan menyebut keputusan yang kurang — bukan default
        // diam-diam, tanpa satu baris tertulis.
        $loan = $this->createLoan([
            'rate_period' => null,
            'calculation_method' => null,
        ]);

        try {
            app(ShmRepaymentScheduleService::class)->generate($loan);
            $this->fail('Generation must not succeed without the explicit calculation contract.');
        } catch (ScheduleConfigurationIncomplete $exception) {
            $this->assertStringContainsString('tidak lengkap atau tidak sesuai baseline', $exception->getMessage());
            $this->assertStringContainsString('rate_period', $exception->getMessage());
            $this->assertStringContainsString('calculation_method', $exception->getMessage());
            $this->assertStringContainsString('metode angsuran', $exception->getMessage());
        }

        $this->assertSame(0, $loan->installments()->count());
    }

    public function test_ac02_undisbursed_loan_cannot_generate_schedule(): void
    {
        $loan = $this->createLoan(['disbursed_at' => null]);

        $this->expectException(ValidationException::class);

        app(ShmRepaymentScheduleService::class)->generate($loan);
    }

    public function test_ac02_non_active_loan_cannot_generate_schedule(): void
    {
        $loan = $this->createLoan(['status' => ShmLoan::STATUS_CLOSED]);

        $this->expectException(ValidationException::class);

        app(ShmRepaymentScheduleService::class)->generate($loan);
    }

    public function test_ac04_ac05_ac06_ac07_existing_schedule_is_returned_without_duplicates_or_deletion(): void
    {
        // Idempotency T75: pinjaman yang SUDAH memiliki angsuran mengembalikan
        // jadwal eksisting — tanpa duplikat, tanpa penghapusan, tanpa
        // regenerasi diam-diam, sequence tetap deterministik 1..N.
        $loan = $this->createLoan();
        $this->seedInstallments($loan, 3);

        $service = app(ShmRepaymentScheduleService::class);

        $first = $service->generate($loan);
        $second = $service->generate($loan);

        $this->assertCount(3, $first);
        $this->assertSame([1, 2, 3], $first->pluck('installment_number')->all());
        $this->assertSame($first->pluck('id')->all(), $second->pluck('id')->all());
        $this->assertSame(3, $loan->installments()->count());
    }

    public function test_ac08_schedule_binds_to_correct_shm_loan(): void
    {
        $loanA = $this->createLoan();
        $loanB = $this->createLoan();
        $this->seedInstallments($loanA, 2);

        $schedule = app(ShmRepaymentScheduleService::class)->generate($loanA);

        $this->assertTrue($schedule->every(fn ($row) => $row->shm_loan_id === $loanA->id));
        $this->assertSame(0, $loanB->installments()->count());
    }

    public function test_ac09_no_cooperative_member_relationship_in_schedule_service(): void
    {
        $reflection = new ReflectionClass(ShmRepaymentScheduleService::class);
        $methodNames = array_map(fn ($m) => $m->getName(), $reflection->getMethods());

        $this->assertFalse(in_array('member', $methodNames, true));
        $this->assertFalse(in_array('cooperativeLoan', $methodNames, true));
    }

    public function test_ac11_ac12_ac13_ac14_service_has_no_payment_penalty_settlement_or_collection_behavior(): void
    {
        $reflection = new ReflectionClass(ShmRepaymentScheduleService::class);
        $methodNames = array_map(fn ($m) => $m->getName(), $reflection->getMethods());

        $forbidden = ['pay', 'payment', 'allocate', 'penalty', 'settle', 'settlement', 'collect', 'collection'];
        $intersection = array_intersect($forbidden, $methodNames);

        $this->assertSame([], array_values($intersection));
    }

    public function test_repeated_generation_never_overwrites_existing_installment_amounts(): void
    {
        $loan = $this->createLoan();
        $this->seedInstallments($loan, 1);

        $before = ShmLoanInstallment::query()->where('shm_loan_id', $loan->id)->first();

        app(ShmRepaymentScheduleService::class)->generate($loan);
        app(ShmRepaymentScheduleService::class)->generate($loan);

        $after = $before->fresh();

        $this->assertSame((string) $before->principal_amount, (string) $after->principal_amount);
        $this->assertSame((string) $before->total_amount, (string) $after->total_amount);
        $this->assertSame($before->due_date->toDateString(), $after->due_date->toDateString());
    }

    // =================================================================
    // T80 §17 — required generation tests
    // =================================================================

    /** #1 — Active disbursed loan with the confirmed contract CAN generate. */
    public function test_t80_ac01_active_disbursed_loan_generates_persisted_schedule(): void
    {
        $loan = $this->createEligibleLoan('2026-10-12');

        $schedule = app(ShmRepaymentScheduleService::class)->generate($loan);

        $this->assertCount(12, $schedule);
        $this->assertSame(12, $loan->installments()->count());
        // Persisted: baris benar-benar ada di database (bukan hasil memori).
        $this->assertSame(
            12,
            ShmLoanInstallment::query()->where('shm_loan_id', $loan->id)->count(),
        );
    }

    /** #4 — Non-baseline explicit configuration is rejected, not silently used. */
    public function test_t80_non_baseline_rate_period_or_method_is_rejected(): void
    {
        $yearly = $this->createLoan(['rate_period' => 'yearly']);

        try {
            app(ShmRepaymentScheduleService::class)->generate($yearly);
            $this->fail('Yearly rate period must not generate a monthly-flat schedule.');
        } catch (ScheduleConfigurationIncomplete $exception) {
            $this->assertStringContainsString('monthly', $exception->getMessage());
        }

        $this->assertSame(0, $yearly->installments()->count());

        $effective = $this->createLoan(['calculation_method' => 'effective']);

        try {
            app(ShmRepaymentScheduleService::class)->generate($effective);
            $this->fail('Effective/reducing-balance method must not generate a flat schedule.');
        } catch (ScheduleConfigurationIncomplete $exception) {
            $this->assertStringContainsString('flat', $exception->getMessage());
        }

        $this->assertSame(0, $effective->installments()->count());
    }

    /**
     * #4b (T82) — rate produk EXAKT dipakai untuk bunga flat; universal 2%
     * TIDAK lagi berlaku. Rate 1.30% dan 1.70% (matriks resmi) menghasilkan
     * bunga bulanan yang benar atas pokok awal.
     */
    public function test_t82_product_rate_is_used_for_flat_interest(): void
    {
        $prioritas = $this->createLoan(['rate_percent' => '1.3000']);

        $schedule = app(ShmRepaymentScheduleService::class)->generate($prioritas);

        $this->assertCount(12, $schedule);
        $this->assertSame(1300000, (int) $schedule->first()->interest_amount, '1.30% × 100 juta = 1.300.000.');

        $prioritas60 = $this->createLoan(['rate_percent' => '1.7000']);

        $schedule = app(ShmRepaymentScheduleService::class)->generate($prioritas60);

        $this->assertSame(1700000, (int) $schedule->first()->interest_amount, '1.70% × 100 juta = 1.700.000.');
    }

    /**
     * #10/#11/#12 (T83) — rate produk menjadi otoritas bunga jadwal:
     * BSM Prioritas 27 bulan → 1.40%, 48 bulan → 1.60%; BSM RO Exelent
     * 24 bulan → 1.20% (angka contoh tidak di-hardcode ke production —
     * dihitung dari loan.rate_percent hasil resolusi produk T82).
     */
    public function test_t83_product_rates_drive_schedule_interest(): void
    {
        // 460.000.000 × 1.40% = 6.440.000 per bulan.
        $prioritas27 = $this->createEligibleLoan('2026-10-10', [
            'principal' => '460000000.00',
            'tenor_months' => 27,
            'rate_percent' => '1.4000',
        ], '2026-11-25');

        $schedule = app(ShmRepaymentScheduleService::class)->generate($prioritas27);

        $this->assertCount(27, $schedule);
        $this->assertTrue(
            $schedule->every(fn ($row) => (string) $row->interest_amount === '6440000.00'),
        );

        // 460.000.000 × 1.60% = 7.360.000 per bulan (contoh ticket T83).
        $prioritas48 = $this->createEligibleLoan('2026-10-10', [
            'principal' => '460000000.00',
            'tenor_months' => 48,
            'rate_percent' => '1.6000',
        ], '2026-11-25');

        $schedule = app(ShmRepaymentScheduleService::class)->generate($prioritas48);

        $this->assertCount(48, $schedule);
        $this->assertTrue(
            $schedule->every(fn ($row) => (string) $row->interest_amount === '7360000.00'),
        );
        // Σ pokok = 460.000.000 secara tepat (balancing angsuran terakhir).
        $this->assertSame(
            460000000,
            array_sum($schedule->pluck('principal_amount')->map(fn ($v) => (int) $v)->all()),
        );
        // Pokok equal 460.000.000/48 = 9.583.333 + angsuran penyeimbang.
        $principals = $schedule->pluck('principal_amount')->map(fn ($v) => (int) $v)->all();
        $this->assertSame(array_fill(0, 47, 9583333), array_slice($principals, 0, 47));
        $this->assertSame(9583349, $principals[47]);

        // 100.000.000 × 1.20% = 1.200.000 per bulan.
        $roExelent24 = $this->createEligibleLoan('2026-10-10', [
            'principal' => '100000000.00',
            'tenor_months' => 24,
            'rate_percent' => '1.2000',
        ], '2026-11-25');

        $schedule = app(ShmRepaymentScheduleService::class)->generate($roExelent24);

        $this->assertCount(24, $schedule);
        $this->assertTrue(
            $schedule->every(fn ($row) => (string) $row->interest_amount === '1200000.00'),
        );
    }

    /** #4b (T82) — rate tidak terisi/nol ditolak (tanpa default yang ditebak). */
    public function test_t82_missing_or_zero_product_rate_is_rejected(): void
    {
        $loan = $this->createLoan(['rate_percent' => '0.0000']);

        try {
            app(ShmRepaymentScheduleService::class)->generate($loan);
            $this->fail('Zero/missing product rate must not generate a schedule.');
        } catch (ScheduleConfigurationIncomplete $exception) {
            $this->assertStringContainsString('rate', $exception->getMessage());
        }

        $this->assertSame(0, $loan->installments()->count());
    }

    /** #4c — Principal berpecahan rupiah ditolak eksplisit (bukan dibulatkan diam-diam). */
    public function test_t80_fractional_rupiah_principal_is_rejected(): void
    {
        $loan = $this->createLoan(['principal' => '100000000.50']);

        $this->expectException(ValidationException::class);

        app(ShmRepaymentScheduleService::class)->generate($loan);
    }

    /** #5/#6/#7 — 2% monthly flat atas pokok awal, konstan di seluruh periode. */
    public function test_t80_interest_is_two_percent_monthly_flat_on_original_principal(): void
    {
        $loan = $this->createEligibleLoan('2026-10-12');

        $schedule = app(ShmRepaymentScheduleService::class)->generate($loan);

        // Pokok awal 100.000.000 × 2% = 2.000.000 per bulan.
        $this->assertTrue(
            $schedule->every(fn ($row) => (string) $row->interest_amount === '2000000.00'),
            'Monthly interest must be exactly 2% of the original principal for every installment.',
        );

        // Flat atas pokok AWAL: bunga identik walau pokok tersisa berkurang
        // antar periode (tidak ada pembayaran di ticket ini — nilai jadwal
        // harus konstan, bukan menyusut).
        $interests = $schedule->pluck('interest_amount')->map(fn ($v) => (string) $v)->unique();
        $this->assertCount(1, $interests);
    }

    /** #5b — Bunga ikut pokok awal: pokok berbeda → bunga berbeda proporsional. */
    public function test_t80_interest_scales_with_original_principal_not_tenor(): void
    {
        $loan = $this->createEligibleLoan('2026-10-12', [
            'principal' => '50000000.00',
            'tenor_months' => 6,
        ]);

        $schedule = app(ShmRepaymentScheduleService::class)->generate($loan);

        $this->assertCount(6, $schedule);
        // 50.000.000 × 2% = 1.000.000 — tidak terpengaruh tenor.
        $this->assertTrue(
            $schedule->every(fn ($row) => (string) $row->interest_amount === '1000000.00'),
        );
    }

    /** #8/#9/#10/#11 — Tenor 12 → 12 angsuran, nomor 1..12, pokok equal + penyeimbang, Σ pokok tepat. */
    public function test_t80_tenor_12_creates_12_sequential_installments_with_balancing_principal(): void
    {
        $loan = $this->createEligibleLoan('2026-10-12');

        $schedule = app(ShmRepaymentScheduleService::class)->generate($loan);

        $this->assertCount(12, $schedule);
        $this->assertSame(range(1, 12), $schedule->pluck('installment_number')->all());

        // Pokok equal 100.000.000 / 12 = 8.333.333,33 → rupiah terdekat
        // 8.333.333; angsuran terakhir penyeimbang 8.333.337.
        $principals = $schedule->pluck('principal_amount')->map(fn ($v) => (int) $v)->all();
        $this->assertSame(array_fill(0, 11, 8333333), array_slice($principals, 0, 11));
        $this->assertSame(8333337, $principals[11]);

        // Invarian: Σ pokok = pokok pinjaman SECARA TEPAT.
        $this->assertSame(100000000, array_sum($principals));

        // Total angsuran = pokok + bunga.
        $this->assertSame('10333333.00', (string) $schedule[0]->total_amount);
        $this->assertSame('10333337.00', (string) $schedule[11]->total_amount);
    }

    /** #12/#13 — Tidak ada pecahan rupiah tersimpan; angsuran terakhir menyerap selisih. */
    public function test_t80_no_fractional_rupiah_persisted_and_final_installment_absorbs_rounding(): void
    {
        // Pokok 100.000.005: /12 = 8.333.333,75 → terdekat 8.333.334;
        // penyeimbang = 100.000.005 − 8.333.334×11 = 8.333.331.
        $loan = $this->createEligibleLoan('2026-10-12', ['principal' => '100000005.00']);

        $schedule = app(ShmRepaymentScheduleService::class)->generate($loan);

        foreach ($schedule as $row) {
            foreach (['principal_amount', 'interest_amount', 'total_amount', 'paid_amount', 'remaining_amount'] as $column) {
                $value = (string) $row->{$column};
                $this->assertMatchesRegularExpression(
                    '/^\d+\.00$/',
                    $value,
                    "Nilai {$column} harus rupiah utuh (tanpa pecahan). Ditemukan: {$value}",
                );
            }
        }

        $principals = $schedule->pluck('principal_amount')->map(fn ($v) => (int) $v)->all();
        $this->assertSame(8333334, $principals[0]);
        $this->assertSame(8333331, $principals[11]);
        $this->assertSame(100000005, array_sum($principals));

        // Bunga round(100.000.005 × 2%) = round(2.000.000,10) = 2.000.000.
        $this->assertTrue(
            $schedule->every(fn ($row) => (string) $row->interest_amount === '2000000.00'),
        );
    }

    /** #13b — Pembulatan bunga setengah rupiah ke atas (round-half-up). */
    public function test_t80_interest_rounds_half_up_to_whole_rupiah(): void
    {
        // 100.000.025 × 2% = 2.000.000,50 → 2.000.001.
        $loan = $this->createEligibleLoan('2026-10-12', ['principal' => '100000025.00']);

        $schedule = app(ShmRepaymentScheduleService::class)->generate($loan);

        $this->assertTrue(
            $schedule->every(fn ($row) => (string) $row->interest_amount === '2000001.00'),
        );
    }

    /**
     * #14/#15 (T83) — jatuh tempo #1 = first_due_date (Marketing-pilih),
     * BUKAN pencairan + 1 bulan. Contoh kasus ticket: cair 10 Oktober 2026,
     * first due date 25 November 2026 → angsuran #1 = 25 November 2026.
     */
    public function test_t83_first_installment_uses_first_due_date_and_progresses_monthly(): void
    {
        $loan = $this->createEligibleLoan('2026-10-12', [], '2026-11-25');

        $schedule = app(ShmRepaymentScheduleService::class)->generate($loan);

        $dueDates = $schedule->pluck('due_date')->map->toDateString()->all();

        // Ansuran #1 = first_due_date itu SENDIRI (bukan 10 November / 12).
        $this->assertSame('2026-11-25', $dueDates[0]);
        $this->assertSame('2026-12-25', $dueDates[1]);
        $this->assertSame('2027-01-25', $dueDates[2]);
        // Bulan ke-12: anchor first_due_date + 12 bulan.
        $this->assertSame('2027-10-25', $dueDates[11]);

        // disbursed_at TIDAK pernah menjadi anchor.
        $this->assertNotContains('2026-11-12', $dueDates);
        $this->assertNotContains('2026-10-12', $dueDates);

        // Invarian: jatuh tempo STRICTLY chronologis.
        $timestamps = $schedule->pluck('due_date')->map->timestamp->all();
        $sorted = $timestamps;
        sort($sorted);
        $this->assertSame($sorted, $timestamps);
    }

    /**
     * T83 — anchor NULL ditolak eksplisit: tanpa fallback ke disbursed_at,
     * disbursed_at + 1 bulan, maupun tanggal hari ini; nol baris tertulis.
     */
    public function test_t83_null_first_due_date_is_rejected_without_fallback(): void
    {
        $loan = $this->createEligibleLoan('2026-10-12', ['first_due_date' => null]);

        try {
            app(ShmRepaymentScheduleService::class)->generate($loan);
            $this->fail('Null first_due_date must reject generation explicitly.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString(
                'tanggal jatuh tempo pertama belum ditentukan',
                (string) $exception->getMessage(),
            );
        }

        $this->assertSame(0, $loan->installments()->count(), 'Tidak boleh ada baris tanpa anchor.');
    }

    /**
     * #16 (T83) — fallback akhir bulan TANPA drift dari anchor
     * first_due_date 31 Januari: urutan wajib ticket.
     * 31 Jan → 28 Feb → 31 Mar → 30 Apr → 31 Mei (BUKAN 28 Feb → 28 Mar).
     */
    public function test_t83_end_of_month_anchor_uses_last_valid_day_without_drift(): void
    {
        $loan = $this->createEligibleLoan('2026-01-10', [], '2026-01-31');

        $schedule = app(ShmRepaymentScheduleService::class)->generate($loan);

        $dueDates = $schedule->pluck('due_date')->map->toDateString()->all();

        $this->assertSame('2026-01-31', $dueDates[0], 'Ansuran #1 = first_due_date itu sendiri.');
        $this->assertSame('2026-02-28', $dueDates[1]);
        // Maret punya hari 31 → kembali ke hari 31 (anchor asli,
        // BUKAN 28 Maret yang terjadi bila di-chained dari Februari).
        $this->assertSame('2026-03-31', $dueDates[2]);
        $this->assertSame('2026-04-30', $dueDates[3]);
        $this->assertSame('2026-05-31', $dueDates[4]);
        // Bulan ke-12 (index 11): anchor 31 Januari 2026 + 11 bulan = 31 Desember 2026.
        $this->assertSame('2026-12-31', $dueDates[11]);
    }

    /** #17/#18 — Idempotency: generate berulang tidak membuat baris tambahan. */
    public function test_t80_repeated_generation_produces_no_additional_rows(): void
    {
        $loan = $this->createEligibleLoan('2026-10-12');

        $service = app(ShmRepaymentScheduleService::class);

        $first = $service->generate($loan);
        $second = $service->generate($loan);
        $third = $service->generate($loan->fresh());

        $this->assertCount(12, $first);
        $this->assertSame($first->pluck('id')->all(), $second->pluck('id')->all());
        $this->assertSame($first->pluck('id')->all(), $third->pluck('id')->all());
        $this->assertSame(12, ShmLoanInstallment::query()->where('shm_loan_id', $loan->id)->count());
    }

    /** #20 — Dua "request" (instance terpisah) tidak pernah menggandakan baris. */
    public function test_t80_sequential_requests_cannot_create_duplicate_installments(): void
    {
        $loan = $this->createEligibleLoan('2026-10-12');

        // Request A dan B memegang instance model TERPISAH (state UI masing-
        // masing), keduanya menyerahkan id pinjaman yang sama — pola yang
        // persis dijaga lockForUpdate + recheck di dalam transaksi.
        $requestA = app(ShmRepaymentScheduleService::class);
        $requestB = app(ShmRepaymentScheduleService::class);

        $resultA = $requestA->generate(ShmLoan::query()->findOrFail($loan->id));
        $resultB = $requestB->generate(ShmLoan::query()->findOrFail($loan->id));

        $this->assertSame($resultA->pluck('id')->all(), $resultB->pluck('id')->all());
        $this->assertSame(12, ShmLoanInstallment::query()->where('shm_loan_id', $loan->id)->count());

        // Backstop database terakhir terhadap duplikasi paralel: unique
        // constraint (shm_loan_id, installment_number) menolak nomor ganda.
        $this->expectException(QueryException::class);

        ShmLoanInstallment::query()->create([
            'shm_loan_id' => $loan->id,
            'installment_number' => 1,
            'due_date' => '2027-01-01',
            'principal_amount' => '1',
            'interest_amount' => '1',
            'total_amount' => '2',
            'status' => ShmLoanInstallment::STATUS_SCHEDULED,
        ]);
    }

    /** #19 — Atomicity: kegagalan penulisan di tengah → rollback penuh, tanpa parsial. */
    public function test_t80_transaction_rolls_back_when_persistence_fails_midway(): void
    {
        $loan = $this->createEligibleLoan('2026-10-12');

        // Simulasikan kegagalan disk/DB pada baris ke-5 dari 12.
        Event::listen('eloquent.creating: '.ShmLoanInstallment::class, function ($model): void {
            if ((int) $model->installment_number === 5) {
                throw new RuntimeException('Simulasi kegagalan penulisan baris ke-5.');
            }
        });

        try {
            app(ShmRepaymentScheduleService::class)->generate($loan);
            $this->fail('Expected the mid-schedule failure to propagate.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('baris ke-5', $exception->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.ShmLoanInstallment::class);
        }

        // TIDAK ada jadwal parsial: bukan 4 baris, bukan 5 — nol baris.
        $this->assertSame(0, ShmLoanInstallment::query()->where('shm_loan_id', $loan->id)->count());
    }

    /** #21 — Angsuran baru memakai status/default kontrak T74 (scheduled, belum dibayar). */
    public function test_t80_new_installments_use_existing_outstanding_status_contract(): void
    {
        $loan = $this->createEligibleLoan('2026-10-12');

        $schedule = app(ShmRepaymentScheduleService::class)->generate($loan);

        $this->assertTrue(
            $schedule->every(fn ($row) => $row->status === ShmLoanInstallment::STATUS_SCHEDULED),
            'Newly generated installments must use the existing T74 default status.',
        );
        $this->assertTrue($schedule->every(fn ($row) => (string) $row->paid_amount === '0.00'));
        $this->assertTrue($schedule->every(fn ($row) => $row->paid_at === null));
        // Semantik kolom T74 (lihat ShmLoanInstallmentTest AC04): remaining
        // = total − paid; pada jadwal baru paid = 0 → sisa = total angsuran.
        $this->assertTrue(
            $schedule->every(
                fn ($row) => (string) $row->remaining_amount === (string) $row->total_amount,
            ),
        );
        // Tidak ada status baru yang diperkenalkan di ticket ini: hanya
        // scheduled (default T74) yang dipakai; 'paid' milik ticket Payment.
        $this->assertSame(ShmLoanInstallment::STATUS_SCHEDULED, $schedule->first()->status);
    }

    /** #22 — Tidak ada record pembayaran yang dibuat (SHM maupun koperasi). */
    public function test_t80_no_payment_records_are_created(): void
    {
        $loan = $this->createEligibleLoan('2026-10-12');

        app(ShmRepaymentScheduleService::class)->generate($loan);

        // Tabel pembayaran SHM tidak ada (ticket Payment belum ada)…
        $this->assertFalse(Schema::hasTable('shm_payments'));
        // …dan tabel pembayaran koperasi tidak tersentuh.
        $this->assertSame(0, InstallmentPayment::query()->count());
        // Seluruh baris angsuran tetap berstatus scheduled (belum dibayar).
        $this->assertSame(
            0,
            ShmLoanInstallment::query()
                ->where('shm_loan_id', $loan->id)
                ->where('status', ShmLoanInstallment::STATUS_PAID)
                ->count(),
        );
    }

    /** #23 (pelengkap) — Data principal tidak realistis untuk tenor ditolak, bukan menghasilkan pokok negatif. */
    public function test_t80_tiny_principal_for_tenor_is_rejected_not_negative(): void
    {
        $loan = $this->createEligibleLoan('2026-10-12', [
            'principal' => '2.00',
            'tenor_months' => 4,
        ]);

        $this->expectException(ValidationException::class);

        app(ShmRepaymentScheduleService::class)->generate($loan);
    }
}
