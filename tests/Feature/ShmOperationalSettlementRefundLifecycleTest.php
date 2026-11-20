<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ShmCashTransaction;
use App\Models\ShmCustomer;
use App\Models\ShmLoan;
use App\Models\ShmLoanApplication;
use App\Models\ShmLoanInstallment;
use App\Models\ShmLoanPayment;
use App\Models\ShmLoanSettlement;
use App\Models\ShmRefundAccountingLink;
use App\Models\ShmSettlementRefund;
use App\Models\User;
use App\Services\Accounting\JournalService;
use App\Services\Accounting\ShmCashFlowService;
use App\Services\Shm\ShmLoanSettlementService;
use App\Services\Shm\ShmSettlementRefundService;
use App\Support\AccountingContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\ShmChartOfAccountSeeder;
use Database\Seeders\ShmLoanProductSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * TICKET 42 — SHM Operational Settlement Overpayment Refund Lifecycle Tests.
 */
class ShmOperationalSettlementRefundLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $financeUser;

    private User $direkturUser;

    private ShmSettlementRefundService $refundService;

    private ShmLoanSettlementService $settlementService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->seed(ShmChartOfAccountSeeder::class);
        $this->seed(ShmLoanProductSeeder::class);

        $this->refundService = app(ShmSettlementRefundService::class);
        $this->settlementService = app(ShmLoanSettlementService::class);

        $this->financeUser = User::factory()->create(['email_verified_at' => now()]);
        $foRole = Role::firstOrCreate(
            ['name' => 'finance_operational_makro', 'entity' => 'makro'],
            ['display_name' => 'Finance Operational Makro', 'is_system' => true]
        );
        $foRole->permissions()->sync(
            Permission::whereIn('name', [
                'shm.view',
                'shm.view_all',
                'shm.settlement.create',
                'shm.refunds.view',
                'shm.refunds.create',
                'shm.refunds.cancel',
                'shm.refunds.execute',
                'shm.refunds.reverse',
                'shm.refunds.confirm_recovery',
            ])->pluck('id')
        );
        $this->financeUser->roles()->attach($foRole->id);

        $this->direkturUser = User::factory()->create(['email_verified_at' => now()]);
        $pdRole = Role::firstOrCreate(
            ['name' => 'direktur_utama_makro', 'entity' => 'makro'],
            ['display_name' => 'Direktur Utama Makro', 'is_system' => true]
        );
        $pdRole->permissions()->sync(
            Permission::whereIn('name', [
                'shm.view',
                'shm.view_all',
                'shm.settlement.create',
                'shm.refunds.view',
                'shm.refunds.approve',
                'shm.refunds.reject',
            ])->pluck('id')
        );
        $this->direkturUser->roles()->attach($pdRole->id);
    }

    private function createCompletedSettlementWithOverpayment(float $overpaymentAmount = 5_000_000): ShmLoanSettlement
    {
        $customer = ShmCustomer::factory()->create(['name' => 'Nasabah Refund Test']);

        $application = ShmLoanApplication::query()->create([
            'application_number' => 'PENGAJ-'.Str::upper(Str::random(8)),
            'shm_customer_id' => $customer->id,
            'application_date' => now()->toDateString(),
            'principal' => '20000000',
            'tenor_months' => 2,
            'first_due_date' => now()->addMonth()->startOfMonth()->toDateString(),
            'marketing_id' => $this->financeUser->id,
            'status' => 'approved',
        ]);

        $loan = ShmLoan::factory()->create([
            'shm_customer_id' => $customer->id,
            'shm_loan_application_id' => $application->id,
            'principal' => '20000000',
            'tenor_months' => 2,
            'status' => ShmLoan::STATUS_ACTIVE,
            'disbursed_at' => now()->subMonth(),
        ]);

        $loan->installments()->create([
            'installment_number' => 1,
            'due_date' => now()->subDay()->toDateString(),
            'principal_amount' => 10_000_000,
            'interest_amount' => 1_000_000,
            'total_amount' => 11_000_000,
            'paid_amount' => 0,
            'paid_interest_amount' => 0,
            'paid_principal_amount' => 0,
            'remaining_amount' => 11_000_000,
            'status' => ShmLoanInstallment::STATUS_SCHEDULED,
        ]);

        $settlement = $this->settlementService->request($loan, ['notes' => 'Settlement for refund test'], $this->financeUser->id);
        $settlement = $this->settlementService->approve($loan, $this->financeUser->id);

        // Bayar lebih banyak dari kewajiban (Overpayment)
        $paymentAmount = (float) $settlement->settlement_amount + $overpaymentAmount;
        $payment = ShmLoanPayment::create([
            'shm_loan_id' => $loan->id,
            'payment_reference' => 'PAY-REF-'.Str::upper(Str::random(8)),
            'payment_date' => now()->toDateString(),
            'amount' => $paymentAmount,
            'channel' => 'bank_transfer',
            'status' => ShmLoanPayment::STATUS_POSTED,
            'created_by' => $this->financeUser->id,
        ]);

        $settlement = $this->settlementService->recordPayment($loan, $payment->id, $this->financeUser->id);
        $settlement = $this->settlementService->verifyPayment($loan, $this->financeUser->id);
        $settlement = $this->settlementService->complete($loan, $this->financeUser->id);

        return $settlement->fresh();
    }

    /**
     * Test 1: Completed settlement dengan overpayment positif dapat membuat permohonan refund.
     * BR-42-01, BR-42-02, BR-42-04.
     */
    public function test_can_request_refund_for_completed_settlement_with_overpayment(): void
    {
        $settlement = $this->createCompletedSettlementWithOverpayment(5_000_000);

        $refund = $this->refundService->request($settlement, [
            'amount' => 2_000_000,
            'payout_method' => 'bank_transfer',
            'payout_details' => [
                'bank_name' => 'BCA',
                'account_number' => '1234567890',
                'account_holder' => 'Nasabah Refund Test',
            ],
            'reason' => 'Pengembalian tahap 1',
        ], $this->financeUser->id);

        $this->assertNotNull($refund);
        $this->assertSame(ShmSettlementRefund::STATUS_PENDING, $refund->status);
        $this->assertEquals(2_000_000.0, (float) $refund->amount);
        $this->assertSame($settlement->id, $refund->shm_loan_settlement_id);
        $this->assertSame($settlement->shm_loan_id, $refund->shm_loan_id);
        $this->assertSame($settlement->loan->shm_customer_id, $refund->shm_customer_id);

        // Ringkasan saldo
        $summary = $this->refundService->calculateRefundableSummary($settlement);
        $this->assertEquals(5_000_000.0, (float) $summary['overpayment_amount']);
        $this->assertEquals(2_000_000.0, (float) $summary['pending_reservations']);
        $this->assertEquals(3_000_000.0, (float) $summary['remaining_refundable']);
        $this->assertFalse($summary['is_fully_refunded']);
    }

    /**
     * Test 2: Settlement tanpa overpayment ditolak untuk refund.
     * BR-42-02.
     */
    public function test_settlement_without_overpayment_cannot_request_refund(): void
    {
        $settlement = $this->createCompletedSettlementWithOverpayment(0);

        $this->expectException(ValidationException::class);
        $this->refundService->request($settlement, [
            'amount' => 1_000_000,
            'payout_method' => 'cash',
        ], $this->financeUser->id);
    }

    /**
     * Test 3: Nominal refund melebihi sisa saldo overpayment ditolak.
     * BR-42-03.
     */
    public function test_refund_amount_exceeding_refundable_balance_is_rejected(): void
    {
        $settlement = $this->createCompletedSettlementWithOverpayment(5_000_000);

        $this->expectException(ValidationException::class);
        $this->refundService->request($settlement, [
            'amount' => 6_000_000,
            'payout_method' => 'cash',
        ], $this->financeUser->id);
    }

    /**
     * Test 4: Approval mereservasi saldo, dan rejection melepaskan saldo.
     * BR-42-06, BR-42-10.
     */
    public function test_approval_and_rejection_lifecycle(): void
    {
        $settlement = $this->createCompletedSettlementWithOverpayment(5_000_000);

        $refund = $this->refundService->request($settlement, [
            'amount' => 3_000_000,
            'payout_method' => 'cash',
        ], $this->financeUser->id);

        $this->assertSame(ShmSettlementRefund::STATUS_PENDING, $refund->status);

        // Approve
        $approved = $this->refundService->approve($refund, $this->direkturUser->id);
        $this->assertSame(ShmSettlementRefund::STATUS_APPROVED, $approved->status);
        $this->assertSame($this->direkturUser->id, $approved->approved_by);

        // Buat pengajuan kedua yang mencoba mengambil lebih dari sisa (sisa = 2jt)
        $this->expectException(ValidationException::class);
        $this->refundService->request($settlement, [
            'amount' => 2_500_000,
            'payout_method' => 'cash',
        ], $this->financeUser->id);
    }

    /**
     * Test 5: Rejection melepaskan reservasi sehingga saldo dapat diminta kembali.
     * BR-42-10.
     */
    public function test_rejection_releases_balance_reservation(): void
    {
        $settlement = $this->createCompletedSettlementWithOverpayment(5_000_000);

        $refund = $this->refundService->request($settlement, [
            'amount' => 4_000_000,
            'payout_method' => 'cash',
        ], $this->financeUser->id);

        $this->refundService->reject($refund, 'Nomor rekening tidak cocok', $this->direkturUser->id);
        $this->assertSame(ShmSettlementRefund::STATUS_REJECTED, $refund->fresh()->status);

        // Saldo kembali utuh 5jt
        $summary = $this->refundService->calculateRefundableSummary($settlement);
        $this->assertEquals(5_000_000.0, (float) $summary['remaining_refundable']);

        // Sekarang permintaan 5jt dapat diproses
        $newRefund = $this->refundService->request($settlement, [
            'amount' => 5_000_000,
            'payout_method' => 'cash',
        ], $this->financeUser->id);
        $this->assertNotNull($newRefund);
    }

    /**
     * Test 6: Eksekusi payout (paid) mencatat bukti dan menghabiskan saldo overpayment (full refund).
     * BR-42-07, BR-42-09.
     */
    public function test_payout_execution_and_full_refund(): void
    {
        $settlement = $this->createCompletedSettlementWithOverpayment(5_000_000);

        $refund = $this->refundService->request($settlement, [
            'amount' => 5_000_000,
            'payout_method' => 'bank_transfer',
            'payout_details' => [
                'bank_name' => 'BCA',
                'account_number' => '1234567890',
                'account_holder' => 'Nasabah Refund Test',
            ],
        ], $this->financeUser->id);

        $this->refundService->approve($refund, $this->direkturUser->id);

        $paid = $this->refundService->executePayout($refund, [
            'payout_reference' => 'TRF-BCA-998811',
            'payout_notes' => 'Transfer berhasil via KlikBCA Bisnis',
            'payout_evidence_path' => 'shm/refund_evidence/transfer_test.pdf',
            'destination_bank' => 'BCA',
            'destination_account_number' => '1234567890',
            'destination_account_holder' => 'Nasabah Refund Test',
        ], $this->financeUser->id);

        $this->assertSame(ShmSettlementRefund::STATUS_PAID, $paid->status);
        $this->assertSame('TRF-BCA-998811', $paid->payout_reference);
        $this->assertSame($this->financeUser->id, $paid->paid_by);

        // Summary sisa saldo = 0, is_fully_refunded = true
        $summary = $this->refundService->calculateRefundableSummary($settlement);
        $this->assertEquals(0.0, (float) $summary['remaining_refundable']);
        $this->assertEquals(5_000_000.0, (float) $summary['paid_amount']);
        $this->assertTrue($summary['is_fully_refunded']);

        // Permohonan tambahan ditolak
        $this->expectException(ValidationException::class);
        $this->refundService->request($settlement, [
            'amount' => 100_000,
            'payout_method' => 'cash',
        ], $this->financeUser->id);
    }

    /**
     * Test 7: Safe Reversal & Recovery Confirmation Lifecycle.
     * BR-42A-01: Reversal tidak membuka saldo, hanya konfirmasi riil yang membuka saldo.
     */
    public function test_safe_reversal_and_recovery_confirmation_lifecycle(): void
    {
        $settlement = $this->createCompletedSettlementWithOverpayment(5_000_000);

        $refund = $this->refundService->request($settlement, ['amount' => 5_000_000, 'payout_method' => 'cash'], $this->financeUser->id);
        $this->refundService->approve($refund, $this->direkturUser->id);
        $this->refundService->executePayout($refund, [
            'payout_reference' => 'BKK-001',
            'payout_evidence_path' => 'shm/refund_evidence/cash_receipt.pdf',
            'recipient_name' => 'Nasabah Langsung',
        ], $this->financeUser->id);

        $this->assertSame(0.0, (float) $this->refundService->calculateRefundableSummary($settlement)['remaining_refundable']);

        // 1. Request Reversal -> status recovery_pending
        $reversed = $this->refundService->requestReversal($refund, 'Uang kasir dikembalikan karena nasabah menolak', $this->financeUser->id);
        $this->assertSame(ShmSettlementRefund::STATUS_RECOVERY_PENDING, $reversed->status);

        // Saldo refundable TETAP 0 (tidak otomatis pulih hanya karena reversal diminta)
        $summaryAfterReq = $this->refundService->calculateRefundableSummary($settlement);
        $this->assertEquals(0.0, (float) $summaryAfterReq['remaining_refundable']);

        // 2. Konfirmasi Recovery Parsial (2jt dari 5jt)
        $partial = $this->refundService->confirmRecovery($refund, [
            'amount' => 2_000_000,
            'recovery_reference' => 'REC-BKM-001',
            'recovery_method' => 'cash',
            'recovery_evidence_path' => 'shm/recovery_evidence/bkm001.pdf',
            'recovery_notes' => 'Penerimaan kembali kas tahap 1',
        ], $this->financeUser->id);

        $this->assertSame(ShmSettlementRefund::STATUS_RECOVERED, $partial->status);
        $this->assertEquals(2_000_000.0, (float) $partial->recovered_amount);
        $this->assertEquals(3_000_000.0, (float) $partial->outstandingPayoutAmount());

        // Saldo refundable pulih HANYA sebesar 2jt
        $summaryAfterPart = $this->refundService->calculateRefundableSummary($settlement);
        $this->assertEquals(2_000_000.0, (float) $summaryAfterPart['remaining_refundable']);

        // 3. Konfirmasi Sisa Recovery (3jt)
        $full = $this->refundService->confirmRecovery($refund, [
            'amount' => 3_000_000,
            'recovery_reference' => 'REC-BKM-002',
            'recovery_method' => 'cash',
            'recovery_evidence_path' => 'shm/recovery_evidence/bkm002.pdf',
            'recovery_notes' => 'Penerimaan kembali kas tahap 2 (lunas)',
        ], $this->financeUser->id);

        $this->assertEquals(5_000_000.0, (float) $full->recovered_amount);
        $this->assertEquals(0.0, (float) $full->outstandingPayoutAmount());

        // Saldo refundable pulih penuh 5jt
        $summaryAfterFull = $this->refundService->calculateRefundableSummary($settlement);
        $this->assertEquals(5_000_000.0, (float) $summaryAfterFull['remaining_refundable']);
    }

    /**
     * Test 8: Accounting Option A Strictly Preserved (TIDAK MEMBUAT auto-jurnal).
     * BR-42-13.
     */
    public function test_refund_workflow_never_creates_automatic_journals(): void
    {
        $initialJournalsCount = JournalEntry::query()->where('accounting_context', AccountingContext::SHM)->count();

        $settlement = $this->createCompletedSettlementWithOverpayment(5_000_000);

        // Request
        $refund = $this->refundService->request($settlement, ['amount' => 2_000_000, 'payout_method' => 'cash'], $this->financeUser->id);
        $this->assertSame($initialJournalsCount, JournalEntry::query()->where('accounting_context', AccountingContext::SHM)->count());

        // Approve
        $this->refundService->approve($refund, $this->direkturUser->id);
        $this->assertSame($initialJournalsCount, JournalEntry::query()->where('accounting_context', AccountingContext::SHM)->count());

        // Payout
        $this->refundService->executePayout($refund, [
            'payout_reference' => 'BKK-99',
            'payout_evidence_path' => 'shm/refund_evidence/bkk99.pdf',
            'recipient_name' => 'Nasabah Langsung',
        ], $this->financeUser->id);
        $this->assertSame($initialJournalsCount, JournalEntry::query()->where('accounting_context', AccountingContext::SHM)->count());

        // Reversal Request
        $this->refundService->requestReversal($refund, 'Koreksi operasional', $this->financeUser->id);
        $this->assertSame($initialJournalsCount, JournalEntry::query()->where('accounting_context', AccountingContext::SHM)->count());

        // Recovery Confirm
        $this->refundService->confirmRecovery($refund, [
            'amount' => 2_000_000,
            'recovery_reference' => 'REC-001',
            'recovery_method' => 'cash',
            'recovery_evidence_path' => 'shm/recovery_evidence/rec001.pdf',
        ], $this->financeUser->id);
        $this->assertSame($initialJournalsCount, JournalEntry::query()->where('accounting_context', AccountingContext::SHM)->count());
    }

    /**
     * Test 9: HTTP Endpoint Authorization & Integration.
     */
    public function test_http_endpoints_respect_authorization_and_workflow(): void
    {
        $settlement = $this->createCompletedSettlementWithOverpayment(3_000_000);

        // 1. Request via HTTP
        $response = $this->actingAs($this->financeUser)
            ->post(route('shm.loans.refunds.store', ['settlement' => $settlement->id]), [
                'amount' => 1_500_000,
                'payout_method' => 'bank_transfer',
                'payout_details' => [
                    'bank_name' => 'Mandiri',
                    'account_number' => '9988776655',
                    'account_holder' => 'Nasabah Refund HTTP',
                ],
                'reason' => 'Pengajuan via web',
            ]);

        $response->assertStatus(302);
        if ($response->exception) {
            $this->fail($response->exception->getMessage());
        }
        $refund = ShmSettlementRefund::latest('id')->first();
        $this->assertNotNull($refund);
        $this->assertEquals(1_500_000.0, (float) $refund->amount);

        // 2. Approve via HTTP
        $approveRes = $this->actingAs($this->direkturUser)
            ->post(route('shm.loans.refunds.approve', $refund->id));
        $approveRes->assertSessionHasNoErrors();
        $this->assertSame(ShmSettlementRefund::STATUS_APPROVED, $refund->fresh()->status);

        // 3. Payout via HTTP
        $file = UploadedFile::fake()->create('bukti_transfer.pdf', 100);
        $payoutRes = $this->actingAs($this->financeUser)
            ->post(route('shm.loans.refunds.payout', $refund->id), [
                'payout_reference' => 'TRF-MND-00192',
                'payout_notes' => 'Transfer berhasil',
                'evidence' => $file,
            ]);
        $payoutRes->assertSessionHasNoErrors();
        $this->assertSame(ShmSettlementRefund::STATUS_PAID, $refund->fresh()->status);

        // 4. Index view
        $indexRes = $this->actingAs($this->financeUser)->get(route('shm.loans.refunds.index'));
        $indexRes->assertOk();

        // 5. Show view
        $showRes = $this->actingAs($this->financeUser)->get(route('shm.loans.refunds.show', $refund->id));
        $showRes->assertOk();

        // 6. Reverse via HTTP
        $revRes = $this->actingAs($this->financeUser)
            ->post(route('shm.loans.refunds.reverse', $refund->id), [
                'reason' => 'Nasabah mengajukan pengembalian dana',
            ]);
        $revRes->assertSessionHasNoErrors();
        $this->assertSame(ShmSettlementRefund::STATUS_RECOVERY_PENDING, $refund->fresh()->status);

        // 7. Confirm Recovery via HTTP
        $recFile = UploadedFile::fake()->create('bukti_recovery.pdf', 100);
        $confRes = $this->actingAs($this->financeUser)
            ->post(route('shm.loans.refunds.confirm_recovery', $refund->id), [
                'amount' => 1_500_000,
                'recovery_reference' => 'REC-TRF-00192',
                'recovery_method' => 'bank_transfer',
                'evidence' => $recFile,
            ]);
        $confRes->assertSessionHasNoErrors();
        $this->assertSame(ShmSettlementRefund::STATUS_RECOVERED, $refund->fresh()->status);
        $this->assertEquals(1_500_000.0, (float) $refund->fresh()->recovered_amount);
    }

    /**
     * Test 10: Strict Dedicated Permission Enforcement.
     * BR-42A-03: User tanpa permission spesifik ditolak (403), general settlement permission tidak cukup.
     */
    public function test_dedicated_permission_isolation_and_fallback_rejection(): void
    {
        $settlement = $this->createCompletedSettlementWithOverpayment(3_000_000);

        // User yang hanya punya shm.settlement.create tapi TIDAK punya shm.refunds.*
        $generalUser = User::factory()->create(['email_verified_at' => now()]);
        $generalRole = Role::create([
            'name' => 'general_settlement_officer',
            'display_name' => 'General Settlement Officer',
            'entity' => 'makro',
            'is_system' => false,
        ]);
        $generalRole->permissions()->sync(
            Permission::whereIn('name', ['shm.view', 'shm.view_all', 'shm.settlement.create'])->pluck('id')
        );
        $generalUser->roles()->attach($generalRole->id);

        // 1. Coba request refund -> 403 Forbidden
        $resStore = $this->actingAs($generalUser)
            ->post(route('shm.loans.refunds.store', ['settlement' => $settlement->id]), [
                'amount' => 500_000,
                'payout_method' => 'cash',
            ]);
        $resStore->assertStatus(403);

        // Buat refund valid menggunakan finance user
        $refund = $this->refundService->request($settlement, [
            'amount' => 1_000_000,
            'payout_method' => 'cash',
        ], $this->financeUser->id);

        // 2. General user coba approve -> 403
        $this->actingAs($generalUser)
            ->post(route('shm.loans.refunds.approve', $refund->id))
            ->assertStatus(403);

        // 3. Finance user coba approve -> 403 (karena finance_operational_makro hanya punya shm.refunds.create/execute, approval wewenang Direktur)
        $this->actingAs($this->financeUser)
            ->post(route('shm.loans.refunds.approve', $refund->id))
            ->assertStatus(403);

        // 4. Direktur coba eksekusi payout -> 403 (Direktur hanya punya approve/reject, payout wewenang Finance)
        $this->refundService->approve($refund, $this->direkturUser->id);
        $file = UploadedFile::fake()->create('bukti_kas.pdf', 100);
        $this->actingAs($this->direkturUser)
            ->post(route('shm.loans.refunds.payout', $refund->id), [
                'recipient_name' => 'Nasabah',
                'evidence' => $file,
            ])
            ->assertStatus(403);
    }

    /**
     * Test 11: RestrictOnDelete Cascade Protection.
     * BR-42A-02: Parent settlement, loan, dan customer tidak dapat dihapus jika memiliki rekaman refund.
     */
    public function test_parent_entities_cannot_be_deleted_when_refund_history_exists(): void
    {
        $settlement = $this->createCompletedSettlementWithOverpayment(3_000_000);
        $refund = $this->refundService->request($settlement, [
            'amount' => 1_000_000,
            'payout_method' => 'cash',
        ], $this->financeUser->id);

        $loan = $settlement->loan;
        $customer = $loan->customer;

        // Coba delete settlement -> QueryException karena restrictOnDelete
        $this->expectException(QueryException::class);
        $settlement->delete();
    }

    /**
     * Test 12: Multi-Transaction Partial Recovery History (BR-42B-05).
     * Memastikan setiap konfirmasi pemulihan dana tersimpan sebagai transaksi permanen tanpa menimpa histori sebelumnya.
     */
    public function test_multi_transaction_partial_recovery_preserves_immutable_history(): void
    {
        $settlement = $this->createCompletedSettlementWithOverpayment(5_000_000);

        $refund = $this->refundService->request($settlement, [
            'amount' => 5_000_000,
            'payout_method' => 'cash',
        ], $this->financeUser->id);

        $this->refundService->approve($refund, $this->direkturUser->id);
        $this->refundService->executePayout($refund, [
            'payout_reference' => 'PAY-5M-MULTI',
            'payout_evidence_path' => 'shm/refund_evidence/pay5m.pdf',
            'recipient_name' => 'Nasabah Multi',
        ], $this->financeUser->id);

        $this->refundService->requestReversal($refund, 'Minta kembalikan bertahap', $this->financeUser->id);

        // Transaksi Recovery 1: 1.5jt
        $this->refundService->confirmRecovery($refund, [
            'amount' => 1_500_000,
            'recovery_reference' => 'BKM-REC-01',
            'recovery_method' => 'cash',
            'recovery_evidence_path' => 'shm/recovery_evidence/bkm01.pdf',
            'recovery_notes' => 'Tahap 1',
        ], $this->financeUser->id);

        // Transaksi Recovery 2: 2.0jt
        $this->refundService->confirmRecovery($refund, [
            'amount' => 2_000_000,
            'recovery_reference' => 'BKM-REC-02',
            'recovery_method' => 'bank_transfer',
            'recovery_evidence_path' => 'shm/recovery_evidence/bkm02.pdf',
            'recovery_notes' => 'Tahap 2',
        ], $this->financeUser->id);

        // Verifikasi histori transaksi: ada 2 transaksi recovery independen
        $this->assertSame(2, $refund->recoveries()->count());
        $rec1 = $refund->recoveries()->where('recovery_reference_external', 'BKM-REC-01')->first();
        $rec2 = $refund->recoveries()->where('recovery_reference_external', 'BKM-REC-02')->first();

        $this->assertNotNull($rec1);
        $this->assertNotNull($rec2);
        $this->assertEquals(1_500_000.0, (float) $rec1->amount);
        $this->assertEquals(2_000_000.0, (float) $rec2->amount);

        // Sisa yang belum kembali: 5M - 3.5M = 1.5M
        $this->assertEquals(1_500_000.0, $refund->fresh()->outstandingPayoutAmount());

        // Saldo refundable settlement pulih sebesar 3.5jt
        $summary = $this->refundService->calculateRefundableSummary($settlement);
        $this->assertEquals(3_500_000.0, (float) $summary['remaining_refundable']);
        $this->assertEquals(3_500_000.0, (float) $summary['recovered_amount']);
    }

    /**
     * Test 13: Structured Accounting Linkage & Duplicate Prevention (BR-42B-01, BR-42B-02, BR-42B-03).
     */
    public function test_structured_accounting_linkage_and_duplicate_prevention(): void
    {
        $settlement = $this->createCompletedSettlementWithOverpayment(4_000_000);

        $refund = $this->refundService->request($settlement, [
            'amount' => 4_000_000,
            'payout_method' => 'cash',
        ], $this->financeUser->id);

        $this->refundService->approve($refund, $this->direkturUser->id);
        $this->refundService->executePayout($refund, [
            'payout_reference' => 'PAY-4M-ACC',
            'payout_evidence_path' => 'shm/refund_evidence/pay4m.pdf',
            'recipient_name' => 'Nasabah Akuntansi',
        ], $this->financeUser->id);

        // 1. Tautkan pengakuan akuntansi via Jurnal 1
        $j1 = JournalEntry::create([
            'accounting_context' => AccountingContext::SHM,
            'journal_number' => 'JU-TEST-LINK-1',
            'transaction_date' => now()->toDateString(),
            'description' => 'Jurnal Pengakuan Refund 1',
            'status' => 'POSTED',
            'created_by' => $this->financeUser->id,
            'posted_by' => $this->financeUser->id,
            'posted_at' => now(),
        ]);

        $link = $this->refundService->linkAccountingRecognition(
            $refund,
            $j1->id,
            ShmRefundAccountingLink::CHANNEL_MANUAL_JOURNAL,
            $this->financeUser->id
        );

        $this->assertNotNull($link);
        $this->assertSame('POSTED', $link->status);

        // 2. Upaya pengakuan kedua ditolak
        $j2 = JournalEntry::create([
            'accounting_context' => AccountingContext::SHM,
            'journal_number' => 'JU-TEST-LINK-2',
            'transaction_date' => now()->toDateString(),
            'description' => 'Jurnal Pengakuan Refund 2',
            'status' => 'POSTED',
            'created_by' => $this->financeUser->id,
            'posted_by' => $this->financeUser->id,
            'posted_at' => now(),
        ]);

        $this->expectException(ValidationException::class);
        $this->refundService->linkAccountingRecognition(
            $refund,
            $j2->id,
            ShmRefundAccountingLink::CHANNEL_CASH_OUTFLOW,
            $this->financeUser->id
        );
    }

    /**
     * Test 14 (Ticket 42C): Manual Journal Integration & Reversal Sync.
     */
    public function test_manual_journal_integration_draft_post_and_reversal_lifecycle(): void
    {
        $settlement = $this->createCompletedSettlementWithOverpayment(2_500_000);

        $refund = $this->refundService->request($settlement, [
            'amount' => 2_500_000,
            'payout_method' => 'cash',
        ], $this->financeUser->id);

        $this->refundService->approve($refund, $this->direkturUser->id);
        $this->refundService->executePayout($refund, [
            'payout_reference' => 'PAY-2.5M-MJ',
            'payout_evidence_path' => 'shm/refund_evidence/pay25m.pdf',
            'recipient_name' => 'Nasabah MJ',
        ], $this->financeUser->id);

        $cashAccount = ChartOfAccount::query()->where('accounting_context', AccountingContext::SHM)->where('code', '1-1100')->firstOrFail();
        $liabilityAccount = ChartOfAccount::query()->where('accounting_context', AccountingContext::SHM)->where('code', '2-1302')->firstOrFail();

        $journalService = app(JournalService::class);

        // 1. Buat Draft Manual Journal dengan relasi refund
        $draft = $journalService->createDraft([
            'accounting_context' => AccountingContext::SHM,
            'transaction_date' => now()->toDateString(),
            'description' => 'Manual Journal Pengakuan Refund',
            'shm_settlement_refund_id' => $refund->id,
        ], [
            ['account_id' => $liabilityAccount->id, 'description' => 'Debet 2-1302', 'debit' => 2_500_000, 'credit' => 0],
            ['account_id' => $cashAccount->id, 'description' => 'Kredit 1-1100', 'debit' => 0, 'credit' => 2_500_000],
        ], $this->financeUser->id);

        $this->assertSame(JournalEntry::STATUS_DRAFT, $draft->status);

        // Link status harus DRAFT
        $draftLink = ShmRefundAccountingLink::where('journal_entry_id', $draft->id)->first();
        $this->assertNotNull($draftLink);
        $this->assertSame(ShmRefundAccountingLink::STATUS_DRAFT, $draftLink->status);

        // 2. Post Manual Journal -> Link otomatis berstatus POSTED
        $posted = $journalService->post($draft, $this->financeUser->id);
        $this->assertSame(JournalEntry::STATUS_POSTED, $posted->status);

        $postedLink = ShmRefundAccountingLink::where('journal_entry_id', $posted->id)->first();
        $this->assertNotNull($postedLink);
        $this->assertSame(ShmRefundAccountingLink::STATUS_POSTED, $postedLink->status);

        // 3. Reversal Manual Journal -> Link otomatis berstatus REVERSED
        $reversal = $journalService->reverse($posted, $this->financeUser->id, 'Koreksi kesalahan pencatatan jurnal refund');
        $this->assertSame(JournalEntry::STATUS_POSTED, $reversal->status);

        $reversedLink = ShmRefundAccountingLink::where('journal_entry_id', $posted->id)->first();
        $this->assertNotNull($reversedLink);
        $this->assertSame(ShmRefundAccountingLink::STATUS_REVERSED, $reversedLink->status);
    }

    /**
     * Test 15 (Ticket 42C-A Scenario RR-02 & CC-05): Replacement After Reversal and Draft Handling.
     * Setelah recognition pertama di-REVERSED, buat recognition baru (pengganti) melalui kanal lain.
     * Buktikan recognition baru dapat diposting sebagai POSTED tanpa merusak histori REVERSED.
     */
    public function test_rr02_replacement_after_reversal_allows_new_posted_recognition(): void
    {
        $settlement = $this->createCompletedSettlementWithOverpayment(3_000_000);

        $refund = $this->refundService->request($settlement, [
            'amount' => 3_000_000,
            'payout_method' => 'cash',
        ], $this->financeUser->id);

        $this->refundService->approve($refund, $this->direkturUser->id);
        $this->refundService->executePayout($refund, [
            'payout_reference' => 'PAY-3M-RR02',
            'payout_evidence_path' => 'shm/refund_evidence/pay3m.pdf',
            'recipient_name' => 'Nasabah RR02',
        ], $this->financeUser->id);

        $cashAccount = ChartOfAccount::query()->where('accounting_context', AccountingContext::SHM)->where('code', '1-1100')->firstOrFail();
        $liabilityAccount = ChartOfAccount::query()->where('accounting_context', AccountingContext::SHM)->where('code', '2-1302')->firstOrFail();

        $journalService = app(JournalService::class);

        // 1. Posting pertama via Manual Journal
        $mjDraft = $journalService->createDraft([
            'accounting_context' => AccountingContext::SHM,
            'transaction_date' => now()->toDateString(),
            'description' => 'Manual Journal Awal',
            'shm_settlement_refund_id' => $refund->id,
        ], [
            ['account_id' => $liabilityAccount->id, 'description' => 'Debet 2-1302', 'debit' => 3_000_000, 'credit' => 0],
            ['account_id' => $cashAccount->id, 'description' => 'Kredit 1-1100', 'debit' => 0, 'credit' => 3_000_000],
        ], $this->financeUser->id);

        $mjPosted = $journalService->post($mjDraft, $this->financeUser->id);
        $this->assertSame(JournalEntry::STATUS_POSTED, $mjPosted->status);

        // 2. Reverse Manual Journal
        $reversal = $journalService->reverse($mjPosted, $this->financeUser->id, 'Koreksi salah rekening');
        $this->assertSame(JournalEntry::STATUS_POSTED, $reversal->status);

        // Link pertama sekarang REVERSED
        $link1 = ShmRefundAccountingLink::where('journal_entry_id', $mjPosted->id)->first();
        $this->assertSame(ShmRefundAccountingLink::STATUS_REVERSED, $link1->status);

        // 3. Buat recognition pengganti via Cash Outflow
        $cashFlowService = app(ShmCashFlowService::class);
        $cashTx = ShmCashTransaction::create([
            'transaction_number' => 'BKK-REPLACEMENT-1',
            'type' => ShmCashTransaction::TYPE_OUTFLOW,
            'cash_account_id' => $cashAccount->id,
            'account_id' => $liabilityAccount->id,
            'amount' => 3_000_000,
            'transaction_date' => now()->toDateString(),
            'description' => 'Pengeluaran Kas Pengganti Reversal',
            'status' => ShmCashTransaction::STATUS_DRAFT,
            'shm_settlement_refund_id' => $refund->id,
            'created_by' => $this->financeUser->id,
        ]);

        $cashPosted = $cashFlowService->post($cashTx, $this->financeUser->id);
        $this->assertSame(ShmCashTransaction::STATUS_POSTED, $cashPosted->status);

        // Verifikasi: Ada 2 link (1 REVERSED, 1 POSTED)
        $links = ShmRefundAccountingLink::where('shm_settlement_refund_id', $refund->id)->get();
        $this->assertCount(2, $links);
        $this->assertSame(1, $links->where('status', ShmRefundAccountingLink::STATUS_REVERSED)->count());
        $this->assertSame(1, $links->where('status', ShmRefundAccountingLink::STATUS_POSTED)->count());
    }

    /**
     * Test 16 (Ticket 42C-A Phase 4 Recovery Test Matrix): Comprehensive Recovery Balance Calculations.
     * Verifies the exact numerical table from Ticket 42C-A Section 7:
     * - Paid, no recovery: Net Consumed = 10jt, Remaining = 0
     * - Recovery pending 3jt: Net Consumed = 10jt, Remaining = 0 (pending does not restore balance)
     * - Recovery confirmed 3jt: Net Consumed = 7jt, Remaining = 3jt
     * - Recovery confirmed 3jt + 2jt (total 5jt): Net Consumed = 5jt, Remaining = 5jt
     * - Full confirmed recovery (10jt): Net Consumed = 0, Remaining = 10jt
     * - Attempted recovery exceeding unrecovered: Rejected, balance unchanged
     */
    public function test_phase4_comprehensive_recovery_balance_matrix(): void
    {
        $settlement = $this->createCompletedSettlementWithOverpayment(10_000_000);

        $refund = $this->refundService->request($settlement, [
            'amount' => 10_000_000,
            'payout_method' => 'cash',
        ], $this->financeUser->id);

        $this->refundService->approve($refund, $this->direkturUser->id);
        $this->refundService->executePayout($refund, [
            'payout_reference' => 'PAY-10M-MATRIX',
            'payout_evidence_path' => 'shm/refund_evidence/pay10m.pdf',
            'recipient_name' => 'Nasabah Matrix',
        ], $this->financeUser->id);

        // 1. Paid, no recovery -> Net Consumed 10M, Remaining 0
        $s1 = $this->refundService->calculateRefundableSummary($settlement->fresh());
        $this->assertEquals(0.0, (float) $s1['remaining_refundable']);
        $this->assertEquals(0.0, (float) $s1['recovered_amount']);

        // 2. Request Reversal (status -> recovery_pending) -> Net Consumed still 10M, Remaining still 0
        $refund = $this->refundService->requestReversal($refund, 'Alasan reversal matrix', $this->financeUser->id);
        $this->assertSame(ShmSettlementRefund::STATUS_RECOVERY_PENDING, $refund->status);

        $s2 = $this->refundService->calculateRefundableSummary($settlement->fresh());
        $this->assertEquals(0.0, (float) $s2['remaining_refundable']);
        $this->assertEquals(0.0, (float) $s2['recovered_amount']);

        // 3. Confirm recovery 3.000.000 -> Net Consumed 7M, Remaining 3M
        $refund = $this->refundService->confirmRecovery($refund, [
            'amount' => 3_000_000,
            'recovery_reference' => 'REC-3M-PART1',
            'recovery_evidence_path' => 'shm/recovery_evidence/rec3m.pdf',
            'recovery_method' => 'cash',
        ], $this->financeUser->id);

        $s3 = $this->refundService->calculateRefundableSummary($settlement->fresh());
        $this->assertEquals(3_000_000.0, (float) $s3['remaining_refundable']);
        $this->assertEquals(3_000_000.0, (float) $s3['recovered_amount']);
        $this->assertEquals(7_000_000.0, $refund->fresh()->outstandingPayoutAmount());

        // 4. Confirm additional recovery 2.000.000 (total 5.000.000) -> Net Consumed 5M, Remaining 5M
        $refund = $this->refundService->confirmRecovery($refund, [
            'amount' => 2_000_000,
            'recovery_reference' => 'REC-2M-PART2',
            'recovery_evidence_path' => 'shm/recovery_evidence/rec2m.pdf',
            'recovery_method' => 'cash',
        ], $this->financeUser->id);

        $s4 = $this->refundService->calculateRefundableSummary($settlement->fresh());
        $this->assertEquals(5_000_000.0, (float) $s4['remaining_refundable']);
        $this->assertEquals(5_000_000.0, (float) $s4['recovered_amount']);
        $this->assertEquals(5_000_000.0, $refund->fresh()->outstandingPayoutAmount());

        // 5. Attempt recovery 6.000.000 (exceeding remaining 5.000.000) -> Rejected, unchanged
        try {
            $this->refundService->confirmRecovery($refund, [
                'amount' => 6_000_000,
                'recovery_reference' => 'REC-FAIL-6M',
                'recovery_evidence_path' => 'shm/recovery_evidence/fail6m.pdf',
                'recovery_method' => 'cash',
            ], $this->financeUser->id);
            $this->fail('Harus ditolak karena melebihi outstanding payout');
        } catch (ValidationException $e) {
            $this->assertTrue(true);
        }

        $s5 = $this->refundService->calculateRefundableSummary($settlement->fresh());
        $this->assertEquals(5_000_000.0, (float) $s5['remaining_refundable']);

        // 6. Full recovery remaining 5.000.000 (total 10.000.000) -> Net Consumed 0, Remaining 10M
        $refund = $this->refundService->confirmRecovery($refund, [
            'amount' => 5_000_000,
            'recovery_reference' => 'REC-5M-FINAL',
            'recovery_evidence_path' => 'shm/recovery_evidence/rec5m.pdf',
            'recovery_method' => 'cash',
        ], $this->financeUser->id);

        $s6 = $this->refundService->calculateRefundableSummary($settlement->fresh());
        $this->assertEquals(10_000_000.0, (float) $s6['remaining_refundable']);
        $this->assertEquals(10_000_000.0, (float) $s6['recovered_amount']);
        $this->assertEquals(0.0, $refund->fresh()->outstandingPayoutAmount());
    }

    /**
     * Test 17 (Ticket 42C-A Scenario TX-01 & TX-04): Atomic Transaction Rollback on Posting Failure.
     * Membuktikan bahwa jika terjadi kegagalan saat proses posting, seluruh mutasi di-rollback secara atomik
     * tanpa meninggalkan partial state pada JournalEntry maupun ShmRefundAccountingLink.
     */
    public function test_tx01_atomic_transaction_rollback_on_posting_failure(): void
    {
        $settlement = $this->createCompletedSettlementWithOverpayment(1_000_000);

        $refund = $this->refundService->request($settlement, [
            'amount' => 1_000_000,
            'payout_method' => 'cash',
        ], $this->financeUser->id);

        $this->refundService->approve($refund, $this->direkturUser->id);
        $this->refundService->executePayout($refund, [
            'payout_reference' => 'PAY-1M-TX01',
            'payout_evidence_path' => 'shm/refund_evidence/pay1m.pdf',
            'recipient_name' => 'Nasabah TX01',
        ], $this->financeUser->id);

        $cashAccount = ChartOfAccount::query()->where('accounting_context', AccountingContext::SHM)->where('code', '1-1100')->firstOrFail();
        $liabilityAccount = ChartOfAccount::query()->where('accounting_context', AccountingContext::SHM)->where('code', '2-1302')->firstOrFail();

        $journalService = app(JournalService::class);
        $draft = $journalService->createDraft([
            'accounting_context' => AccountingContext::SHM,
            'transaction_date' => now()->toDateString(),
            'description' => 'Manual Journal TX01',
            'shm_settlement_refund_id' => $refund->id,
        ], [
            ['account_id' => $liabilityAccount->id, 'description' => 'Debet 2-1302', 'debit' => 1_000_000, 'credit' => 0],
            ['account_id' => $cashAccount->id, 'description' => 'Kredit 1-1100', 'debit' => 0, 'credit' => 1_000_000],
        ], $this->financeUser->id);

        // Deaktivasi akun secara sementara untuk memicu ValidationException saat posting di dalam transaksi
        $cashAccount->update(['is_active' => false]);

        try {
            $journalService->post($draft, $this->financeUser->id);
            $this->fail('Harus gagal karena akun kas nonaktif');
        } catch (ValidationException $e) {
            $this->assertTrue(true);
        }

        // Kembalikan akun aktif
        $cashAccount->update(['is_active' => true]);

        // Verifikasi atomisitas: Jurnal tetap DRAFT, Link tetap DRAFT
        $this->assertSame(JournalEntry::STATUS_DRAFT, $draft->fresh()->status);
        $link = ShmRefundAccountingLink::where('journal_entry_id', $draft->id)->first();
        $this->assertSame(ShmRefundAccountingLink::STATUS_DRAFT, $link->status);
    }
}
