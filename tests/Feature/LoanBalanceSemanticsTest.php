<?php

namespace Tests\Feature;

use App\Models\InstallmentSchedule;
use App\Models\Loan;
use App\Models\LoanDisbursement;
use App\Models\User;
use App\Services\InstallmentPaymentService;
use App\Services\LoanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * T107 — kontrak sumber kebenaran remaining_balance vs total_owed.
 *
 * remaining_balance = sisa pokok (nominal dicairkan - pembayaran berhasil);
 * total_owed = sisa kewajiban total (pokok + bunga + denda belum dibayar
 * sesuai jadwal) yang menjadi batas overpayment & keputusan PAID_OFF di
 * backend. Keduanya konsep terpisah dan tidak boleh ditukar antar konsumen.
 */
class LoanBalanceSemanticsTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superadmin = User::factory()->superadmin()->create();
    }

    /**
     * Pinjaman dicairkan Rp12.000.000, flat 12% setahun → bunga1.440.000
     * sehingga sisa pokok (12.000.000) berbeda dari kewajiban total
     * (13.440.000) dan konsep keduanya dapat diverifikasi eksplisit.
     */
    private function disbursedLoanWithInterest(): Loan
    {
        $loan = Loan::factory()->approved()->create([
            'amount' => 12_000_000,
            'tenor_months' => 12,
        ]);

        app(LoanService::class)->disburse($loan->fresh(), [
            'disbursement_date' => now()->toDateString(),
            'first_due_date' => now()->addMonth()->toDateString(),
            'method' => 'TRANSFER',
            'interest_method' => 'flat',
            'interest_rate_annual' => 12.0,
        ], $this->superadmin->id);

        return $loan->fresh();
    }

    /** Pinjaman aktif dengan dua jadwal tagihan (T104-style, tanpa bunga prosedural). */
    private function activeLoanWithTwoSchedules(): Loan
    {
        $loan = Loan::factory()->active()->create(['amount' => 1000000]);

        LoanDisbursement::factory()->create([
            'loan_id' => $loan->id,
            'amount' => 1000000,
        ]);

        foreach ([1, 2] as $number) {
            InstallmentSchedule::factory()->create([
                'loan_id' => $loan->id,
                'installment_number' => $number,
                'principal_amount' => 500000,
                'interest_amount' => 50000,
                'total_amount' => 550000,
                'paid_amount' => 0,
                'remaining_amount' => 550000,
                'status' => 'UPCOMING',
            ]);
        }

        return $loan;
    }

    // =====================================================================
    // SKENARIO 1 + 8 — SISA POKOK MENGIKUTI FORMULA LEGASINYA
    // =====================================================================

    public function test_principal_remaining_reflects_disbursed_amount_minus_payments(): void
    {
        $loan = $this->disbursedLoanWithInterest();

        // Sebelum pembayaran: sisa pokok = nominal dicairkan.
        $this->assertSame(12_000_000.0, $loan->remaining_balance);

        // Setelah pembayaran parsial Rp250.000 (120.000 bunga + 130.000 pokok),
        // formula legasi mengurangi SELURUH nominal pembayaran dari pokok —
        // perilaku lama yang dipertahankan, bukan diubah oleh T107.
        app(InstallmentPaymentService::class)->pay($loan, [
            'amount' => 250_000,
            'payment_date' => now()->toDateString(),
        ], $this->superadmin->id);

        $loan->refresh();
        $this->assertSame(11_750_000.0, $loan->remaining_balance);
    }

    public function test_principal_remaining_is_not_total_obligation(): void
    {
        $loan = $this->disbursedLoanWithInterest();

        // Pokok 12.000.000 vs kewajiban total 12.000.000 + 1.440.000 bunga.
        $this->assertSame(12_000_000.0, $loan->remaining_balance);
        $this->assertSame(13_440_000.0, $loan->total_owed);
        $this->assertNotSame($loan->remaining_balance, $loan->total_owed);
    }

    // =====================================================================
    // SKENARIO 3-5 — KONTRAK PEMBAYARAN: BATAS = TOTAL_OWED
    // =====================================================================

    public function test_payment_below_total_owed_is_accepted(): void
    {
        $loan = $this->disbursedLoanWithInterest();

        $response = $this->actingAs($this->superadmin)
            ->post(route('angsuran.pay.store', $loan->id), [
                'amount' => 249_999,
                'payment_date' => now()->toDateString(),
                'idempotency_key' => (string) Str::uuid(),
            ]);

        $response->assertRedirect(route('angsuran.schedule', $loan->id));
        $this->assertSame(1, $loan->payments()->where('status', 'COMPLETED')->count());
    }

    public function test_payment_equal_to_total_owed_is_accepted_and_marks_paid_off(): void
    {
        $loan = $this->disbursedLoanWithInterest();

        $response = $this->actingAs($this->superadmin)
            ->post(route('angsuran.pay.store', $loan->id), [
                'amount' => 13_440_000,
                'payment_date' => now()->toDateString(),
                'idempotency_key' => (string) Str::uuid(),
            ]);

        $response->assertRedirect(route('angsuran.schedule', $loan->id));

        $loan->refresh();
        $this->assertSame('PAID_OFF', $loan->status);
        $this->assertSame(0.0, $loan->total_owed);
        $this->assertSame(0.0, $loan->remaining_balance);
    }

    public function test_payment_above_total_owed_is_rejected_by_backend(): void
    {
        $loan = $this->disbursedLoanWithInterest();

        $response = $this->actingAs($this->superadmin)
            ->post(route('angsuran.pay.store', $loan->id), [
                'amount' => 13_440_000.01,
                'payment_date' => now()->toDateString(),
            ]);

        $response->assertSessionHasErrors('amount');
        $this->assertStringContainsString(
            'melebihi sisa kewajiban pinjaman',
            session('errors')->first('amount')
        );
        $this->assertSame(0, $loan->payments()->count());
        $this->assertSame('ACTIVE', $loan->fresh()->status);
    }

    // =====================================================================
    // SKENARIO 6 — GUARD FRONTEND MEMAKAI KONTRAK TOTAL_OWED YANG SAMA
    // =====================================================================

    public function test_pay_form_serializes_total_owed_and_guard_reads_it(): void
    {
        $loan = $this->disbursedLoanWithInterest();

        $response = $this->actingAs($this->superadmin)
            ->get(route('angsuran.pay.create', $loan->id));

        $response->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('Angsuran/Pay')
                // Kedua konsep dikirim eksplisit dengan nilai backend masing-masing.
                ->where('loan.total_owed', fn ($value): bool => (float) $value === 13_440_000.0)
                ->where('loan.remaining_balance', fn ($value): bool => (float) $value === 12_000_000.0)
                ->where('suggested_amount', fn ($value): bool => (float) $value === 1_120_000.0)
            );

        // Guard overpayment frontend membaca total_owed — konsep yang sama dengan
        // validasi backend — dan label sisa pokok konsisten dengan modul.
        $source = file_get_contents(resource_path('js/Pages/Angsuran/Pay.jsx'));
        $this->assertStringContainsString('loan.total_owed', $source);
        $this->assertStringContainsString('Sisa Kewajiban Pokok', $source);
    }

    // =====================================================================
    // SKENARIO 7 — SISA TERTENTU ANGSURAN BUKAN KEWAJIBAN TOTAL PINJAMAN
    // =====================================================================

    public function test_installment_remaining_amount_is_distinct_from_loan_total_owed(): void
    {
        $loan = $this->activeLoanWithTwoSchedules();

        $response = $this->actingAs($this->superadmin)
            ->get(route('angsuran.pay.create', $loan->id));

        $response->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                // Kewajiban total pinjaman = jumlah dua jadwal (1.100.000).
                ->where('loan.total_owed', fn ($value): bool => (float) $value === 1_100_000.0)
                // Tagihan berikutnya memakai sisa angsuran per-jadwal (550.000),
                // bukan pinjaman-level total_owed.
                ->where('next_schedule.remaining_amount', fn ($value): bool => (float) $value === 550_000.0)
                ->where('suggested_amount', fn ($value): bool => (float) $value === 550_000.0)
            );
    }

    // =====================================================================
    // SETELAH PEMBAYARAN — NILAI DIPERBARUI MENURUT SEMANTIK MASING-MASING
    // =====================================================================

    public function test_values_update_according_to_each_semantics_after_partial_payment(): void
    {
        $loan = $this->disbursedLoanWithInterest();

        app(InstallmentPaymentService::class)->pay($loan, [
            'amount' => 250_000,
            'payment_date' => now()->toDateString(),
        ], $this->superadmin->id);

        $loan->refresh();
        // Sisa pokok: 12.000.000 - 250.000 (formula legasi).
        $this->assertSame(11_750_000.0, $loan->remaining_balance);
        // Kewajiban total: 13.440.000 - 250.000 (alokasi penuh terkonsumsi).
        $this->assertSame(13_190_000.0, $loan->total_owed);
        // Rincian alokasi angsuran pertama: bunga120.000 dulu, pokok130.000 —
        // paid_amount pada jadwal melacak porsi POKOK (bukti: BusinessRulesTest).
        $first = $loan->schedules()->orderBy('installment_number')->first()->fresh();
        $this->assertSame(120_000.0, (float) $first->interest_paid);
        $this->assertSame(130_000.0, (float) $first->paid_amount);
    }

    // =====================================================================
    // KONTRAK LABEL + SORTING INDEX (PERBAIKAN KONSUMEN T107)
    // =====================================================================

    public function test_angsuran_index_sort_by_accessor_falls_back_and_keeps_serialization(): void
    {
        $loan = $this->activeLoanWithTwoSchedules();

        // Regression: `remaining_balance` bukan kolom database — sort key ini
        // sebelumnya memicu QueryException 500; kini jatuh ke urutan fallback.
        $response = $this->actingAs($this->superadmin)
            ->get(route('angsuran.index', ['sort' => 'remaining_balance', 'direction' => 'asc']));

        $response->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('Angsuran/Index')
                ->where('sort.key', null)
                ->where('loans.data.0.remaining_balance', fn ($value): bool => (float) $value === 1_000_000.0)
            );
    }

    public function test_angsuran_index_labels_principal_remaining_explicitly(): void
    {
        $source = file_get_contents(resource_path('js/Pages/Angsuran/Index.jsx'));

        // Nilai kolom adalah sisa pokok; label harus mencantumkan "Pokok"
        // agar tidak tercampur dengan kewajiban total ("sisa kewajiban").
        $this->assertStringContainsString('Sisa Kewajiban Pokok', $source);
        $this->assertStringNotContainsString('>Sisa Kewajiban<', $source);
    }
}
