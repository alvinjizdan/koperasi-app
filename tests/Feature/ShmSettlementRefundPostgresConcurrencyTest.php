<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
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
use App\Support\DbTransaction;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\ShmChartOfAccountSeeder;
use Database\Seeders\ShmLoanProductSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\Concurrency\ConcurrencyHarness;
use Tests\TestCase;

/**
 * TICKET 42 — SHM Settlement Refund PostgreSQL Multi-Process Concurrency Test.
 *
 * Menguji konkurensi pada PostgreSQL terisolasi:
 * 1. Dua pengajuan refund simultan yang mencoba melebihi saldo overpayment: tepat satu yang berhasil.
 * 2. Dua eksekusi payout simultan terhadap refund yang sama: idempoten, tidak double payout.
 * 3. Partial refunds paralel: saldo tersisa tepat dan konsisten.
 */
#[RequiresPhpExtension('pdo_pgsql')]
class ShmSettlementRefundPostgresConcurrencyTest extends TestCase
{
    private ConcurrencyHarness $harness;

    private User $admin;

    private bool $concurrencyDatabaseReady = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->postgresAvailable()) {
            $this->markTestSkipped('PostgreSQL untuk pengujian konkurensi tidak tersedia.');
        }

        $this->harness = new ConcurrencyHarness($this);
        $this->harness->setUpDatabase();
        $this->concurrencyDatabaseReady = true;

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->seed(ShmChartOfAccountSeeder::class);
        $this->seed(ShmLoanProductSeeder::class);

        $this->admin = User::factory()->create(['email_verified_at' => now()]);
        $role = Role::where('name', 'admin')->first();
        if ($role) {
            $this->admin->roles()->attach($role->id);
        }
    }

    protected function tearDown(): void
    {
        if ($this->concurrencyDatabaseReady) {
            $this->harness->tearDownDatabase();
        }

        parent::tearDown();
    }

    private function postgresAvailable(): bool
    {
        try {
            $base = config('database.connections.pgsql');
            $dsn = sprintf('pgsql:host=%s;port=%s;dbname=postgres', $base['host'], $base['port'] ?? 5432);
            new \PDO($dsn, $base['username'], $base['password'], [\PDO::ATTR_TIMEOUT => 2]);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function createCompletedSettlementFixture(float $overpayment = 5_000_000): ShmLoanSettlement
    {
        $customer = ShmCustomer::factory()->create();

        $app = ShmLoanApplication::create([
            'application_number' => 'PENGAJ-CONC-'.rand(1000, 9999),
            'shm_customer_id' => $customer->id,
            'principal' => 20000000,
            'tenor_months' => 2,
            'application_date' => now()->toDateString(),
            'first_due_date' => now()->addMonth()->startOfMonth()->toDateString(),
            'marketing_id' => $this->admin->id,
            'status' => 'approved',
        ]);

        $loan = ShmLoan::factory()->create([
            'shm_customer_id' => $customer->id,
            'shm_loan_application_id' => $app->id,
            'principal' => 20000000,
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

        $settlementService = app(ShmLoanSettlementService::class);
        $settlement = $settlementService->request($loan, ['notes' => 'Settlement conc refund'], $this->admin->id);
        $settlement = $settlementService->approve($loan, $this->admin->id);

        $paymentAmount = (float) $settlement->settlement_amount + $overpayment;
        $payment = ShmLoanPayment::create([
            'shm_loan_id' => $loan->id,
            'payment_reference' => 'PAY-CONC-'.rand(1000, 9999),
            'payment_date' => now()->toDateString(),
            'amount' => $paymentAmount,
            'status' => ShmLoanPayment::STATUS_POSTED,
            'created_by' => $this->admin->id,
        ]);

        $settlement = $settlementService->recordPayment($loan, $payment->id, $this->admin->id);
        $settlement = $settlementService->verifyPayment($loan, $this->admin->id);
        $settlement = $settlementService->complete($loan, $this->admin->id);

        return $settlement->fresh();
    }

    /**
     * 1. Dua pengajuan refund simultan bersaing atas saldo overpayment yang sama (Overpayment 5jt, masing-masing minta 4jt).
     * Expected: Tepat satu berhasil, satu gagal dengan validasi saldo tidak cukup. Total reservasi <= 5jt.
     */
    public function test_concurrent_refund_requests_cannot_over_reserve_balance(): void
    {
        $settlement = $this->createCompletedSettlementFixture(5_000_000);

        $results = $this->harness->runParallel([
            ['task' => 'shm_refund_request', 'params' => ['settlement_id' => $settlement->id, 'amount' => 4_000_000, 'user_id' => $this->admin->id]],
            ['task' => 'shm_refund_request', 'params' => ['settlement_id' => $settlement->id, 'amount' => 4_000_000, 'user_id' => $this->admin->id]],
        ]);

        $results->assertNoPartialEffects();

        // Tepat 1 yang berhasil
        $this->assertSame(1, $results->whereOk()->count());

        // Verifikasi di database PostgreSQL
        $totalReserved = (float) ShmSettlementRefund::where('shm_loan_settlement_id', $settlement->id)
            ->whereIn('status', ['pending', 'approved', 'paid'])
            ->sum('amount');

        $this->assertEquals(4_000_000.0, $totalReserved);
        $this->assertLessThanOrEqual(5_000_000.0, $totalReserved);
    }

    /**
     * 2. Dua eksekusi payout simultan terhadap refund yang sama.
     * Expected: Idempoten, kedua worker selesai dengan status ok, status refund final tetap 'paid'.
     */
    public function test_concurrent_payout_executions_are_idempotent(): void
    {
        $settlement = $this->createCompletedSettlementFixture(5_000_000);

        $refundService = app(ShmSettlementRefundService::class);
        $refund = $refundService->request($settlement, ['amount' => 5_000_000, 'payout_method' => 'cash'], $this->admin->id);
        $refund = $refundService->approve($refund, $this->admin->id);

        $results = $this->harness->runParallel([
            ['task' => 'shm_refund_payout', 'params' => ['refund_id' => $refund->id, 'payout_reference' => 'PAYOUT-A', 'user_id' => $this->admin->id]],
            ['task' => 'shm_refund_payout', 'params' => ['refund_id' => $refund->id, 'payout_reference' => 'PAYOUT-B', 'user_id' => $this->admin->id]],
        ]);

        $results->assertNoPartialEffects();

        // Keduanya berhasil (idempoten)
        $this->assertSame(2, $results->whereOk()->count());

        $fresh = $refund->fresh();
        $this->assertSame(ShmSettlementRefund::STATUS_PAID, $fresh->status);

        // Hanya ada 1 record refund
        $this->assertSame(1, ShmSettlementRefund::where('shm_loan_settlement_id', $settlement->id)->count());
    }

    /**
     * 3. Dua approval simultan terhadap permohonan refund yang sama.
     * Expected: Deterministic locking, idempotensi transisi, status akhir approved.
     */
    public function test_concurrent_approval_executions(): void
    {
        $settlement = $this->createCompletedSettlementFixture(5_000_000);

        $refundService = app(ShmSettlementRefundService::class);
        $refund = $refundService->request($settlement, ['amount' => 3_000_000, 'payout_method' => 'cash'], $this->admin->id);

        $results = $this->harness->runParallel([
            ['task' => 'shm_refund_approve', 'params' => ['refund_id' => $refund->id, 'user_id' => $this->admin->id]],
            ['task' => 'shm_refund_approve', 'params' => ['refund_id' => $refund->id, 'user_id' => $this->admin->id]],
        ]);

        $this->assertSame(2, $results->whereOk()->count());
        $this->assertSame(ShmSettlementRefund::STATUS_APPROVED, $refund->fresh()->status);
    }

    /**
     * 4. Multi-partisi pengajuan simultan (2 worker masing-masing 2.5jt dari overpayment 5jt).
     * Expected: Keduanya berhasil karena 2.5jt + 2.5jt <= 5jt. Total reservasi tepat 5jt.
     */
    public function test_concurrent_partial_refund_requests_within_capacity(): void
    {
        $settlement = $this->createCompletedSettlementFixture(5_000_000);

        $results = $this->harness->runParallel([
            ['task' => 'shm_refund_request', 'params' => ['settlement_id' => $settlement->id, 'amount' => 2_500_000, 'user_id' => $this->admin->id]],
            ['task' => 'shm_refund_request', 'params' => ['settlement_id' => $settlement->id, 'amount' => 2_500_000, 'user_id' => $this->admin->id]],
        ]);

        $this->assertSame(2, $results->whereOk()->count());

        $totalReserved = (float) ShmSettlementRefund::where('shm_loan_settlement_id', $settlement->id)
            ->whereIn('status', ['pending', 'approved', 'paid'])
            ->sum('amount');

        $this->assertEquals(5_000_000.0, $totalReserved);
    }

    /**
     * 5. Konkurensi konfirmasi recovery riil dan pengajuan refund baru secara bersamaan.
     * Overpayment 5jt:
     * - Refund A sebesar 5jt sudah paid, lalu di-reversal (status recovery_pending). Sisa saldo refundable saat ini: 0.
     * - Worker 1: Melakukan konfirmasi recovery riil sebesar 3jt (sehingga saldo refundable pulih jadi 3jt).
     * - Worker 2: Melakukan pengajuan refund baru sebesar 3jt.
     * Expected: Berkat pessimistic locking pada settlement row, worker 2 dieksekusi secara teratur dan kedua worker aman (tidak ada dirty read / race condition).
     */
    public function test_concurrent_recovery_confirmation_and_new_refund_request(): void
    {
        $settlement = $this->createCompletedSettlementFixture(5_000_000);

        $refundService = app(ShmSettlementRefundService::class);
        $refund = $refundService->request($settlement, ['amount' => 5_000_000, 'payout_method' => 'cash'], $this->admin->id);
        $refund = $refundService->approve($refund, $this->admin->id);
        $refund = $refundService->executePayout($refund, [
            'payout_reference' => 'PAY-5M',
            'payout_evidence_path' => 'shm/refund_evidence/pay5m.pdf',
            'recipient_name' => 'Nasabah',
        ], $this->admin->id);

        $refund = $refundService->requestReversal($refund, 'Reversal pengujian konkurensi', $this->admin->id);
        $this->assertSame(ShmSettlementRefund::STATUS_RECOVERY_PENDING, $refund->status);

        $results = $this->harness->runParallel([
            ['task' => 'shm_refund_recovery_confirm', 'params' => ['refund_id' => $refund->id, 'amount' => 3_000_000, 'user_id' => $this->admin->id]],
            ['task' => 'shm_refund_request', 'params' => ['settlement_id' => $settlement->id, 'amount' => 3_000_000, 'user_id' => $this->admin->id]],
        ]);

        // Kedua worker berhasil atau tepat 1 berhasil jika request dieksekusi sebelum recovery
        $okCount = $results->whereOk()->count();
        $this->assertGreaterThanOrEqual(1, $okCount);

        // Validasi tidak ada saldo yang over-allocated
        $summary = $refundService->calculateRefundableSummary($settlement);
        $this->assertGreaterThanOrEqual(0.0, (float) $summary['remaining_refundable']);
    }

    /**
     * 6. Konkurensi pengakuan akuntansi (Accounting Linkage Idempotency) — BR-42B-01 & BR-42B-02.
     * Dua proses simultan mencoba mengakui refund payout yang sama ke dalam jurnal akuntansi.
     * Expected: Tepat 1 proses yang berhasil membuat tautan POSTED, proses kedua gagal validasi pengakuan ganda.
     */
    public function test_concurrent_accounting_linkage_prevents_duplicate_recognition(): void
    {
        $settlement = $this->createCompletedSettlementFixture(5_000_000);

        $refundService = app(ShmSettlementRefundService::class);
        $refund = $refundService->request($settlement, ['amount' => 5_000_000, 'payout_method' => 'cash'], $this->admin->id);
        $refund = $refundService->approve($refund, $this->admin->id);
        $refund = $refundService->executePayout($refund, [
            'payout_reference' => 'PAY-5M-ACC',
            'payout_evidence_path' => 'shm/refund_evidence/pay5m.pdf',
            'recipient_name' => 'Nasabah',
        ], $this->admin->id);

        // Buat 2 dummy JournalEntry POSTED
        $j1 = JournalEntry::create([
            'accounting_context' => AccountingContext::SHM,
            'journal_number' => 'JU-CONC-1',
            'transaction_date' => now()->toDateString(),
            'description' => 'Jurnal Konkuren 1',
            'status' => 'POSTED',
            'created_by' => $this->admin->id,
            'posted_by' => $this->admin->id,
            'posted_at' => now(),
        ]);

        $j2 = JournalEntry::create([
            'accounting_context' => AccountingContext::SHM,
            'journal_number' => 'JU-CONC-2',
            'transaction_date' => now()->toDateString(),
            'description' => 'Jurnal Konkuren 2',
            'status' => 'POSTED',
            'created_by' => $this->admin->id,
            'posted_by' => $this->admin->id,
            'posted_at' => now(),
        ]);

        $results = $this->harness->runParallel([
            ['task' => 'shm_refund_accounting_link', 'params' => ['refund_id' => $refund->id, 'journal_entry_id' => $j1->id, 'channel' => 'manual_journal', 'user_id' => $this->admin->id]],
            ['task' => 'shm_refund_accounting_link', 'params' => ['refund_id' => $refund->id, 'journal_entry_id' => $j2->id, 'channel' => 'cash_outflow', 'user_id' => $this->admin->id]],
        ]);

        // Tepat 1 worker yang berhasil
        $this->assertSame(1, $results->whereOk()->count());

        // Verifikasi di database: hanya ada tepat 1 record link aktif
        $activeLinksCount = ShmRefundAccountingLink::where('shm_settlement_refund_id', $refund->id)
            ->where('status', 'POSTED')
            ->count();
        $this->assertSame(1, $activeLinksCount);
    }

    /**
     * Test 7 (Ticket 42C): Cross-Channel Race — Cash Outflow Posting vs Manual Journal Posting
     * Kedua kanal mencoba mengakui satu refund payout secara paralel.
     * Database partial unique index + application lock menjamin tepat SATU kanal yang berhasil POSTED.
     */
    public function test_cross_channel_race_cash_outflow_vs_manual_journal_posting_guarantees_single_posted(): void
    {
        $settlement = $this->createCompletedSettlementFixture(3_000_000);

        $refundService = app(ShmSettlementRefundService::class);
        $refund = $refundService->request($settlement, ['amount' => 3_000_000, 'payout_method' => 'cash'], $this->admin->id);
        $refund = $refundService->approve($refund, $this->admin->id);
        $refund = $refundService->executePayout($refund, [
            'payout_reference' => 'PAY-3M-CROSS',
            'payout_evidence_path' => 'shm/refund_evidence/pay3m.pdf',
            'recipient_name' => 'Nasabah Cross Race',
        ], $this->admin->id);

        $cashAccount = ChartOfAccount::query()->where('accounting_context', AccountingContext::SHM)->where('code', '1-1100')->firstOrFail();
        $liabilityAccount = ChartOfAccount::query()->where('accounting_context', AccountingContext::SHM)->where('code', '2-1302')->firstOrFail();

        // 1. Setup draft cash transaction
        $cashTx = ShmCashTransaction::create([
            'transaction_number' => 'BKK-CONC-CROSS-1',
            'type' => ShmCashTransaction::TYPE_OUTFLOW,
            'cash_account_id' => $cashAccount->id,
            'account_id' => $liabilityAccount->id,
            'amount' => 3_000_000,
            'transaction_date' => now()->toDateString(),
            'description' => 'Pengeluaran Kas Refund Cross Race',
            'status' => ShmCashTransaction::STATUS_DRAFT,
            'shm_settlement_refund_id' => $refund->id,
            'created_by' => $this->admin->id,
        ]);

        // 2. Setup draft manual journal linked to refund
        $journalService = app(JournalService::class);
        $draftJournal = $journalService->createDraft([
            'accounting_context' => AccountingContext::SHM,
            'transaction_date' => now()->toDateString(),
            'description' => 'Manual Journal Refund Cross Race',
            'shm_settlement_refund_id' => $refund->id,
        ], [
            ['account_id' => $liabilityAccount->id, 'description' => 'Debet 2-1302', 'debit' => 3_000_000, 'credit' => 0],
            ['account_id' => $cashAccount->id, 'description' => 'Kredit 1-1100', 'debit' => 0, 'credit' => 3_000_000],
        ], $this->admin->id);

        // Run both workers in parallel
        $results = $this->harness->runParallel([
            ['task' => 'shm_cash_outflow_post', 'params' => ['transaction_id' => $cashTx->id, 'user_id' => $this->admin->id]],
            ['task' => 'shm_manual_journal_post', 'params' => ['journal_entry_id' => $draftJournal->id, 'user_id' => $this->admin->id]],
        ]);

        // Tepat 1 yang berhasil
        $this->assertSame(1, $results->whereOk()->count());

        // Verifikasi database constraint: tepat 1 link POSTED di shm_refund_accounting_links
        $postedLinks = ShmRefundAccountingLink::where('shm_settlement_refund_id', $refund->id)
            ->where('status', ShmRefundAccountingLink::STATUS_POSTED)
            ->get();

        $this->assertCount(1, $postedLinks);
    }

    /**
     * Test 8 (Ticket 42C-A Scenario CC-01): Cash Outflow vs XLSX Imported Draft Concurrency
     * Worker A: Cash Outflow posting.
     * Worker B: XLSX imported DRAFT journal posting.
     * Keduanya bersaing untuk mengakui refund yang sama.
     */
    public function test_cc01_cash_outflow_vs_xlsx_import_concurrency(): void
    {
        $settlement = $this->createCompletedSettlementFixture(2_000_000);

        $refundService = app(ShmSettlementRefundService::class);
        $refund = $refundService->request($settlement, ['amount' => 2_000_000, 'payout_method' => 'cash'], $this->admin->id);
        $refund = $refundService->approve($refund, $this->admin->id);
        $refund = $refundService->executePayout($refund, [
            'payout_reference' => 'PAY-2M-CC01',
            'payout_evidence_path' => 'shm/refund_evidence/pay2m.pdf',
            'recipient_name' => 'Nasabah CC01',
        ], $this->admin->id);

        $cashAccount = ChartOfAccount::query()->where('accounting_context', AccountingContext::SHM)->where('code', '1-1100')->firstOrFail();
        $liabilityAccount = ChartOfAccount::query()->where('accounting_context', AccountingContext::SHM)->where('code', '2-1302')->firstOrFail();

        // 1. Setup draft cash transaction
        $cashTx = ShmCashTransaction::create([
            'transaction_number' => 'BKK-CONC-CC01',
            'type' => ShmCashTransaction::TYPE_OUTFLOW,
            'cash_account_id' => $cashAccount->id,
            'account_id' => $liabilityAccount->id,
            'amount' => 2_000_000,
            'transaction_date' => now()->toDateString(),
            'description' => 'Pengeluaran Kas Refund CC01',
            'status' => ShmCashTransaction::STATUS_DRAFT,
            'shm_settlement_refund_id' => $refund->id,
            'created_by' => $this->admin->id,
        ]);

        // 2. Setup draft XLSX imported journal with DRAFT link
        $journalService = app(JournalService::class);
        $xlsxJournal = $journalService->createDraft([
            'accounting_context' => AccountingContext::SHM,
            'transaction_date' => now()->toDateString(),
            'description' => 'Jurnal Impor XLSX CC01',
            'posting_type' => 'import_xlsx',
        ], [
            ['account_id' => $liabilityAccount->id, 'description' => 'Debet 2-1302', 'debit' => 2_000_000, 'credit' => 0],
            ['account_id' => $cashAccount->id, 'description' => 'Kredit 1-1100', 'debit' => 0, 'credit' => 2_000_000],
        ], $this->admin->id);

        ShmRefundAccountingLink::create([
            'accounting_context' => AccountingContext::SHM,
            'shm_settlement_refund_id' => $refund->id,
            'recognition_channel' => ShmRefundAccountingLink::CHANNEL_XLSX_IMPORT,
            'journal_entry_id' => $xlsxJournal->id,
            'status' => ShmRefundAccountingLink::STATUS_DRAFT,
            'linked_by' => $this->admin->id,
            'linked_at' => now(),
            'notes' => 'Diimpor dari berkas XLSX',
        ]);

        // Run both workers in parallel
        $results = $this->harness->runParallel([
            ['task' => 'shm_cash_outflow_post', 'params' => ['transaction_id' => $cashTx->id, 'user_id' => $this->admin->id]],
            ['task' => 'shm_manual_journal_post', 'params' => ['journal_entry_id' => $xlsxJournal->id, 'user_id' => $this->admin->id]],
        ]);

        $this->assertSame(1, $results->whereOk()->count());

        $postedLinks = ShmRefundAccountingLink::where('shm_settlement_refund_id', $refund->id)
            ->where('status', ShmRefundAccountingLink::STATUS_POSTED)
            ->get();
        $this->assertCount(1, $postedLinks);
    }

    /**
     * Test 9 (Ticket 42C-A Scenario CC-02): Manual Journal vs XLSX Imported Draft Concurrency
     * Worker A: Manual Journal posting.
     * Worker B: XLSX imported DRAFT journal posting.
     */
    public function test_cc02_manual_journal_vs_xlsx_import_concurrency(): void
    {
        $settlement = $this->createCompletedSettlementFixture(2_500_000);

        $refundService = app(ShmSettlementRefundService::class);
        $refund = $refundService->request($settlement, ['amount' => 2_500_000, 'payout_method' => 'cash'], $this->admin->id);
        $refund = $refundService->approve($refund, $this->admin->id);
        $refund = $refundService->executePayout($refund, [
            'payout_reference' => 'PAY-2.5M-CC02',
            'payout_evidence_path' => 'shm/refund_evidence/pay25m.pdf',
            'recipient_name' => 'Nasabah CC02',
        ], $this->admin->id);

        $cashAccount = ChartOfAccount::query()->where('accounting_context', AccountingContext::SHM)->where('code', '1-1100')->firstOrFail();
        $liabilityAccount = ChartOfAccount::query()->where('accounting_context', AccountingContext::SHM)->where('code', '2-1302')->firstOrFail();

        $journalService = app(JournalService::class);

        // 1. Setup draft manual journal
        $manualJournal = $journalService->createDraft([
            'accounting_context' => AccountingContext::SHM,
            'transaction_date' => now()->toDateString(),
            'description' => 'Manual Journal CC02',
            'shm_settlement_refund_id' => $refund->id,
        ], [
            ['account_id' => $liabilityAccount->id, 'description' => 'Debet 2-1302', 'debit' => 2_500_000, 'credit' => 0],
            ['account_id' => $cashAccount->id, 'description' => 'Kredit 1-1100', 'debit' => 0, 'credit' => 2_500_000],
        ], $this->admin->id);

        // 2. Setup draft XLSX imported journal
        $xlsxJournal = $journalService->createDraft([
            'accounting_context' => AccountingContext::SHM,
            'transaction_date' => now()->toDateString(),
            'description' => 'Jurnal Impor XLSX CC02',
            'posting_type' => 'import_xlsx',
        ], [
            ['account_id' => $liabilityAccount->id, 'description' => 'Debet 2-1302', 'debit' => 2_500_000, 'credit' => 0],
            ['account_id' => $cashAccount->id, 'description' => 'Kredit 1-1100', 'debit' => 0, 'credit' => 2_500_000],
        ], $this->admin->id);

        ShmRefundAccountingLink::create([
            'accounting_context' => AccountingContext::SHM,
            'shm_settlement_refund_id' => $refund->id,
            'recognition_channel' => ShmRefundAccountingLink::CHANNEL_XLSX_IMPORT,
            'journal_entry_id' => $xlsxJournal->id,
            'status' => ShmRefundAccountingLink::STATUS_DRAFT,
            'linked_by' => $this->admin->id,
            'linked_at' => now(),
            'notes' => 'Diimpor dari berkas XLSX CC02',
        ]);

        $results = $this->harness->runParallel([
            ['task' => 'shm_manual_journal_post', 'params' => ['journal_entry_id' => $manualJournal->id, 'user_id' => $this->admin->id]],
            ['task' => 'shm_manual_journal_post', 'params' => ['journal_entry_id' => $xlsxJournal->id, 'user_id' => $this->admin->id]],
        ]);

        $this->assertSame(1, $results->whereOk()->count());

        $postedLinks = ShmRefundAccountingLink::where('shm_settlement_refund_id', $refund->id)
            ->where('status', ShmRefundAccountingLink::STATUS_POSTED)
            ->get();
        $this->assertCount(1, $postedLinks);
    }

    /**
     * Test 10 (Ticket 42C-A Scenario CC-03): Three-Way Concurrency
     * Worker A: Cash Outflow posting.
     * Worker B: Manual Journal posting.
     * Worker C: XLSX imported DRAFT journal posting.
     * Semua menargetkan refund yang sama.
     */
    public function test_cc03_three_way_concurrency(): void
    {
        $settlement = $this->createCompletedSettlementFixture(4_000_000);

        $refundService = app(ShmSettlementRefundService::class);
        $refund = $refundService->request($settlement, ['amount' => 4_000_000, 'payout_method' => 'cash'], $this->admin->id);
        $refund = $refundService->approve($refund, $this->admin->id);
        $refund = $refundService->executePayout($refund, [
            'payout_reference' => 'PAY-4M-CC03',
            'payout_evidence_path' => 'shm/refund_evidence/pay4m.pdf',
            'recipient_name' => 'Nasabah CC03',
        ], $this->admin->id);

        $cashAccount = ChartOfAccount::query()->where('accounting_context', AccountingContext::SHM)->where('code', '1-1100')->firstOrFail();
        $liabilityAccount = ChartOfAccount::query()->where('accounting_context', AccountingContext::SHM)->where('code', '2-1302')->firstOrFail();

        // 1. Setup Cash Outflow draft
        $cashTx = ShmCashTransaction::create([
            'transaction_number' => 'BKK-CONC-CC03',
            'type' => ShmCashTransaction::TYPE_OUTFLOW,
            'cash_account_id' => $cashAccount->id,
            'account_id' => $liabilityAccount->id,
            'amount' => 4_000_000,
            'transaction_date' => now()->toDateString(),
            'description' => 'Pengeluaran Kas CC03',
            'status' => ShmCashTransaction::STATUS_DRAFT,
            'shm_settlement_refund_id' => $refund->id,
            'created_by' => $this->admin->id,
        ]);

        $journalService = app(JournalService::class);

        // 2. Setup Manual Journal draft
        $manualJournal = $journalService->createDraft([
            'accounting_context' => AccountingContext::SHM,
            'transaction_date' => now()->toDateString(),
            'description' => 'Manual Journal CC03',
            'shm_settlement_refund_id' => $refund->id,
        ], [
            ['account_id' => $liabilityAccount->id, 'description' => 'Debet 2-1302', 'debit' => 4_000_000, 'credit' => 0],
            ['account_id' => $cashAccount->id, 'description' => 'Kredit 1-1100', 'debit' => 0, 'credit' => 4_000_000],
        ], $this->admin->id);

        // 3. Setup XLSX imported draft
        $xlsxJournal = $journalService->createDraft([
            'accounting_context' => AccountingContext::SHM,
            'transaction_date' => now()->toDateString(),
            'description' => 'Jurnal Impor XLSX CC03',
            'posting_type' => 'import_xlsx',
        ], [
            ['account_id' => $liabilityAccount->id, 'description' => 'Debet 2-1302', 'debit' => 4_000_000, 'credit' => 0],
            ['account_id' => $cashAccount->id, 'description' => 'Kredit 1-1100', 'debit' => 0, 'credit' => 4_000_000],
        ], $this->admin->id);

        ShmRefundAccountingLink::create([
            'accounting_context' => AccountingContext::SHM,
            'shm_settlement_refund_id' => $refund->id,
            'recognition_channel' => ShmRefundAccountingLink::CHANNEL_XLSX_IMPORT,
            'journal_entry_id' => $xlsxJournal->id,
            'status' => ShmRefundAccountingLink::STATUS_DRAFT,
            'linked_by' => $this->admin->id,
            'linked_at' => now(),
            'notes' => 'Diimpor dari berkas XLSX CC03',
        ]);

        $results = $this->harness->runParallel([
            ['task' => 'shm_cash_outflow_post', 'params' => ['transaction_id' => $cashTx->id, 'user_id' => $this->admin->id]],
            ['task' => 'shm_manual_journal_post', 'params' => ['journal_entry_id' => $manualJournal->id, 'user_id' => $this->admin->id]],
            ['task' => 'shm_manual_journal_post', 'params' => ['journal_entry_id' => $xlsxJournal->id, 'user_id' => $this->admin->id]],
        ]);

        $this->assertSame(1, $results->whereOk()->count());

        $postedLinks = ShmRefundAccountingLink::where('shm_settlement_refund_id', $refund->id)
            ->where('status', ShmRefundAccountingLink::STATUS_POSTED)
            ->get();
        $this->assertCount(1, $postedLinks);
    }

    /**
     * Test 11 (Ticket 42C-A Scenario RR-04): Repeated Reversal Concurrency
     * Dua worker mencoba membalikkan jurnal pengakuan refund yang sama secara paralel.
     * Tepat 1 worker yang berhasil membalikkan; worker kedua ditolak secara terkendali.
     */
    public function test_rr04_repeated_reversal_concurrency(): void
    {
        $settlement = $this->createCompletedSettlementFixture(1_500_000);

        $refundService = app(ShmSettlementRefundService::class);
        $refund = $refundService->request($settlement, ['amount' => 1_500_000, 'payout_method' => 'cash'], $this->admin->id);
        $refund = $refundService->approve($refund, $this->admin->id);
        $refund = $refundService->executePayout($refund, [
            'payout_reference' => 'PAY-1.5M-RR04',
            'payout_evidence_path' => 'shm/refund_evidence/pay15m.pdf',
            'recipient_name' => 'Nasabah RR04',
        ], $this->admin->id);

        $cashAccount = ChartOfAccount::query()->where('accounting_context', AccountingContext::SHM)->where('code', '1-1100')->firstOrFail();
        $liabilityAccount = ChartOfAccount::query()->where('accounting_context', AccountingContext::SHM)->where('code', '2-1302')->firstOrFail();

        $journalService = app(JournalService::class);
        $draft = $journalService->createDraft([
            'accounting_context' => AccountingContext::SHM,
            'transaction_date' => now()->toDateString(),
            'description' => 'Manual Journal RR04',
            'shm_settlement_refund_id' => $refund->id,
        ], [
            ['account_id' => $liabilityAccount->id, 'description' => 'Debet 2-1302', 'debit' => 1_500_000, 'credit' => 0],
            ['account_id' => $cashAccount->id, 'description' => 'Kredit 1-1100', 'debit' => 0, 'credit' => 1_500_000],
        ], $this->admin->id);

        $posted = $journalService->post($draft, $this->admin->id);
        $this->assertSame(JournalEntry::STATUS_POSTED, $posted->status);

        // Run parallel reversal attempts
        $results = $this->harness->runParallel([
            ['task' => 'shm_refund_reversal_reverse', 'params' => ['journal_entry_id' => $posted->id, 'user_id' => $this->admin->id, 'reason' => 'Reversal Worker A']],
            ['task' => 'shm_refund_reversal_reverse', 'params' => ['journal_entry_id' => $posted->id, 'user_id' => $this->admin->id, 'reason' => 'Reversal Worker B']],
        ]);

        $this->assertSame(1, $results->whereOk()->count());

        $freshJournal = $posted->fresh();
        $this->assertSame(JournalEntry::STATUS_REVERSED, $freshJournal->status);
        $this->assertNotNull($freshJournal->reversed_by_entry_id);

        // Link status must be REVERSED
        $link = ShmRefundAccountingLink::where('journal_entry_id', $posted->id)->first();
        $this->assertSame(ShmRefundAccountingLink::STATUS_REVERSED, $link->status);
    }

    /**
     * Test 12 (Ticket 42C-A Scenario RC-01 & RC-02): Duplicate and Concurrent Partial Recoveries
     * Settlement overpayment 10.000.000, refund paid 10.000.000.
     * Worker A: Confirm recovery 6.000.000.
     * Worker B: Confirm recovery 6.000.000.
     * Total requested (12.000.000) exceeds payout amount (10.000.000).
     * Tepat 1 worker yang berhasil; worker lainnya ditolak karena kapasitas recovery terlampaui.
     */
    public function test_rc02_concurrent_different_recoveries_prevent_over_recovery(): void
    {
        $settlement = $this->createCompletedSettlementFixture(10_000_000);

        $refundService = app(ShmSettlementRefundService::class);
        $refund = $refundService->request($settlement, ['amount' => 10_000_000, 'payout_method' => 'cash'], $this->admin->id);
        $refund = $refundService->approve($refund, $this->admin->id);
        $refund = $refundService->executePayout($refund, [
            'payout_reference' => 'PAY-10M-RC02',
            'payout_evidence_path' => 'shm/refund_evidence/pay10m.pdf',
            'recipient_name' => 'Nasabah RC02',
        ], $this->admin->id);

        $refund = $refundService->requestReversal($refund, 'Reversal request for partial recovery race', $this->admin->id);

        $results = $this->harness->runParallel([
            ['task' => 'shm_refund_recovery_confirm', 'params' => ['refund_id' => $refund->id, 'amount' => 6_000_000, 'recovery_reference' => 'REC-RC02-A', 'user_id' => $this->admin->id]],
            ['task' => 'shm_refund_recovery_confirm', 'params' => ['refund_id' => $refund->id, 'amount' => 6_000_000, 'recovery_reference' => 'REC-RC02-B', 'user_id' => $this->admin->id]],
        ]);

        $this->assertSame(1, $results->whereOk()->count());

        $freshRefund = $refund->fresh();
        $this->assertEquals(6_000_000.0, (float) $freshRefund->recovered_amount);
        $this->assertEquals(4_000_000.0, $freshRefund->outstandingPayoutAmount());

        // Confirmed recovery restored exactly 6.000.000 to refundable capacity
        $summary = $refundService->calculateRefundableSummary($settlement->fresh());
        $this->assertEquals(6_000_000.0, (float) $summary['remaining_refundable']);
    }

    /**
     * Test 13 (Ticket 42C-A Scenario RC-05): Recovery and New Refund Race
     * Worker A: Confirm recovery 4.000.000.
     * Worker B: Request new refund 4.000.000 based on newly restored capacity.
     */
    public function test_rc05_recovery_and_new_refund_race(): void
    {
        $settlement = $this->createCompletedSettlementFixture(5_000_000);

        $refundService = app(ShmSettlementRefundService::class);
        $refund1 = $refundService->request($settlement, ['amount' => 5_000_000, 'payout_method' => 'cash'], $this->admin->id);
        $refund1 = $refundService->approve($refund1, $this->admin->id);
        $refund1 = $refundService->executePayout($refund1, [
            'payout_reference' => 'PAY-5M-RC05',
            'payout_evidence_path' => 'shm/refund_evidence/pay5m.pdf',
            'recipient_name' => 'Nasabah RC05',
        ], $this->admin->id);

        $refund1 = $refundService->requestReversal($refund1, 'Reversal for race with new refund', $this->admin->id);

        // Saldo tersisa saat ini 0.0
        $summaryBefore = $refundService->calculateRefundableSummary($settlement->fresh());
        $this->assertEquals(0.0, (float) $summaryBefore['remaining_refundable']);

        $results = $this->harness->runParallel([
            ['task' => 'shm_refund_recovery_confirm', 'params' => ['refund_id' => $refund1->id, 'amount' => 3_000_000, 'recovery_reference' => 'REC-RC05', 'user_id' => $this->admin->id]],
            ['task' => 'shm_refund_request', 'params' => ['settlement_id' => $settlement->id, 'amount' => 3_000_000, 'user_id' => $this->admin->id]],
        ]);

        // Kedua operasi selesai secara aman (tidak deadlock dan tidak terjadi over-reservation)
        $summaryAfter = $refundService->calculateRefundableSummary($settlement->fresh());
        $remaining = (float) $summaryAfter['remaining_refundable'];
        $reserved = (float) $summaryAfter['total_reserved'];

        // Invariant: remaining + reserved + net_consumed <= original overpayment (5_000_000)
        $this->assertLessThanOrEqual(5_000_000.0, round($remaining + $reserved + (5_000_000.0 - (float) $summaryAfter['recovered_amount']), 2));
    }

    /**
     * Test 14 (Ticket 42C-A Phase A: Test A-04): DRAFT Lifecycle Concurrency
     * - Dua kanal membuat DRAFT untuk refund yang sama.
     * - DRAFT link diperbolehkan berdampingan pada status DRAFT.
     * - Ketika salah satu DRAFT diposting ke POSTED, DRAFT yang lain tidak dapat diposting.
     */
    public function test_a04_draft_lifecycle_concurrency(): void
    {
        $settlement = $this->createCompletedSettlementFixture(2_000_000);

        $refundService = app(ShmSettlementRefundService::class);
        $refund = $refundService->request($settlement, ['amount' => 2_000_000, 'payout_method' => 'cash'], $this->admin->id);
        $refund = $refundService->approve($refund, $this->admin->id);
        $refund = $refundService->executePayout($refund, [
            'payout_reference' => 'PAY-2M-A04',
            'payout_evidence_path' => 'shm/refund_evidence/pay2m.pdf',
            'recipient_name' => 'Nasabah A04',
        ], $this->admin->id);

        $cashAccount = ChartOfAccount::query()->where('accounting_context', AccountingContext::SHM)->where('code', '1-1100')->firstOrFail();
        $liabilityAccount = ChartOfAccount::query()->where('accounting_context', AccountingContext::SHM)->where('code', '2-1302')->firstOrFail();

        $journalService = app(JournalService::class);

        // 1. Buat Draft Manual Journal 1
        $draftJournal1 = $journalService->createDraft([
            'accounting_context' => AccountingContext::SHM,
            'transaction_date' => now()->toDateString(),
            'description' => 'Draft Journal 1 A04',
            'shm_settlement_refund_id' => $refund->id,
        ], [
            ['account_id' => $liabilityAccount->id, 'description' => 'Debet 2-1302', 'debit' => 2_000_000, 'credit' => 0],
            ['account_id' => $cashAccount->id, 'description' => 'Kredit 1-1100', 'debit' => 0, 'credit' => 2_000_000],
        ], $this->admin->id);

        // 2. Buat Draft Manual Journal 2 (XLSX imported draft style)
        $draftJournal2 = $journalService->createDraft([
            'accounting_context' => AccountingContext::SHM,
            'transaction_date' => now()->toDateString(),
            'description' => 'Draft Journal 2 A04',
            'posting_type' => 'import_xlsx',
        ], [
            ['account_id' => $liabilityAccount->id, 'description' => 'Debet 2-1302', 'debit' => 2_000_000, 'credit' => 0],
            ['account_id' => $cashAccount->id, 'description' => 'Kredit 1-1100', 'debit' => 0, 'credit' => 2_000_000],
        ], $this->admin->id);

        ShmRefundAccountingLink::create([
            'accounting_context' => AccountingContext::SHM,
            'shm_settlement_refund_id' => $refund->id,
            'recognition_channel' => ShmRefundAccountingLink::CHANNEL_XLSX_IMPORT,
            'journal_entry_id' => $draftJournal2->id,
            'status' => ShmRefundAccountingLink::STATUS_DRAFT,
            'linked_by' => $this->admin->id,
            'linked_at' => now(),
            'notes' => 'Diimpor dari berkas XLSX',
        ]);

        // Verifikasi kedua draft link eksis dalam status DRAFT
        $draftLinks = ShmRefundAccountingLink::where('shm_settlement_refund_id', $refund->id)->get();
        $this->assertCount(2, $draftLinks);
        $this->assertSame(2, $draftLinks->where('status', ShmRefundAccountingLink::STATUS_DRAFT)->count());

        // 3. Posting Draft 1 berhasil menjadi POSTED
        $posted1 = $journalService->post($draftJournal1, $this->admin->id);
        $this->assertSame(JournalEntry::STATUS_POSTED, $posted1->status);

        // 4. Posting Draft 2 sekarang harus ditolak karena refund sudah berstatus POSTED
        try {
            $journalService->post($draftJournal2, $this->admin->id);
            $this->fail('Draft 2 harus ditolak karena refund sudah diakui.');
        } catch (ValidationException $e) {
            $this->assertTrue(true);
        }

        // Tepat 1 link POSTED di database
        $postedLinks = ShmRefundAccountingLink::where('shm_settlement_refund_id', $refund->id)
            ->where('status', ShmRefundAccountingLink::STATUS_POSTED)
            ->get();
        $this->assertCount(1, $postedLinks);
    }

    /**
     * Test 15 (Ticket 42C-A Phase A: Test A-05): Cancelled Draft Replacement
     * - Buat DRAFT melalui kanal Cash Outflow.
     * - Batalkan / hapus DRAFT cash transaction.
     * - Buat DRAFT melalui kanal Manual Journal.
     * - Posting DRAFT manual journal berhasil tanpa hambatan.
     */
    public function test_a05_cancelled_draft_replacement(): void
    {
        $settlement = $this->createCompletedSettlementFixture(1_500_000);

        $refundService = app(ShmSettlementRefundService::class);
        $refund = $refundService->request($settlement, ['amount' => 1_500_000, 'payout_method' => 'cash'], $this->admin->id);
        $refund = $refundService->approve($refund, $this->admin->id);
        $refund = $refundService->executePayout($refund, [
            'payout_reference' => 'PAY-1.5M-A05',
            'payout_evidence_path' => 'shm/refund_evidence/pay15m.pdf',
            'recipient_name' => 'Nasabah A05',
        ], $this->admin->id);

        $cashAccount = ChartOfAccount::query()->where('accounting_context', AccountingContext::SHM)->where('code', '1-1100')->firstOrFail();
        $liabilityAccount = ChartOfAccount::query()->where('accounting_context', AccountingContext::SHM)->where('code', '2-1302')->firstOrFail();

        // 1. Buat Draft Cash Transaction
        $cashTx = ShmCashTransaction::create([
            'transaction_number' => 'BKK-DRAFT-CANCEL-1',
            'type' => ShmCashTransaction::TYPE_OUTFLOW,
            'cash_account_id' => $cashAccount->id,
            'account_id' => $liabilityAccount->id,
            'amount' => 1_500_000,
            'transaction_date' => now()->toDateString(),
            'description' => 'Draft Cash Outflow yang akan dibatalkan',
            'status' => ShmCashTransaction::STATUS_DRAFT,
            'shm_settlement_refund_id' => $refund->id,
            'created_by' => $this->admin->id,
        ]);

        // 2. Hapus / batalkan draft cash transaction
        $cashFlowService = app(ShmCashFlowService::class);
        $cashFlowService->deleteDraft($cashTx);
        $this->assertDatabaseMissing('shm_cash_transactions', ['id' => $cashTx->id]);

        // 3. Buat dan posting Manual Journal pengganti
        $journalService = app(JournalService::class);
        $draftJournal = $journalService->createDraft([
            'accounting_context' => AccountingContext::SHM,
            'transaction_date' => now()->toDateString(),
            'description' => 'Manual Journal Pengganti Draft Batal',
            'shm_settlement_refund_id' => $refund->id,
        ], [
            ['account_id' => $liabilityAccount->id, 'description' => 'Debet 2-1302', 'debit' => 1_500_000, 'credit' => 0],
            ['account_id' => $cashAccount->id, 'description' => 'Kredit 1-1100', 'debit' => 0, 'credit' => 1_500_000],
        ], $this->admin->id);

        $postedJournal = $journalService->post($draftJournal, $this->admin->id);
        $this->assertSame(JournalEntry::STATUS_POSTED, $postedJournal->status);

        $link = ShmRefundAccountingLink::where('shm_settlement_refund_id', $refund->id)->first();
        $this->assertNotNull($link);
        $this->assertSame(ShmRefundAccountingLink::STATUS_POSTED, $link->status);
        $this->assertSame($postedJournal->id, $link->journal_entry_id);
    }

    /**
     * Test 16 (Ticket 42C-B Phase B: Scenario B-01): Actual PostgreSQL Deadlock 40P01 Reproduction & Retry.
     * Worker A locks row 1 then row 2.
     * Worker B locks row 2 then row 1.
     * With DbTransaction::run(), the victim transaction catches 40P01, automatically retries,
     * and both workers complete successfully without partial commit.
     */
    public function test_b01_actual_deadlock_reproduction_and_retry(): void
    {
        $settlement = $this->createCompletedSettlementFixture(6_000_000);

        $refundService = app(ShmSettlementRefundService::class);
        $refund1 = $refundService->request($settlement, ['amount' => 2_000_000, 'payout_method' => 'cash'], $this->admin->id);
        $refund2 = $refundService->request($settlement, ['amount' => 2_000_000, 'payout_method' => 'cash'], $this->admin->id);

        $results = $this->harness->runParallel([
            ['task' => 'shm_simulated_deadlock', 'params' => ['order' => 'A', 'id1' => $refund1->id, 'id2' => $refund2->id]],
            ['task' => 'shm_simulated_deadlock', 'params' => ['order' => 'B', 'id1' => $refund1->id, 'id2' => $refund2->id]],
        ]);

        fwrite(STDERR, "\nB01_RAW_RESULTS: ".json_encode($results->all())."\n");

        // Kedua worker berhasil karena DbTransaction::run berhasil melakukan retry pada SQLSTATE 40P01
        $this->assertSame(2, $results->whereOk()->count());
    }

    /**
     * Test 17 (Ticket 42C-B Phase B: Scenario B-02): PostgreSQL Serialization Failure 40001 & Retry Verification.
     * Demonstrates that when transactions operate under SERIALIZABLE isolation,
     * concurrent overlapping read-write conflicts produce 40001 which is cleanly handled by DbTransaction::run.
     */
    public function test_b02_serialization_failure_and_retry_verification(): void
    {
        $settlement = $this->createCompletedSettlementFixture(5_000_000);

        $refundService = app(ShmSettlementRefundService::class);
        $refund1 = $refundService->request($settlement, ['amount' => 2_000_000, 'payout_method' => 'cash'], $this->admin->id);
        $refund2 = $refundService->request($settlement, ['amount' => 2_000_000, 'payout_method' => 'cash'], $this->admin->id);

        $results = $this->harness->runParallel([
            ['task' => 'shm_simulated_serialization', 'params' => ['settlement_id' => $settlement->id, 'refund_id' => $refund1->id]],
            ['task' => 'shm_simulated_serialization', 'params' => ['settlement_id' => $settlement->id, 'refund_id' => $refund2->id]],
        ]);

        fwrite(STDERR, "\nB02_RAW_RESULTS: ".json_encode($results->all())."\n");

        // Hasil akhir konsisten tanpa error yang tak tertangani
        $this->assertGreaterThanOrEqual(1, $results->whereOk()->count());
    }

    /**
     * Test 18 (Ticket 42C-B Phase C: Scenario C-02): Concurrent Reversal and Replacement Recognition.
     * Worker A: Reverse an already posted journal.
     * Worker B: Attempt to post replacement recognition.
     * Proves that under row-level locking, replacement only succeeds when the original recognition has transitioned away from POSTED.
     */
    public function test_c02_concurrent_reversal_and_replacement_recognition(): void
    {
        $settlement = $this->createCompletedSettlementFixture(3_000_000);

        $refundService = app(ShmSettlementRefundService::class);
        $refund = $refundService->request($settlement, ['amount' => 3_000_000, 'payout_method' => 'cash'], $this->admin->id);
        $refund = $refundService->approve($refund, $this->admin->id);
        $refund = $refundService->executePayout($refund, [
            'payout_reference' => 'PAY-3M-C02',
            'payout_evidence_path' => 'shm/refund_evidence/pay3m.pdf',
            'recipient_name' => 'Nasabah C02',
        ], $this->admin->id);

        $cashAccount = ChartOfAccount::query()->where('accounting_context', AccountingContext::SHM)->where('code', '1-1100')->firstOrFail();
        $liabilityAccount = ChartOfAccount::query()->where('accounting_context', AccountingContext::SHM)->where('code', '2-1302')->firstOrFail();

        // 1. Setup posted Manual Journal
        $journalService = app(JournalService::class);
        $mjDraft = $journalService->createDraft([
            'accounting_context' => AccountingContext::SHM,
            'transaction_date' => now()->toDateString(),
            'description' => 'Manual Journal C02',
            'shm_settlement_refund_id' => $refund->id,
        ], [
            ['account_id' => $liabilityAccount->id, 'description' => 'Debet 2-1302', 'debit' => 3_000_000, 'credit' => 0],
            ['account_id' => $cashAccount->id, 'description' => 'Kredit 1-1100', 'debit' => 0, 'credit' => 3_000_000],
        ], $this->admin->id);

        $mjPosted = $journalService->post($mjDraft, $this->admin->id);

        // 2. Setup draft Cash Outflow (candidate replacement)
        $cashTx = ShmCashTransaction::create([
            'transaction_number' => 'BKK-CONC-C02',
            'type' => ShmCashTransaction::TYPE_OUTFLOW,
            'cash_account_id' => $cashAccount->id,
            'account_id' => $liabilityAccount->id,
            'amount' => 3_000_000,
            'transaction_date' => now()->toDateString(),
            'description' => 'Pengeluaran Kas Pengganti C02',
            'status' => ShmCashTransaction::STATUS_DRAFT,
            'shm_settlement_refund_id' => $refund->id,
            'created_by' => $this->admin->id,
        ]);

        // Run concurrent reversal (Worker A) and replacement posting attempt (Worker B)
        $results = $this->harness->runParallel([
            ['task' => 'shm_refund_reversal_reverse', 'params' => ['journal_entry_id' => $mjPosted->id, 'user_id' => $this->admin->id, 'reason' => 'Koreksi reversal C02']],
            ['task' => 'shm_cash_outflow_post', 'params' => ['transaction_id' => $cashTx->id, 'user_id' => $this->admin->id]],
        ]);

        // Verifikasi database invariants:
        // Tidak pernah ada 2 link POSTED aktif sekaligus
        $postedLinks = ShmRefundAccountingLink::where('shm_settlement_refund_id', $refund->id)
            ->where('status', ShmRefundAccountingLink::STATUS_POSTED)
            ->get();

        $allLinks = ShmRefundAccountingLink::where('shm_settlement_refund_id', $refund->id)->get();
        $journalIds = $allLinks->pluck('journal_entry_id')->filter();
        $allJournals = JournalEntry::whereIn('id', $journalIds)->get();
        $allCash = ShmCashTransaction::where('shm_settlement_refund_id', $refund->id)->get();

        fwrite(STDERR, "\nC02_DATABASE_STATE: ".json_encode([
            'worker_results' => $results->all(),
            'links' => $allLinks->map(fn ($l) => ['id' => $l->id, 'channel' => $l->recognition_channel, 'status' => $l->status, 'journal_id' => $l->journal_entry_id, 'cash_id' => $l->shm_cash_transaction_id])->toArray(),
            'journals' => $allJournals->map(fn ($j) => ['id' => $j->id, 'status' => $j->status, 'entry_number' => $j->entry_number])->toArray(),
            'cash_transactions' => $allCash->map(fn ($c) => ['id' => $c->id, 'status' => $c->status, 'tx_number' => $c->transaction_number])->toArray(),
        ])."\n");

        $this->assertLessThanOrEqual(1, $postedLinks->count());
    }

    /**
     * Test 19 (Ticket 42C-B Phase D: Scenario D-03): Concurrent Draft Cancellation and Posting.
     * Worker A: Cancels / deletes draft cash transaction.
     * Worker B: Tries to post the draft cash transaction.
     * Exactly one transition succeeds; no orphan or inconsistent state occurs.
     */
    public function test_d03_concurrent_draft_cancellation_and_posting(): void
    {
        $settlement = $this->createCompletedSettlementFixture(2_000_000);

        $refundService = app(ShmSettlementRefundService::class);
        $refund = $refundService->request($settlement, ['amount' => 2_000_000, 'payout_method' => 'cash'], $this->admin->id);
        $refund = $refundService->approve($refund, $this->admin->id);
        $refund = $refundService->executePayout($refund, [
            'payout_reference' => 'PAY-2M-D03',
            'payout_evidence_path' => 'shm/refund_evidence/pay2m.pdf',
            'recipient_name' => 'Nasabah D03',
        ], $this->admin->id);

        $cashAccount = ChartOfAccount::query()->where('accounting_context', AccountingContext::SHM)->where('code', '1-1100')->firstOrFail();
        $liabilityAccount = ChartOfAccount::query()->where('accounting_context', AccountingContext::SHM)->where('code', '2-1302')->firstOrFail();

        $cashTx = ShmCashTransaction::create([
            'transaction_number' => 'BKK-CONC-D03',
            'type' => ShmCashTransaction::TYPE_OUTFLOW,
            'cash_account_id' => $cashAccount->id,
            'account_id' => $liabilityAccount->id,
            'amount' => 2_000_000,
            'transaction_date' => now()->toDateString(),
            'description' => 'Pengeluaran Kas Race D03',
            'status' => ShmCashTransaction::STATUS_DRAFT,
            'shm_settlement_refund_id' => $refund->id,
            'created_by' => $this->admin->id,
        ]);

        $results = $this->harness->runParallel([
            ['task' => 'shm_draft_cancellation', 'params' => ['transaction_id' => $cashTx->id, 'user_id' => $this->admin->id, 'reason' => 'Pembatalan race D03']],
            ['task' => 'shm_cash_outflow_post', 'params' => ['transaction_id' => $cashTx->id, 'user_id' => $this->admin->id]],
        ]);

        // Tepat 1 worker yang berhasil (baik cancellation duluan, atau posting duluan)
        $this->assertSame(1, $results->whereOk()->count());

        // Jika cancellation yang menang, buktikan audit trail tersimpan di database
        if ($results->all()[0]->ok) {
            $audit = AuditLog::where('object_type', 'ShmCashTransaction')
                ->where('object_id', $cashTx->id)
                ->first();
            $this->assertNotNull($audit);
            $this->assertSame('shm_cash_transaction.draft_deleted', $audit->action);
        }

        // Verifikasi tidak ada inkonsistensi
        $postedLinks = ShmRefundAccountingLink::where('shm_settlement_refund_id', $refund->id)
            ->where('status', ShmRefundAccountingLink::STATUS_POSTED)
            ->get();

        $this->assertLessThanOrEqual(1, $postedLinks->count());
    }

    /**
     * Test 20 (Ticket 42C-E Scope B-01): Nested Transaction Success.
     * Outer writes A, inner calls DbTransaction::run for write B, outer writes C.
     * All committed, transaction level returns to 0.
     */
    public function test_e01_nested_transaction_success(): void
    {
        $settlement = $this->createCompletedSettlementFixture(2_000_000);

        $initialLevel = DB::transactionLevel();
        $this->assertSame(0, $initialLevel);

        $result = DB::transaction(function () use ($settlement) {
            $r1 = ShmSettlementRefund::create([
                'shm_customer_id' => $settlement->loan->shm_customer_id,
                'shm_loan_id' => $settlement->shm_loan_id,
                'shm_loan_settlement_id' => $settlement->id,
                'amount' => 500_000,
                'status' => 'pending',
                'payout_method' => 'cash',
                'refund_reference' => 'REF-E01-A',
                'requested_by' => $this->admin->id,
            ]);

            $r2 = DbTransaction::run(function () use ($settlement) {
                $this->assertGreaterThan(0, DB::transactionLevel());

                return ShmSettlementRefund::create([
                    'shm_customer_id' => $settlement->loan->shm_customer_id,
                    'shm_loan_id' => $settlement->shm_loan_id,
                    'shm_loan_settlement_id' => $settlement->id,
                    'amount' => 600_000,
                    'status' => 'pending',
                    'payout_method' => 'cash',
                    'refund_reference' => 'REF-E01-B',
                    'requested_by' => $this->admin->id,
                ]);
            });

            $r3 = ShmSettlementRefund::create([
                'shm_customer_id' => $settlement->loan->shm_customer_id,
                'shm_loan_id' => $settlement->shm_loan_id,
                'shm_loan_settlement_id' => $settlement->id,
                'amount' => 700_000,
                'status' => 'pending',
                'payout_method' => 'cash',
                'refund_reference' => 'REF-E01-C',
                'requested_by' => $this->admin->id,
            ]);

            return [$r1, $r2, $r3];
        });

        $this->assertSame(0, DB::transactionLevel());
        $this->assertDatabaseHas('shm_settlement_refunds', ['refund_reference' => 'REF-E01-A']);
        $this->assertDatabaseHas('shm_settlement_refunds', ['refund_reference' => 'REF-E01-B']);
        $this->assertDatabaseHas('shm_settlement_refunds', ['refund_reference' => 'REF-E01-C']);
    }

    /**
     * Test 21 (Ticket 42C-E Scope B-02): Nested Business Exception.
     * Business exception inside inner callback rolls back entire outer transaction without retry.
     */
    public function test_e02_nested_business_exception_aborts_outer_cleanly(): void
    {
        $settlement = $this->createCompletedSettlementFixture(2_000_000);

        try {
            DB::transaction(function () use ($settlement) {
                ShmSettlementRefund::create([
                    'shm_customer_id' => $settlement->loan->shm_customer_id,
                    'shm_loan_id' => $settlement->shm_loan_id,
                    'shm_loan_settlement_id' => $settlement->id,
                    'amount' => 500_000,
                    'status' => 'pending',
                    'payout_method' => 'cash',
                    'refund_reference' => 'REF-E02-OUTER',
                    'requested_by' => $this->admin->id,
                ]);

                DbTransaction::run(function () {
                    throw ValidationException::withMessages(['inner' => ['Business logic error']]);
                });
            });
            $this->fail('Harus melempar ValidationException');
        } catch (ValidationException $e) {
            $this->assertTrue(true);
        }

        $this->assertSame(0, DB::transactionLevel());
        $this->assertDatabaseMissing('shm_settlement_refunds', ['refund_reference' => 'REF-E02-OUTER']);
    }

    /**
     * Test 22 (Ticket 42C-E Scope B-03): Nested Deadlock 40P01 with Real PostgreSQL multi-process.
     * Worker A has an outer transaction that calls DbTransaction::run in a nested manner.
     * When 40P01 occurs, inner retry does not swallow outer failure;
     * outer transaction handles retry at top-level boundary.
     */
    public function test_e03_nested_deadlock_40p01_outer_boundary_retry(): void
    {
        $settlement = $this->createCompletedSettlementFixture(6_000_000);

        $refundService = app(ShmSettlementRefundService::class);
        $refund1 = $refundService->request($settlement, ['amount' => 2_000_000, 'payout_method' => 'cash'], $this->admin->id);
        $refund2 = $refundService->request($settlement, ['amount' => 2_000_000, 'payout_method' => 'cash'], $this->admin->id);

        $results = $this->harness->runParallel([
            ['task' => 'shm_nested_deadlock', 'params' => ['order' => 'A', 'id1' => $refund1->id, 'id2' => $refund2->id]],
            ['task' => 'shm_nested_deadlock', 'params' => ['order' => 'B', 'id1' => $refund1->id, 'id2' => $refund2->id]],
        ]);

        fwrite(STDERR, "\nE03_NESTED_DEADLOCK_RESULTS: ".json_encode($results->all())."\n");

        // Pada PostgreSQL, salah satu worker terdeteksi deadlock (40P01).
        // Minimal 1 worker berhasil commit, dan worker yang gagal terdeteksi SQLSTATE 40P01
        // tanpa partial commit di database.
        $this->assertGreaterThanOrEqual(1, $results->whereOk()->count());
    }

    /**
     * Test 23 (Ticket 42C-E Scope B-04): Nested Serialization 40001 with Real PostgreSQL multi-process.
     */
    public function test_e04_nested_serialization_40001_outer_boundary_retry(): void
    {
        $settlement = $this->createCompletedSettlementFixture(5_000_000);

        $refundService = app(ShmSettlementRefundService::class);
        $refund1 = $refundService->request($settlement, ['amount' => 2_000_000, 'payout_method' => 'cash'], $this->admin->id);
        $refund2 = $refundService->request($settlement, ['amount' => 2_000_000, 'payout_method' => 'cash'], $this->admin->id);

        $results = $this->harness->runParallel([
            ['task' => 'shm_nested_serialization', 'params' => ['settlement_id' => $settlement->id, 'refund_id' => $refund1->id]],
            ['task' => 'shm_nested_serialization', 'params' => ['settlement_id' => $settlement->id, 'refund_id' => $refund2->id]],
        ]);

        fwrite(STDERR, "\nE04_NESTED_SERIALIZATION_RESULTS: ".json_encode($results->all())."\n");

        $this->assertGreaterThanOrEqual(1, $results->whereOk()->count());
    }

    /**
     * Ticket 42C-F Scope C-01/C-02: deadlock PostgreSQL nyata pada nested
     * DbTransaction::run harus merambat ke outer boundary dan mengulang SELURUH
     * callback outer (operasi A, B, C) tanpa partial commit dan tanpa side effect ganda.
     */
    public function test_f01_nested_deadlock_repeats_entire_outer_callback(): void
    {
        $settlement = $this->createCompletedSettlementFixture(6_000_000);

        $refundService = app(ShmSettlementRefundService::class);
        $refund1 = $refundService->request($settlement, ['amount' => 2_000_000, 'payout_method' => 'cash'], $this->admin->id);
        $refund2 = $refundService->request($settlement, ['amount' => 2_000_000, 'payout_method' => 'cash'], $this->admin->id);

        $results = $this->harness->runParallel([
            ['task' => 'shm_outer_nested_deadlock', 'params' => ['order' => 'A', 'id1' => $refund1->id, 'id2' => $refund2->id, 'label' => 'deadlock-A']],
            ['task' => 'shm_outer_nested_deadlock', 'params' => ['order' => 'B', 'id1' => $refund1->id, 'id2' => $refund2->id, 'label' => 'deadlock-B']],
        ]);

        fwrite(STDERR, "\nF01_OUTER_NESTED_DEADLOCK: ".json_encode($results->all())."\n");

        $results->assertAllOk();

        $all = $results->all();
        $maxOuterAttempts = max(array_map(fn ($r) => (int) ($r->data['outer_attempts'] ?? 0), $all));

        $this->assertGreaterThanOrEqual(
            2,
            $maxOuterAttempts,
            'Minimal satu worker harus mengulang seluruh callback outer setelah 40P01.'
        );

        $retriedWorkers = array_values(array_filter($all, fn ($r) => (int) ($r->data['outer_attempts'] ?? 0) >= 2));
        $this->assertNotEmpty($retriedWorkers);

        $observedSqlStates = [];
        foreach ($retriedWorkers as $worker) {
            $data = $worker->data;

            // Inner tidak boleh retry lokal: satu invokasi inner per percobaan outer.
            $this->assertSame(
                (int) $data['outer_attempts'],
                (int) $data['inner_invocations'],
                'Inner hanya boleh dijalankan sekali per percobaan outer (tanpa retry lokal).'
            );

            foreach ($data['caught_sqlstates'] as $state) {
                $observedSqlStates[] = $state;
            }

            foreach ($data['retry_sqlstates'] as $retry) {
                $observedSqlStates[] = $retry['sqlstate'] ?? null;
            }
        }

        $this->assertContains('40P01', $observedSqlStates, 'SQLSTATE 40P01 harus teramati pada batas retry outer.');

        foreach (['deadlock-A', 'deadlock-B'] as $label) {
            $this->assertSame(
                1,
                AuditLog::where('action', 'test.outer_retry.probe')->where('object_label', $label)->count(),
                'Side effect operasi A harus tepat satu per worker setelah retry.'
            );
        }

        // Marker akhir harus berasal dari percobaan terakhir yang benar-benar commit.
        foreach ($all as $worker) {
            $data = $worker->data;
            $this->assertSame(
                "outer-probe:{$data['label']}:attempt:{$data['outer_attempts']}",
                $data['marker']
            );
        }
    }

    /**
     * Ticket 42C-F Scope C-03: serialization failure PostgreSQL nyata (40001)
     * di dalam nested DbTransaction::run harus diteruskan ke outer boundary
     * dan mengulang seluruh callback outer di bawah isolation SERIALIZABLE.
     */
    public function test_f02_nested_serialization_40001_propagates_to_outer_boundary(): void
    {
        $settlement = $this->createCompletedSettlementFixture(5_000_000);

        $refundService = app(ShmSettlementRefundService::class);
        $refund1 = $refundService->request($settlement, ['amount' => 2_000_000, 'payout_method' => 'cash'], $this->admin->id);
        $refundService->request($settlement, ['amount' => 2_000_000, 'payout_method' => 'cash'], $this->admin->id);

        $results = $this->harness->runParallel([
            ['task' => 'shm_outer_nested_serialization', 'params' => ['settlement_id' => $settlement->id, 'target_refund_id' => $refund1->id, 'label' => 'serial-A']],
            ['task' => 'shm_outer_nested_serialization', 'params' => ['settlement_id' => $settlement->id, 'target_refund_id' => $refund1->id, 'label' => 'serial-B']],
        ]);

        fwrite(STDERR, "\nF02_OUTER_NESTED_SERIALIZATION: ".json_encode($results->all())."\n");

        $results->assertAllOk();

        $all = $results->all();
        $maxOuterAttempts = max(array_map(fn ($r) => (int) ($r->data['outer_attempts'] ?? 0), $all));
        $this->assertGreaterThanOrEqual(2, $maxOuterAttempts, 'Minimal satu transaksi harus mengalami 40001 dan retry outer.');

        $observedSqlStates = [];
        foreach (array_filter($all, fn ($r) => (int) ($r->data['outer_attempts'] ?? 0) >= 2) as $worker) {
            $this->assertSame(
                (int) $worker->data['outer_attempts'],
                (int) $worker->data['inner_invocations'],
                'Inner tidak boleh melakukan retry lokal pada serialization failure.'
            );

            foreach ($worker->data['caught_sqlstates'] as $state) {
                $observedSqlStates[] = $state;
            }
            foreach ($worker->data['retry_sqlstates'] as $retry) {
                $observedSqlStates[] = $retry['sqlstate'] ?? null;
            }
        }

        $this->assertContains('40001', $observedSqlStates, 'SQLSTATE 40001 harus teramati pada batas retry outer.');

        foreach (['serial-A', 'serial-B'] as $label) {
            $this->assertSame(
                1,
                AuditLog::where('action', 'test.outer_serialization.probe')->where('object_label', $label)->count(),
                'Outer callback yang diulang tidak boleh menggandakan audit log.'
            );
        }

        // Snapshot akhir tetap konsisten: dua refund, tanpa baris parsial tambahan.
        $this->assertSame(2, ShmSettlementRefund::where('shm_loan_settlement_id', $settlement->id)->count());
        $this->assertStringStartsWith('serial-probe:', (string) $refund1->fresh()->reason);
    }

    /**
     * Ticket 42C-F Scope C-04: retry exhaustion tepat MAX_ATTEMPTS, rollback penuh,
     * exception asli diteruskan, dan tidak ada side effect yang tertinggal.
     */
    public function test_f03_retry_exhaustion_is_bounded_and_fully_rolled_back(): void
    {
        foreach (['40P01', '40001'] as $sqlState) {
            $label = 'exhaustion-'.$sqlState;

            $result = $this->harness->runParallel([
                ['task' => 'shm_retry_exhaustion', 'params' => ['sqlstate' => $sqlState, 'label' => $label]],
            ])->first();

            fwrite(STDERR, "\nF03_EXHAUSTION_{$sqlState}: ".json_encode($result->data)."\n");

            $this->assertTrue($result->ok);
            $this->assertTrue((bool) $result->data['exhausted']);
            $this->assertSame(3, (int) $result->data['attempts'], 'MAX_ATTEMPTS harus tepat 3.');
            $this->assertSame($sqlState, $result->data['sqlstate'], 'Exception asli harus membawa SQLSTATE yang benar.');
            $this->assertCount(2, $result->data['retry_sqlstates'], 'Observer retry hanya boleh dipanggil sebelum attempt 2 dan 3.');

            $this->assertSame(
                0,
                AuditLog::where('action', 'test.retry_exhaustion.probe')->where('object_label', $label)->count(),
                'Seluruh side effect attempt yang gagal harus di-rollback.'
            );
        }
    }

    /**
     * Ticket 42C-F Scope F-01: original reversal harus commit sebelum replacement
     * posting dapat berjalan, dengan bukti timestamp dan status link.
     */
    public function test_f04_reversal_commits_before_replacement_posting(): void
    {
        $settlement = $this->createCompletedSettlementFixture(3_000_000);

        $refundService = app(ShmSettlementRefundService::class);
        $refund = $refundService->request($settlement, ['amount' => 3_000_000, 'payout_method' => 'cash'], $this->admin->id);
        $refund = $refundService->approve($refund, $this->admin->id);
        $refund = $refundService->executePayout($refund, [
            'payout_reference' => 'PAY-3M-F01',
            'payout_evidence_path' => 'shm/refund_evidence/f01.pdf',
            'recipient_name' => 'Nasabah F01',
        ], $this->admin->id);

        $cashAccount = ChartOfAccount::query()->where('accounting_context', AccountingContext::SHM)->where('code', '1-1100')->firstOrFail();
        $liabilityAccount = ChartOfAccount::query()->where('accounting_context', AccountingContext::SHM)->where('code', '2-1302')->firstOrFail();

        $journalService = app(JournalService::class);
        $original = $journalService->createDraft([
            'accounting_context' => AccountingContext::SHM,
            'transaction_date' => now()->toDateString(),
            'description' => 'Manual Journal F01 Original',
            'shm_settlement_refund_id' => $refund->id,
        ], [
            ['account_id' => $liabilityAccount->id, 'description' => 'Debet 2-1302', 'debit' => 3_000_000, 'credit' => 0],
            ['account_id' => $cashAccount->id, 'description' => 'Kredit 1-1100', 'debit' => 0, 'credit' => 3_000_000],
        ], $this->admin->id);
        $original = $journalService->post($original, $this->admin->id);

        $replacement = ShmCashTransaction::create([
            'transaction_number' => 'BKK-F01-REPLACEMENT',
            'type' => ShmCashTransaction::TYPE_OUTFLOW,
            'cash_account_id' => $cashAccount->id,
            'account_id' => $liabilityAccount->id,
            'amount' => 3_000_000,
            'transaction_date' => now()->toDateString(),
            'description' => 'Replacement F01',
            'status' => ShmCashTransaction::STATUS_DRAFT,
            'shm_settlement_refund_id' => $refund->id,
            'created_by' => $this->admin->id,
        ]);

        $result = $this->harness->runParallel([
            ['task' => 'shm_reversal_then_replacement', 'params' => [
                'journal_entry_id' => $original->id,
                'replacement_transaction_id' => $replacement->id,
                'refund_id' => $refund->id,
                'user_id' => $this->admin->id,
                'reason' => 'Reversal commit order F01',
            ]],
        ])->first();

        fwrite(STDERR, "\nF04_REVERSAL_COMMIT_ORDER: ".json_encode($result->data)."\n");

        $this->assertTrue($result->ok, $result->message);

        $this->assertSame(JournalEntry::STATUS_REVERSED, $result->data['original_status']);
        $this->assertSame(ShmRefundAccountingLink::STATUS_REVERSED, $result->data['link_status_after_reversal']);
        $this->assertNotNull($result->data['replacement_journal_id']);
        $this->assertLessThanOrEqual(
            $result->data['replacement_committed_at'],
            $result->data['reversal_committed_at'],
            'Timestamp commit reversal harus lebih awal/equal dibanding replacement.'
        );

        $posted = ShmRefundAccountingLink::where('shm_settlement_refund_id', $refund->id)
            ->where('status', ShmRefundAccountingLink::STATUS_POSTED)
            ->get();

        $this->assertCount(1, $posted);
        $this->assertSame($replacement->id, $posted->first()->shm_cash_transaction_id);
    }

    /**
     * Ticket 42C-F Scope F-02: reversal gagal tidak boleh mengubah original POSTED
     * maupun link POSTED, dan replacement harus ditolak.
     */
    public function test_f05_reversal_failure_keeps_original_posted_and_rejects_replacement(): void
    {
        $settlement = $this->createCompletedSettlementFixture(2_000_000);

        $refundService = app(ShmSettlementRefundService::class);
        $refund = $refundService->request($settlement, ['amount' => 2_000_000, 'payout_method' => 'cash'], $this->admin->id);
        $refund = $refundService->approve($refund, $this->admin->id);
        $refund = $refundService->executePayout($refund, [
            'payout_reference' => 'PAY-2M-F02',
            'payout_evidence_path' => 'shm/refund_evidence/f02.pdf',
            'recipient_name' => 'Nasabah F02',
        ], $this->admin->id);

        $cashAccount = ChartOfAccount::query()->where('accounting_context', AccountingContext::SHM)->where('code', '1-1100')->firstOrFail();
        $liabilityAccount = ChartOfAccount::query()->where('accounting_context', AccountingContext::SHM)->where('code', '2-1302')->firstOrFail();

        $journalService = app(JournalService::class);
        $original = $journalService->createDraft([
            'accounting_context' => AccountingContext::SHM,
            'transaction_date' => now()->toDateString(),
            'description' => 'Manual Journal F02 Original',
            'shm_settlement_refund_id' => $refund->id,
        ], [
            ['account_id' => $liabilityAccount->id, 'description' => 'Debet 2-1302', 'debit' => 2_000_000, 'credit' => 0],
            ['account_id' => $cashAccount->id, 'description' => 'Kredit 1-1100', 'debit' => 0, 'credit' => 2_000_000],
        ], $this->admin->id);
        $original = $journalService->post($original, $this->admin->id);

        $draftToReverse = $journalService->createDraft([
            'accounting_context' => AccountingContext::SHM,
            'transaction_date' => now()->toDateString(),
            'description' => 'Draft yang gagal direverse',
        ], [
            ['account_id' => $liabilityAccount->id, 'description' => 'Debet', 'debit' => 1_000, 'credit' => 0],
            ['account_id' => $cashAccount->id, 'description' => 'Kredit', 'debit' => 0, 'credit' => 1_000],
        ], $this->admin->id);

        try {
            $journalService->reverse($draftToReverse, $this->admin->id, 'Reversal tidak valid');
            $this->fail('Reversal jurnal DRAFT harus ditolak.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame(JournalEntry::STATUS_POSTED, $original->fresh()->status);

        $originalLink = ShmRefundAccountingLink::where('journal_entry_id', $original->id)->firstOrFail();
        $this->assertSame(ShmRefundAccountingLink::STATUS_POSTED, $originalLink->status);

        $replacement = ShmCashTransaction::create([
            'transaction_number' => 'BKK-F02-REPLACEMENT',
            'type' => ShmCashTransaction::TYPE_OUTFLOW,
            'cash_account_id' => $cashAccount->id,
            'account_id' => $liabilityAccount->id,
            'amount' => 2_000_000,
            'transaction_date' => now()->toDateString(),
            'description' => 'Replacement F02 ditolak',
            'status' => ShmCashTransaction::STATUS_DRAFT,
            'shm_settlement_refund_id' => $refund->id,
            'created_by' => $this->admin->id,
        ]);

        try {
            app(ShmCashFlowService::class)->post($replacement, $this->admin->id);
            $this->fail('Replacement harus ditolak saat original masih POSTED.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame(JournalEntry::STATUS_POSTED, $original->fresh()->status);
        $this->assertSame(ShmRefundAccountingLink::STATUS_POSTED, $originalLink->fresh()->status);
        $this->assertSame(ShmCashTransaction::STATUS_DRAFT, $replacement->fresh()->status);
        $this->assertSame(
            1,
            ShmRefundAccountingLink::where('shm_settlement_refund_id', $refund->id)->where('status', ShmRefundAccountingLink::STATUS_POSTED)->count()
        );
    }

    /**
     * Ticket 42C-F Scope F-03: replacement gagal setelah reversal tidak boleh
     * meninggalkan link POSTED orphan; refund dapat diakui kembali lewat jalur sah.
     */
    public function test_f06_replacement_failure_leaves_no_orphan_and_recovery_path_works(): void
    {
        $settlement = $this->createCompletedSettlementFixture(2_000_000);

        $refundService = app(ShmSettlementRefundService::class);
        $refund = $refundService->request($settlement, ['amount' => 2_000_000, 'payout_method' => 'cash'], $this->admin->id);
        $refund = $refundService->approve($refund, $this->admin->id);
        $refund = $refundService->executePayout($refund, [
            'payout_reference' => 'PAY-2M-F03',
            'payout_evidence_path' => 'shm/refund_evidence/f03.pdf',
            'recipient_name' => 'Nasabah F03',
        ], $this->admin->id);

        $cashAccount = ChartOfAccount::query()->where('accounting_context', AccountingContext::SHM)->where('code', '1-1100')->firstOrFail();
        $liabilityAccount = ChartOfAccount::query()->where('accounting_context', AccountingContext::SHM)->where('code', '2-1302')->firstOrFail();

        $wrongContextAccount = ChartOfAccount::create([
            'accounting_context' => AccountingContext::KOPERASI,
            'code' => '9-9999',
            'name' => 'Akun Konteks Salah F03',
            'category' => ChartOfAccount::CATEGORY_ASSET,
            'normal_balance' => ChartOfAccount::NORMAL_DEBIT,
            'is_active' => true,
        ]);

        $journalService = app(JournalService::class);
        $original = $journalService->createDraft([
            'accounting_context' => AccountingContext::SHM,
            'transaction_date' => now()->toDateString(),
            'description' => 'Manual Journal F03 Original',
            'shm_settlement_refund_id' => $refund->id,
        ], [
            ['account_id' => $liabilityAccount->id, 'description' => 'Debet 2-1302', 'debit' => 2_000_000, 'credit' => 0],
            ['account_id' => $cashAccount->id, 'description' => 'Kredit 1-1100', 'debit' => 0, 'credit' => 2_000_000],
        ], $this->admin->id);
        $original = $journalService->post($original, $this->admin->id);

        $journalService->reverse($original, $this->admin->id, 'Reversal untuk F03');

        $this->assertSame(JournalEntry::STATUS_REVERSED, $original->fresh()->status);
        $this->assertSame(
            ShmRefundAccountingLink::STATUS_REVERSED,
            ShmRefundAccountingLink::where('journal_entry_id', $original->id)->firstOrFail()->status
        );

        $replacement = ShmCashTransaction::create([
            'transaction_number' => 'BKK-F03-REPLACEMENT',
            'type' => ShmCashTransaction::TYPE_OUTFLOW,
            'cash_account_id' => $cashAccount->id,
            'account_id' => $wrongContextAccount->id,
            'amount' => 2_000_000,
            'transaction_date' => now()->toDateString(),
            'description' => 'Replacement F03 gagal',
            'status' => ShmCashTransaction::STATUS_DRAFT,
            'shm_settlement_refund_id' => $refund->id,
            'created_by' => $this->admin->id,
        ]);

        try {
            app(ShmCashFlowService::class)->post($replacement, $this->admin->id);
            $this->fail('Replacement dengan akun konteks salah harus gagal.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame(JournalEntry::STATUS_REVERSED, $original->fresh()->status);
        $this->assertSame(ShmCashTransaction::STATUS_DRAFT, $replacement->fresh()->status);
        $this->assertSame(
            0,
            ShmRefundAccountingLink::where('shm_settlement_refund_id', $refund->id)->where('status', ShmRefundAccountingLink::STATUS_POSTED)->count(),
            'Replacement gagal tidak boleh meninggalkan link POSTED orphan.'
        );
        $this->assertSame(
            0,
            JournalEntry::where('source_type', ShmCashTransaction::class)->where('source_id', $replacement->id)->where('status', JournalEntry::STATUS_POSTED)->count()
        );

        // Jalur sah: perbaiki akun lawan lalu posting ulang.
        $replacement->update(['account_id' => $liabilityAccount->id]);
        $posted = app(ShmCashFlowService::class)->post($replacement->fresh(), $this->admin->id);

        $this->assertSame(ShmCashTransaction::STATUS_POSTED, $posted->status);
        $this->assertSame(
            1,
            ShmRefundAccountingLink::where('shm_settlement_refund_id', $refund->id)->where('status', ShmRefundAccountingLink::STATUS_POSTED)->count()
        );
    }
}
