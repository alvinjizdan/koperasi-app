<?php

namespace Tests\Feature;

use App\Models\InstallmentAllocation;
use App\Models\Loan;
use App\Models\SavingProduct;
use App\Models\SavingsAccount;
use App\Models\User;
use App\Services\InstallmentPaymentService;
use App\Services\LoanService;
use App\Services\SavingsTransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Ticket 15 — pengujian integrasi aturan bisnis umum (data dummy).
 */
class BusinessRulesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    // =====================================================================
    // B. PENCAIRAN DENGAN SNAPSHOT BUNGA
    // =====================================================================

    public function test_disbursement_creates_schedule_with_flat_interest_snapshot(): void
    {
        $loan = Loan::factory()->create([
            'amount' => 12_000_000,
            'tenor_months' => 12,
            'status' => 'APPROVED',
        ]);

        app(LoanService::class)->disburse($loan->fresh(), [
            'disbursement_date' => '2026-01-10',
            'method' => 'TRANSFER',
            'interest_method' => 'flat',
            'interest_rate_annual' => 12.0,
        ], $this->user->id);

        $loan->refresh();

        // Snapshot terkunci pada pinjaman (C.4).
        $this->assertSame('flat', $loan->interest_method);
        $this->assertSame(12.0, (float) $loan->interest_rate_annual);

        // Flat 12% × 12 juta × 1 tahun = 1.440.000 bunga; pokok 1 jt/bln + bunga 120 ribu.
        $schedules = $loan->schedules()->get();
        $this->assertCount(12, $schedules);
        $this->assertSame(1_000_000.0, (float) $schedules[0]->principal_amount);
        $this->assertSame(120_000.0, (float) $schedules[0]->interest_amount);
        $this->assertSame(1_120_000.0, (float) $schedules[0]->total_amount);
    }

    public function test_disbursement_rejects_unknown_interest_method(): void
    {
        $loan = Loan::factory()->create(['amount' => 5_000_000, 'tenor_months' => 5, 'status' => 'APPROVED']);

        $this->expectException(ValidationException::class);

        app(LoanService::class)->disburse($loan->fresh(), [
            'disbursement_date' => '2026-01-10',
            'method' => 'TRANSFER',
            'interest_method' => 'compound', // tidak dikenal
            'interest_rate_annual' => 10.0,
        ], $this->user->id);
    }

    public function test_disbursement_defaults_to_configured_provisional_values(): void
    {
        $loan = Loan::factory()->create(['amount' => 6_000_000, 'tenor_months' => 6, 'status' => 'APPROVED']);

        app(LoanService::class)->disburse($loan->fresh(), [
            'disbursement_date' => '2026-01-10',
            'method' => 'TRANSFER',
        ], $this->user->id);

        $loan->refresh();

        // Default provisional: flat, rate 0 (produk tanpa bunga sampai dikonfirmasi).
        $this->assertSame('flat', $loan->interest_method);
        $this->assertSame(0.0, (float) $loan->interest_rate_annual);
        $this->assertSame(0.0, (float) $loan->schedules[0]->interest_amount);
    }

    // =====================================================================
    // E. ALOKASI PEMBAYARAN KE KOMPONEN
    // =====================================================================

    private function activeLoanWithInterest(): Loan
    {
        $loan = Loan::factory()->create([
            'amount' => 12_000_000,
            'tenor_months' => 12,
            'status' => 'APPROVED',
        ]);

        app(LoanService::class)->disburse($loan->fresh(), [
            'disbursement_date' => '2026-01-10',
            'method' => 'TRANSFER',
            'interest_method' => 'flat',
            'interest_rate_annual' => 12.0,
        ], $this->user->id);

        return $loan->fresh();
    }

    public function test_payment_allocates_to_interest_and_principal_in_order(): void
    {
        $loan = $this->activeLoanWithInterest();
        $first = $loan->schedules[0]; // pokok 1.000.000 + bunga 120.000

        $payment = app(InstallmentPaymentService::class)->pay($loan, [
            'amount' => 500_000,
            'payment_date' => '2026-02-10',
        ], $this->user->id);

        // Urutan provisional: interest → principal (tanpa denda).
        $allocations = InstallmentAllocation::where('installment_payment_id', $payment->id)->get()->keyBy('component');
        $this->assertSame(120_000.0, (float) $allocations['interest']->amount);
        $this->assertSame(380_000.0, (float) $allocations['principal']->amount);

        $first->refresh();
        $this->assertSame(120_000.0, (float) $first->interest_paid);
        $this->assertSame(380_000.0, (float) $first->paid_amount);
        $this->assertSame('PARTIALLY_PAID', $first->status);
    }

    public function test_full_payment_completes_schedule_and_tracks_all_components(): void
    {
        $loan = $this->activeLoanWithInterest();

        $payment = app(InstallmentPaymentService::class)->pay($loan, [
            'amount' => 1_120_000,
            'payment_date' => '2026-02-10',
        ], $this->user->id);

        $first = $loan->schedules[0]->fresh();
        $this->assertSame('PAID', $first->status);
        $this->assertSame(1_000_000.0, (float) $first->paid_amount);
        $this->assertSame(120_000.0, (float) $first->interest_paid);

        $byComponent = InstallmentAllocation::where('installment_payment_id', $payment->id)
            ->pluck('amount', 'component');
        $this->assertSame(120_000.0, (float) $byComponent['interest']);
        $this->assertSame(1_000_000.0, (float) $byComponent['principal']);
    }

    public function test_total_owed_includes_interest_and_drives_paid_off(): void
    {
        $loan = $this->activeLoanWithInterest();

        // Sisa kewajiban total = 12 × (1.000.000 + 120.000) = 13.440.000.
        $this->assertSame(13_440_000.0, $loan->total_owed);

        // Lunasi seluruh kewajiban.
        app(InstallmentPaymentService::class)->pay($loan, [
            'amount' => 13_440_000.0,
            'payment_date' => '2026-07-01',
        ], $this->user->id);

        $loan->refresh();
        $this->assertSame('PAID_OFF', $loan->status);
        $this->assertSame(0.0, $loan->total_owed);
    }

    public function test_payment_exceeding_total_owed_is_rejected(): void
    {
        $loan = $this->activeLoanWithInterest();

        $this->expectException(ValidationException::class);

        app(InstallmentPaymentService::class)->pay($loan, [
            'amount' => 13_440_000.01,
            'payment_date' => '2026-02-10',
        ], $this->user->id);
    }

    public function test_partial_payment_is_supported_and_recorded(): void
    {
        $loan = $this->activeLoanWithInterest();

        $payment = app(InstallmentPaymentService::class)->pay($loan, [
            'amount' => 250_000,
            'payment_date' => '2026-02-10',
        ], $this->user->id);

        $this->assertSame(250_000.0, (float) $payment->amount);
        // interest 120.000 + principal 130.000.
        $first = $loan->schedules[0]->fresh();
        $this->assertSame(120_000.0, (float) $first->interest_paid);
        $this->assertSame(130_000.0, (float) $first->paid_amount);
    }

    // =====================================================================
    // A. SIMPANAN: ELIGIBILITY & REVERSAL
    // =====================================================================

    private function accountForMember(): SavingsAccount
    {
        $product = SavingProduct::factory()->create();
        $account = SavingsAccount::factory()->create([
            'saving_product_id' => $product->id,
            'balance' => 500_000,
        ]);

        return $account;
    }

    public function test_inactive_member_cannot_transact_savings(): void
    {
        $account = $this->accountForMember();
        $account->member->update(['status' => 'inactive']);

        $this->expectException(ValidationException::class);

        app(SavingsTransactionService::class)->withdraw($account->fresh(), [
            'amount' => 100_000,
            'transaction_date' => now()->format('Y-m-d'),
        ], $this->user->id);
    }

    public function test_inactive_account_cannot_transact(): void
    {
        $account = $this->accountForMember();
        $account->update(['status' => 'closed']);

        $this->expectException(ValidationException::class);

        app(SavingsTransactionService::class)->deposit($account->fresh(), [
            'amount' => 100_000,
            'transaction_date' => now()->format('Y-m-d'),
        ], $this->user->id);
    }

    public function test_savings_reversal_creates_offsetting_transaction_linked_to_original(): void
    {
        $account = $this->accountForMember();
        $service = app(SavingsTransactionService::class);

        $original = $service->deposit($account->fresh(), [
            'amount' => 200_000,
            'transaction_date' => now()->format('Y-m-d'),
            'processed_by' => $this->user->id,
        ], $this->user->id);

        $account->refresh();
        $this->assertSame(700_000.0, (float) $account->balance);

        $reversal = $service->reverse($original->fresh(), $this->user->id, 'Salah input nominal');

        // Reversal = transaksi penyesuaian berlawanan yang terhubung ke asal (A.8).
        $this->assertSame('reversal', $reversal->entry_type);
        $this->assertSame($original->id, $reversal->reversal_of_id);
        $this->assertSame('withdrawal', $reversal->type);
        $this->assertSame(200_000.0, (float) $reversal->amount);

        // Saldo kembali ke posisi sebelum setoran.
        $account->refresh();
        $this->assertSame(500_000.0, (float) $account->balance);

        // Transaksi asal tidak berubah (A.7).
        $original->refresh();
        $this->assertSame('completed', $original->status);
        $this->assertSame(200_000.0, (float) $original->amount);
    }

    public function test_reversal_cannot_be_applied_twice(): void
    {
        $account = $this->accountForMember();
        $service = app(SavingsTransactionService::class);

        $original = $service->deposit($account->fresh(), [
            'amount' => 100_000,
            'transaction_date' => now()->format('Y-m-d'),
            'processed_by' => $this->user->id,
        ], $this->user->id);

        $service->reverse($original->fresh(), $this->user->id, 'Koreksi pertama');

        $this->expectException(ValidationException::class);
        $service->reverse($original->fresh(), $this->user->id, 'Koreksi kedua');
    }

    // =====================================================================
    // G. KONSISTENSI LAPORAN DENGAN SUMBER
    // =====================================================================

    public function test_loan_report_balance_matches_total_owed_after_partial_payment(): void
    {
        $loan = $this->activeLoanWithInterest();

        app(InstallmentPaymentService::class)->pay($loan, [
            'amount' => 1_120_000, // satu angsuran penuh
            'payment_date' => '2026-02-10',
        ], $this->user->id);

        $loan->refresh();

        // Sisa = 13.440.000 − 1.120.000.
        $this->assertSame(12_320_000.0, $loan->total_owed);
        // Pembayaran completed = 1.120.000 (sumber tunggal untuk laporan).
        $this->assertSame(1_120_000.0, $loan->total_paid);
    }
}
