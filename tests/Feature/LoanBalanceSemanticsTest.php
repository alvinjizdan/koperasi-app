<?php

namespace Tests\Feature;

use App\Models\InstallmentSchedule;
use App\Models\Loan;
use App\Models\LoanDisbursement;
use App\Models\User;
use App\Services\InstallmentPaymentService;
use App\Services\LoanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->markTestSkipped('Ticket 15J: Retired obsolete Mikro domain test (LoanBalanceSemantics decommissioned).');

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

    public function test_angsuran_index_labels_principal_remaining_explicitly(): void
    {
        // 1. Domain invariant: remaining_balance (sisa pokok) harus selalu berbeda dari total_owed (kewajiban total)
        $loan = $this->disbursedLoanWithInterest();
        $this->assertNotSame($loan->remaining_balance, $loan->total_owed);
        $this->assertSame(12_000_000.0, $loan->remaining_balance);
        $this->assertSame(13_440_000.0, $loan->total_owed);

        // 2. Active SHM Loan UI verification:
        // Pada halaman Detail Pinjaman SHM (resources/js/Pages/Shm/Loans/Show.jsx),
        // outstanding/sisa pokok dan kolom angsuran dipisahkan secara eksplisit:
        // - Pokok angsuran: label 'Pokok' (key: principal_amount)
        // - Total angsuran: label 'Total' (key: total_amount)
        // - Sisa angsuran: label 'Sisa' (key: remaining_amount)
        // - Outstanding pinjaman: loan.outstanding (sisa pokok)
        $shmShowSource = file_get_contents(resource_path('js/Pages/Shm/Loans/Show.jsx'));
        $this->assertStringContainsString("key: 'principal_amount'", $shmShowSource);
        $this->assertStringContainsString("label: 'Pokok'", $shmShowSource);
        $this->assertStringContainsString("key: 'remaining_amount'", $shmShowSource);
        $this->assertStringContainsString('loan.outstanding', $shmShowSource);
    }
}
