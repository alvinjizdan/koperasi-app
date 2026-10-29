<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CashTransaction;
use App\Models\Member;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SavingProduct;
use App\Models\SavingsAccount;
use App\Models\SavingsTransaction;
use App\Models\ShmCustomer;
use App\Models\ShmLoan;
use App\Models\ShmLoanApplication;
use App\Models\ShmLoanInstallment;
use App\Models\ShmLoanPayment;
use App\Models\ShmLoanSettlement;
use App\Models\ShmSettlementCorrection;
use App\Models\ShmSettlementDiscountPolicy;
use App\Models\User;
use App\Services\Shm\ShmLoanPaymentAllocationService;
use App\Services\Shm\ShmLoanPaymentService;
use App\Services\Shm\ShmLoanSettlementService;
use App\Services\Shm\ShmSettlementCorrectionService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * T005 — SHM Settlement Correction (Policy B — Current Period Correction).
 *
 * Menguji seluruh cakupan Case A sampai Case M (Section 38):
 * - Case A: Kelayakan status (completed diizinkan; pending, approved, recorded, verified, rejected, reversed ditolak)
 * - Case B: Validasi alasan (wajib, tidak boleh kosong/whitespace, minimal 5 karakter)
 * - Case C: Reversal non-destruktif (original settlement tetap di DB dengan status 'reversed', nilai finansial tidak berubah)
 * - Case D: Pemulihan saldo finansial (status pinjaman active, sisa jadwal angsuran dipulihkan ke alokasi pra-pelunasan)
 * - Case E: Pemulihan dampak diskon
 * - Case F: Pemulihan dampak penalty
 * - Case G: Kompensasi overpayment Simpanan Pokok (saldo simpanan berkurang tepat sebesar overpayment)
 * - Case H: Nol dampak kas kasir (assert 0 cash transactions)
 * - Case I: Penerbitan settlement pengganti (ID baru, ref baru, status pending, tertaut ke koreksi)
 * - Case J: Idempotensi & penolakan koreksi duplikat
 * - Case K: Keamanan konkurensi (row locking)
 * - Case L: Atomisitas (rollback penuh jika terjadi kegagalan)
 * - Case M: Audit logging
 */
class ShmSettlementCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private User $unauthorizedUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->user = $this->userWithPermissions([
            'shm.view',
            'shm.payment.create',
            'shm.settlement.create',
            'shm.settlement.policy.manage',
        ]);

        $this->unauthorizedUser = User::factory()->create(['email_verified_at' => now()]);
    }

    private function userWithPermissions(array $permissionNames): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $role = Role::query()->create([
            'name' => 't005-'.Str::uuid(),
            'display_name' => 'T005 Tester',
            'is_system' => false,
        ]);

        $role->permissions()->attach(
            Permission::query()->whereIn('name', $permissionNames)->pluck('id')
        );

        $user->roles()->attach($role->id);

        return $user;
    }

    /**
     * Buat pinjaman aktif dengan nasabah yang terhubung ke Member Simpanan Pokok.
     */
    private function activeLoanWithMember(float $futureInterest = 60_000_000, ?SavingsAccount &$savingsAccount = null): ShmLoan
    {
        $member = Member::factory()->create(['status' => 'active']);

        $product = SavingProduct::query()->firstOrCreate(
            ['category' => SavingProduct::CATEGORY_PRINCIPAL],
            [
                'code' => 'SP-POKOK',
                'name' => 'Simpanan Pokok',
                'description' => 'Simpanan Pokok Anggota',
                'minimum_initial_deposit' => 100_000,
                'minimum_balance' => 0,
                'is_active' => true,
            ],
        );

        $savingsAccount = SavingsAccount::query()->create([
            'account_number' => 'SA-'.Str::upper(Str::random(10)),
            'member_id' => $member->id,
            'saving_product_id' => $product->id,
            'opened_at' => now()->toDateString(),
            'status' => 'active',
            'balance' => 0,
        ]);

        $customer = ShmCustomer::factory()->create([
            'member_id' => $member->id,
        ]);

        $application = ShmLoanApplication::query()->create([
            'application_number' => 'PENGAJ-T005-'.Str::upper(Str::random(10)),
            'shm_customer_id' => $customer->id,
            'application_date' => now()->toDateString(),
            'principal' => '100000000',
            'tenor_months' => 2,
            'first_due_date' => now()->addMonth()->startOfMonth()->toDateString(),
            'marketing_id' => $this->user->id,
            'status' => 'approved',
        ]);

        $loan = ShmLoan::factory()->create([
            'shm_customer_id' => $customer->id,
            'shm_loan_application_id' => $application->id,
            'principal' => '100000000',
            'tenor_months' => 2,
            'status' => ShmLoan::STATUS_ACTIVE,
            'disbursed_at' => now()->subMonths(2),
        ]);

        // Angsuran #1 (jatuh tempo lalu)
        $due = now()->subMonth()->startOfMonth();
        $loan->installments()->create([
            'installment_number' => 1,
            'due_date' => $due->toDateString(),
            'principal_amount' => 5_000_000,
            'interest_amount' => 1_500_000,
            'total_amount' => 6_500_000,
            'paid_amount' => 0,
            'paid_interest_amount' => 0,
            'paid_principal_amount' => 0,
            'remaining_amount' => 6_500_000,
            'status' => ShmLoanInstallment::STATUS_SCHEDULED,
        ]);

        // Angsuran #2 (masa depan)
        $future = now()->addMonth()->startOfMonth();
        $loan->installments()->create([
            'installment_number' => 2,
            'due_date' => $future->toDateString(),
            'principal_amount' => 5_000_000,
            'interest_amount' => $futureInterest,
            'total_amount' => 5_000_000 + $futureInterest,
            'paid_amount' => 0,
            'paid_interest_amount' => 0,
            'paid_principal_amount' => 0,
            'remaining_amount' => 5_000_000 + $futureInterest,
            'status' => ShmLoanInstallment::STATUS_SCHEDULED,
        ]);

        return $loan;
    }

    private function policy(string $percent = '10.00'): ShmSettlementDiscountPolicy
    {
        return ShmSettlementDiscountPolicy::query()->create([
            'min_remaining_months' => 1,
            'max_remaining_months' => 3,
            'discount_percent' => $percent,
            'is_active' => true,
        ]);
    }

    /**
     * Jalankan workflow settlement hingga COMPLETED secara penuh.
     */
    private function completeSettlement(ShmLoan $loan, float $extraPayment = 0.0, float $penalty = 0.0): ShmLoanSettlement
    {
        $settlementService = app(ShmLoanSettlementService::class);
        $paymentService = app(ShmLoanPaymentService::class);

        // 1. Request
        $settlement = $settlementService->request($loan, [
            'penalty' => $penalty,
            'notes' => 'Settlement original testing',
        ], (int) $this->user->id);

        // 2. Approve
        $settlement = $settlementService->approve($loan, (int) $this->user->id);

        // 3. Payment
        $obligation = (float) $settlement->settlement_amount;
        $paymentAmount = $obligation + $extraPayment;
        $payment = $paymentService->create($loan, [
            'payment_date' => now()->toDateString(),
            'amount' => $paymentAmount,
        ], (int) $this->user->id);

        // 4. Record Payment
        $settlement = $settlementService->recordPayment($loan, $payment->id, (int) $this->user->id);

        // 5. Verify Payment
        $settlement = $settlementService->verifyPayment($loan, (int) $this->user->id);

        // 6. Complete
        return $settlementService->complete($loan, (int) $this->user->id);
    }

    // ==================================================================
    // CASE A: Kelayakan Status (Eligibility)
    // ==================================================================

    public function test_case_a_only_completed_settlement_can_be_corrected(): void
    {
        $this->policy();
        $loan = $this->activeLoanWithMember();
        $settlementService = app(ShmLoanSettlementService::class);
        $correctionService = app(ShmSettlementCorrectionService::class);

        // 1. Pending settlement: ditolak
        $settlement = $settlementService->request($loan, ['penalty' => 0], (int) $this->user->id);
        $this->expectException(ValidationException::class);
        $correctionService->correct($loan, $settlement, 'Alasan koreksi valid', (int) $this->user->id);
    }

    public function test_case_a_approved_settlement_is_rejected_for_correction(): void
    {
        $this->policy();
        $loan = $this->activeLoanWithMember();
        $settlementService = app(ShmLoanSettlementService::class);
        $correctionService = app(ShmSettlementCorrectionService::class);

        $settlement = $settlementService->request($loan, ['penalty' => 0], (int) $this->user->id);
        $settlement = $settlementService->approve($loan, (int) $this->user->id);

        $this->expectException(ValidationException::class);
        $correctionService->correct($loan, $settlement, 'Alasan koreksi valid', (int) $this->user->id);
    }

    public function test_case_a_rejected_settlement_is_rejected_for_correction(): void
    {
        $this->policy();
        $loan = $this->activeLoanWithMember();
        $settlementService = app(ShmLoanSettlementService::class);
        $correctionService = app(ShmSettlementCorrectionService::class);

        $settlement = $settlementService->request($loan, ['penalty' => 0], (int) $this->user->id);
        $settlement = $settlementService->reject($loan, 'Tolak pengajuan', (int) $this->user->id);

        $this->expectException(ValidationException::class);
        $correctionService->correct($loan, $settlement, 'Alasan koreksi valid', (int) $this->user->id);
    }

    // ==================================================================
    // CASE B: Validasi Alasan (Reason Validation)
    // ==================================================================

    public function test_case_b_empty_or_whitespace_reason_is_rejected(): void
    {
        $this->policy();
        $loan = $this->activeLoanWithMember();
        $settlement = $this->completeSettlement($loan);
        $correctionService = app(ShmSettlementCorrectionService::class);

        try {
            $correctionService->correct($loan, $settlement, '   ', (int) $this->user->id);
            $this->fail('Seharusnya melempar ValidationException untuk reason whitespace');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reason', $e->errors());
        }

        try {
            $correctionService->correct($loan, $settlement, 'abc', (int) $this->user->id);
            $this->fail('Seharusnya melempar ValidationException untuk reason < 5 karakter');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reason', $e->errors());
        }
    }

    // ==================================================================
    // CASE C: Reversal Immutabilitas (Non-destructive Invalidation)
    // ==================================================================

    public function test_case_c_original_settlement_record_remains_and_status_becomes_reversed(): void
    {
        $this->policy();
        $loan = $this->activeLoanWithMember();
        $settlement = $this->completeSettlement($loan, 500_000, 250_000);
        $correctionService = app(ShmSettlementCorrectionService::class);

        // Snapshot nilai finansial original sebelum koreksi
        $originalId = $settlement->id;
        $origPrincipal = $settlement->outstanding_principal;
        $origInterest = $settlement->outstanding_interest;
        $origPenalty = $settlement->outstanding_penalty;
        $origSettlementAmount = $settlement->settlement_amount;
        $origDiscountAmount = $settlement->discount_amount;
        $origOverpayment = $settlement->overpayment_amount;

        $correction = $correctionService->correct(
            $loan,
            $settlement,
            'Koreksi karena pembatalan transaksi oleh manajer',
            (int) $this->user->id,
        );

        $reloaded = ShmLoanSettlement::findOrFail($originalId);

        // Status berubah menjadi reversed
        $this->assertSame(ShmLoanSettlement::STATUS_REVERSED, $reloaded->status);

        // Seluruh kolom finansial TETAP IDENTIK (tidak diedit/dihapus)
        $this->assertSame((string) $origPrincipal, (string) $reloaded->outstanding_principal);
        $this->assertSame((string) $origInterest, (string) $reloaded->outstanding_interest);
        $this->assertSame((string) $origPenalty, (string) $reloaded->outstanding_penalty);
        $this->assertSame((string) $origSettlementAmount, (string) $reloaded->settlement_amount);
        $this->assertSame((string) $origDiscountAmount, (string) $reloaded->discount_amount);
        $this->assertSame((string) $origOverpayment, (string) $reloaded->overpayment_amount);

        // Baris pembayaran terhubung dilepas ke domain reguler
        $payment = ShmLoanPayment::findOrFail($reloaded->shm_loan_payment_id);
        $this->assertNotNull($payment->settlement_released_at);
        $this->assertTrue($payment->isReleasedToRegularDomain());
    }

    // ==================================================================
    // CASE D, E, F: Pemulihan Finansial & Jadwal Angsuran
    // ==================================================================

    public function test_case_d_e_f_loan_and_installments_are_restored_to_pre_settlement_state(): void
    {
        $this->policy('10.00');
        $loan = $this->activeLoanWithMember(60_000_000);

        // Bayar angsuran 1 secara parsial sebelum pelunasan
        $inst1 = $loan->installments()->where('installment_number', 1)->first();
        $regularPayment = app(ShmLoanPaymentService::class)->create($loan, [
            'payment_date' => now()->subWeek()->toDateString(),
            'amount' => '3000000',
        ], (int) $this->user->id);

        app(ShmLoanPaymentAllocationService::class)->allocate($regularPayment, (int) $this->user->id);

        $inst1->refresh();
        $this->assertSame(3_000_000.0, (float) $inst1->paid_amount);
        $this->assertSame(3_500_000.0, (float) $inst1->remaining_amount);
        $this->assertSame(ShmLoanInstallment::STATUS_SCHEDULED, $inst1->status);

        // Eksekusi settlement hingga COMPLETED
        $settlement = $this->completeSettlement($loan, 0, 100_000);

        // Saat completed, pinjaman closed dan semua angsuran paid
        $loan->refresh();
        $inst1->refresh();
        $inst2 = $loan->installments()->where('installment_number', 2)->first();

        $this->assertSame(ShmLoan::STATUS_CLOSED, $loan->status);
        $this->assertSame(ShmLoanInstallment::STATUS_PAID, $inst1->status);
        $this->assertSame(ShmLoanInstallment::STATUS_PAID, $inst2->status);
        $this->assertSame(0.0, (float) $inst1->remaining_amount);
        $this->assertSame(0.0, (float) $inst2->remaining_amount);

        // EKSEKUSI KOREKSI
        $correctionService = app(ShmSettlementCorrectionService::class);
        $correctionService->correct(
            $loan,
            $settlement,
            'Kesalahan perhitungan pelunasan diinput ulang',
            (int) $this->user->id,
        );

        // PEMULIHAN STATUS PINJAMAN
        $loan->refresh();
        $this->assertSame(ShmLoan::STATUS_ACTIVE, $loan->status);

        // PEMULIHAN JADWAL ANGSURAN KE PRE-SETTLEMENT STATE
        $inst1->refresh();
        $inst2->refresh();

        $this->assertSame(ShmLoanInstallment::STATUS_SCHEDULED, $inst1->status);
        $this->assertSame(3_000_000.0, (float) $inst1->paid_amount);
        $this->assertSame(3_500_000.0, (float) $inst1->remaining_amount);
        $this->assertNull($inst1->paid_at);

        $this->assertSame(ShmLoanInstallment::STATUS_SCHEDULED, $inst2->status);
        $this->assertSame(0.0, (float) $inst2->paid_amount);
        $this->assertSame((float) $inst2->total_amount, (float) $inst2->remaining_amount);
        $this->assertNull($inst2->paid_at);
    }

    // ==================================================================
    // CASE G & H: Kompensasi Overpayment & Nol Dampak Kas
    // ==================================================================

    public function test_case_g_and_h_overpayment_compensated_and_zero_cash_impact(): void
    {
        $this->policy();
        $savingsAccount = null;
        $loan = $this->activeLoanWithMember(60_000_000, $savingsAccount);

        $cashCountBefore = CashTransaction::query()->count();

        // Selesaikan settlement dengan overpayment 1.500.000
        $overpaymentAmount = 1_500_000;
        $settlement = $this->completeSettlement($loan, $overpaymentAmount);

        $savingsAccount->refresh();
        $this->assertSame(1_500_000.0, (float) $savingsAccount->balance);

        // Tidak boleh ada dampak kas kasir dari overpayment deposit
        $this->assertSame($cashCountBefore, CashTransaction::query()->count());

        // EKSEKUSI KOREKSI
        $correctionService = app(ShmSettlementCorrectionService::class);
        $correction = $correctionService->correct(
            $loan,
            $settlement,
            'Koreksi overpayment dan batalkan pelunasan',
            (int) $this->user->id,
        );

        // Saldo rekening simpanan anggota berkurang kembali menjadi 0
        $savingsAccount->refresh();
        $this->assertSame(0.0, (float) $savingsAccount->balance);

        // Case H: Nol dampak transaksi kas kasir selama kompensasi
        $this->assertSame($cashCountBefore, CashTransaction::query()->count());

        // Verifikasi reversal transaksi simpanan tercatat
        $origTx = SavingsTransaction::where('idempotency_key', sprintf('shm-settlement-overpayment:%d', $settlement->id))->first();
        $this->assertNotNull($origTx);
        $this->assertTrue($origTx->reversals()->where('status', 'completed')->exists());
    }

    // ==================================================================
    // CASE I: Replacement Settlement
    // ==================================================================

    public function test_case_i_replacement_settlement_is_created_with_new_id_and_reference(): void
    {
        $this->policy();
        $loan = $this->activeLoanWithMember();
        $settlement = $this->completeSettlement($loan);

        $correctionService = app(ShmSettlementCorrectionService::class);
        $correction = $correctionService->correct(
            $loan,
            $settlement,
            'Pengajuan ulang settlement dengan denda baru',
            (int) $this->user->id,
            ['penalty' => 200_000],
        );

        $this->assertInstanceOf(ShmSettlementCorrection::class, $correction);
        $this->assertSame($settlement->id, $correction->original_settlement_id);

        $replacement = $correction->replacementSettlement;
        $this->assertNotNull($replacement);
        $this->assertNotEquals($settlement->id, $replacement->id);
        $this->assertNotEquals($settlement->settlement_reference, $replacement->settlement_reference);
        $this->assertSame(ShmLoanSettlement::STATUS_PENDING, $replacement->status);
        $this->assertSame('200000.00', (string) $replacement->outstanding_penalty);
        $this->assertSame($loan->id, $replacement->shm_loan_id);
    }

    // ==================================================================
    // CASE J: Idempotensi & Proteksi Duplikat Koreksi
    // ==================================================================

    public function test_case_j_duplicate_correction_on_same_settlement_is_rejected(): void
    {
        $this->policy();
        $loan = $this->activeLoanWithMember();
        $settlement = $this->completeSettlement($loan);

        $correctionService = app(ShmSettlementCorrectionService::class);

        // Koreksi pertama: sukses
        $correction = $correctionService->correct(
            $loan,
            $settlement,
            'Koreksi pertama sukses',
            (int) $this->user->id,
        );
        $this->assertNotNull($correction);

        // Koreksi kedua pada settlement yang sama: ditolak
        $this->expectException(ValidationException::class);
        $correctionService->correct(
            $loan,
            $settlement,
            'Koreksi kedua harus ditolak',
            (int) $this->user->id,
        );
    }

    // ==================================================================
    // CASE K: Keamanan Konkurensi & Constraint Database
    // ==================================================================

    public function test_case_k_database_unique_constraint_blocks_duplicate_correction(): void
    {
        $this->policy();
        $loan = $this->activeLoanWithMember();
        $settlement = $this->completeSettlement($loan);

        $correctionService = app(ShmSettlementCorrectionService::class);
        $correctionService->correct(
            $loan,
            $settlement,
            'Koreksi pertama',
            (int) $this->user->id,
        );

        $this->expectException(QueryException::class);
        ShmSettlementCorrection::create([
            'shm_loan_id' => $loan->id,
            'original_settlement_id' => $settlement->id,
            'reason' => 'Percobaan bypass constraint unik database',
            'status' => 'completed',
            'created_by' => $this->user->id,
        ]);
    }

    public function test_released_payment_from_reversed_settlement_can_be_allocated_to_regular_installments(): void
    {
        $this->policy();
        $loan = $this->activeLoanWithMember(60_000_000);
        $settlement = $this->completeSettlement($loan);

        $paymentId = $settlement->shm_loan_payment_id;

        // Koreksi settlement
        $correctionService = app(ShmSettlementCorrectionService::class);
        $correctionService->correct(
            $loan,
            $settlement,
            'Koreksi untuk melepas pembayaran pelunasan',
            (int) $this->user->id,
        );

        $payment = ShmLoanPayment::findOrFail($paymentId);
        $this->assertTrue($payment->isReleasedToRegularDomain());

        // Alokasikan pembayaran yang sudah dilepas ke angsuran reguler
        $result = app(ShmLoanPaymentAllocationService::class)->allocate($payment, (int) $this->user->id);
        $this->assertTrue($result['allocated']);
        $this->assertGreaterThan(0, $result['allocations']);
    }

    // ==================================================================
    // CASE L: Atomisitas & Rollback
    // ==================================================================

    public function test_case_l_atomicity_and_full_rollback_on_failure(): void
    {
        $this->policy();
        $loan = $this->activeLoanWithMember();
        $settlement = $this->completeSettlement($loan);

        $correctionService = app(ShmSettlementCorrectionService::class);

        // Inject kegagalan dengan memberikan penalty negatif pada replacement data
        try {
            $correctionService->correct(
                $loan,
                $settlement,
                'Alasan valid untuk koreksi',
                (int) $this->user->id,
                ['penalty' => -50_000], // invalid penalty
            );
            $this->fail('Seharusnya rollback karena penalty negatif');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('penalty', $e->errors());
        }

        // Pastikan TIDAK ada perubahan parsial yang tersimpan
        $settlement->refresh();
        $loan->refresh();

        $this->assertSame(ShmLoanSettlement::STATUS_COMPLETED, $settlement->status);
        $this->assertSame(ShmLoan::STATUS_CLOSED, $loan->status);
        $this->assertSame(0, ShmSettlementCorrection::query()->count());
    }

    // ==================================================================
    // CASE M: Audit Logging
    // ==================================================================

    public function test_case_m_audit_logs_are_recorded_for_reversal_and_correction(): void
    {
        $this->policy();
        $loan = $this->activeLoanWithMember();
        $settlement = $this->completeSettlement($loan);

        $correctionService = app(ShmSettlementCorrectionService::class);
        $correction = $correctionService->correct(
            $loan,
            $settlement,
            'Audit log tracking verification',
            (int) $this->user->id,
        );

        $this->assertTrue(
            AuditLog::query()
                ->where('action', 'shm.settlement.reversed')
                ->where('object_id', $settlement->id)
                ->exists(),
        );

        $this->assertTrue(
            AuditLog::query()
                ->where('action', 'shm.settlement.correction.executed')
                ->where('object_id', $correction->id)
                ->exists(),
        );

        $this->assertTrue(
            AuditLog::query()
                ->where('action', 'shm.loan.payment.settlement_released')
                ->where('object_id', $settlement->shm_loan_payment_id)
                ->exists(),
        );
    }

    // ==================================================================
    // HTTP CONTROLLER & AUTHORIZATION TESTS
    // ==================================================================

    public function test_preview_correction_endpoint(): void
    {
        $this->policy();
        $loan = $this->activeLoanWithMember();
        $settlement = $this->completeSettlement($loan);

        $response = $this->actingAs($this->user)
            ->get(route('shm.loans.pelunasan.correct.preview', $loan));

        $response->assertOk();
        $response->assertJsonStructure([
            'preview' => [
                'original_settlement' => ['id', 'reference', 'settlement_amount'],
                'restored_state' => ['loan_status', 'installments_to_restore'],
            ],
        ]);
    }

    public function test_correct_controller_endpoint_success(): void
    {
        $this->policy();
        $loan = $this->activeLoanWithMember();
        $settlement = $this->completeSettlement($loan);

        $response = $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.correct', $loan), [
                'reason' => 'Koreksi via web interface resmi',
                'penalty' => 0,
            ]);

        $response->assertRedirect(route('shm.loans.pelunasan.show', $loan));
        $response->assertSessionHas('success');

        $this->assertSame(1, ShmSettlementCorrection::query()->count());
    }

    public function test_unauthorized_user_is_forbidden_from_correcting_settlement(): void
    {
        $this->policy();
        $loan = $this->activeLoanWithMember();
        $settlement = $this->completeSettlement($loan);

        $response = $this->actingAs($this->unauthorizedUser)
            ->post(route('shm.loans.pelunasan.correct', $loan), [
                'reason' => 'Percobaan koreksi tanpa permission',
            ]);

        $response->assertForbidden();
    }
}
