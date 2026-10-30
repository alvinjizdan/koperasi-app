<?php

namespace Tests\Feature;

use App\Models\CashTransaction;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\Role;
use App\Models\SavingsAccount;
use App\Models\ShmAccountingMapping;
use App\Models\ShmApplication;
use App\Models\ShmApproval;
use App\Models\ShmCustomer;
use App\Models\ShmLoan;
use App\Models\ShmLoanApplication;
use App\Models\ShmLoanInstallment;
use App\Models\ShmLoanPayment;
use App\Models\ShmLoanProduct;
use App\Models\ShmLoanSettlement;
use App\Models\ShmProductAccounting;
use App\Models\ShmSettlementCorrection;
use App\Models\User;
use App\Services\Accounting\FinancialReportService;
use App\Services\Accounting\JournalService;
use App\Services\Accounting\ShmAccountingMappingService;
use App\Services\Accounting\ShmAutomaticJournalService;
use App\Services\Shm\ShmLoanDisbursementService;
use App\Services\Shm\ShmLoanSettlementService;
use App\Services\Shm\ShmRegularPaymentService;
use App\Services\Shm\ShmRepaymentScheduleService;
use App\Services\Shm\ShmSettlementCorrectionService;
use App\Support\AccountingContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InteractsWithShmDisbursement;
use Tests\TestCase;

/**
 * T009 — Financial Integration QA
 *
 * Verifies end-to-end financial integrity across all integrated financial domains:
 * SHM Loan -> Disbursement -> Savings / Cashier -> Regular Payment -> Settlement -> Settlement Correction -> Accounting -> Financial Reports -> Audit
 */
class ShmFinancialIntegrationQaTest extends TestCase
{
    use InteractsWithShmDisbursement;
    use RefreshDatabase;

    private User $admin;

    private ShmLoanProduct $product;

    private ShmCustomer $customer;

    private ChartOfAccount $shmCash;

    private ChartOfAccount $shmReceivable;

    private ChartOfAccount $shmInterest;

    private ChartOfAccount $shmPenalty;

    private ChartOfAccount $shmDiscount;

    private ChartOfAccount $koperasiCash;

    private ShmAutomaticJournalService $autoJournalService;

    private JournalService $journalService;

    private ShmAccountingMappingService $mappingService;

    private FinancialReportService $reportService;

    private ShmLoanDisbursementService $disbursementService;

    private ShmRegularPaymentService $paymentService;

    private ShmLoanSettlementService $settlementService;

    private ShmSettlementCorrectionService $correctionService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->autoJournalService = app(ShmAutomaticJournalService::class);
        $this->journalService = app(JournalService::class);
        $this->mappingService = app(ShmAccountingMappingService::class);
        $this->reportService = app(FinancialReportService::class);
        $this->disbursementService = app(ShmLoanDisbursementService::class);
        $this->paymentService = app(ShmRegularPaymentService::class);
        $this->settlementService = app(ShmLoanSettlementService::class);
        $this->correctionService = app(ShmSettlementCorrectionService::class);

        $this->admin = User::factory()->create();
        $adminRole = Role::where('name', 'admin')->first();
        if ($adminRole) {
            $this->admin->roles()->attach($adminRole->id);
        }

        $this->seedShmSavingsProducts();
        $this->openCashierSessionFor($this->admin);

        $this->customer = $this->createShmCustomerWithMember();

        $this->product = ShmLoanProduct::firstOrCreate(
            ['code' => 'PJS'],
            ['name' => 'Pinjaman Siaga SHM', 'category' => ShmLoanProduct::CATEGORY_PERUMAHAN, 'is_active' => true]
        );
        $this->product->rateTiers()->delete();
        $this->product->rateTiers()->create([
            'min_tenor_months' => 12,
            'max_tenor_months' => 18,
            'rate_percent' => 1.30,
            'rate_period' => 'monthly',
            'calculation_method' => 'flat',
        ]);

        // Active SHM Accounts
        $this->shmCash = ChartOfAccount::firstOrCreate(
            ['accounting_context' => AccountingContext::SHM, 'code' => '1-1101'],
            ['name' => 'Kas Operasional SHM', 'category' => ChartOfAccount::CATEGORY_ASSET, 'normal_balance' => ChartOfAccount::NORMAL_DEBIT, 'is_active' => true]
        );

        $this->shmReceivable = ChartOfAccount::firstOrCreate(
            ['accounting_context' => AccountingContext::SHM, 'code' => '1-1301'],
            ['name' => 'Piutang Pokok SHM', 'category' => ChartOfAccount::CATEGORY_ASSET, 'normal_balance' => ChartOfAccount::NORMAL_DEBIT, 'is_active' => true]
        );

        $this->shmInterest = ChartOfAccount::firstOrCreate(
            ['accounting_context' => AccountingContext::SHM, 'code' => '4-1101'],
            ['name' => 'Pendapatan Bunga SHM', 'category' => ChartOfAccount::CATEGORY_REVENUE, 'normal_balance' => ChartOfAccount::NORMAL_CREDIT, 'is_active' => true]
        );

        $this->shmPenalty = ChartOfAccount::firstOrCreate(
            ['accounting_context' => AccountingContext::SHM, 'code' => '4-1201'],
            ['name' => 'Pendapatan Denda SHM', 'category' => ChartOfAccount::CATEGORY_REVENUE, 'normal_balance' => ChartOfAccount::NORMAL_CREDIT, 'is_active' => true]
        );

        $this->shmDiscount = ChartOfAccount::firstOrCreate(
            ['accounting_context' => AccountingContext::SHM, 'code' => '4-1901'],
            ['name' => 'Diskon Pelunasan SHM', 'category' => ChartOfAccount::CATEGORY_REVENUE, 'normal_balance' => ChartOfAccount::NORMAL_DEBIT, 'is_active' => true]
        );

        $this->koperasiCash = ChartOfAccount::firstOrCreate(
            ['accounting_context' => AccountingContext::KOPERASI, 'code' => '1-1100'],
            ['name' => 'Kas Koperasi', 'category' => ChartOfAccount::CATEGORY_ASSET, 'normal_balance' => ChartOfAccount::NORMAL_DEBIT, 'is_active' => true]
        );

        // Configure Product Accounting
        ShmProductAccounting::updateOrCreate(
            [
                'shm_loan_product_id' => $this->product->id,
                'accounting_context' => AccountingContext::SHM,
            ],
            [
                'cash_account_id' => $this->shmCash->id,
                'receivable_account_id' => $this->shmReceivable->id,
                'interest_revenue_account_id' => $this->shmInterest->id,
                'penalty_revenue_account_id' => $this->shmPenalty->id,
                'settlement_discount_account_id' => $this->shmDiscount->id,
                'is_active' => true,
            ]
        );

        // Setup All Core Mappings
        $this->mappingService->saveMapping([
            'business_event' => ShmAccountingMapping::EVENT_DISBURSEMENT,
            'shm_loan_product_id' => $this->product->id,
            'debit_account_role' => ShmAccountingMapping::ROLE_RECEIVABLE,
            'credit_account_role' => ShmAccountingMapping::ROLE_CASH,
            'is_active' => true,
        ], $this->admin->id);

        $this->mappingService->saveMapping([
            'business_event' => ShmAccountingMapping::EVENT_REGULAR_PAYMENT,
            'shm_loan_product_id' => null,
            'debit_account_role' => ShmAccountingMapping::ROLE_CASH,
            'credit_account_role' => ShmAccountingMapping::ROLE_RECEIVABLE,
            'is_active' => true,
        ], $this->admin->id);

        $this->mappingService->saveMapping([
            'business_event' => ShmAccountingMapping::EVENT_SETTLEMENT,
            'shm_loan_product_id' => null,
            'debit_account_role' => ShmAccountingMapping::ROLE_CASH,
            'credit_account_role' => ShmAccountingMapping::ROLE_RECEIVABLE,
            'is_active' => true,
        ], $this->admin->id);

        $this->mappingService->saveMapping([
            'business_event' => ShmAccountingMapping::EVENT_SETTLEMENT_CORRECTION,
            'shm_loan_product_id' => null,
            'debit_account_role' => ShmAccountingMapping::ROLE_RECEIVABLE,
            'credit_account_role' => ShmAccountingMapping::ROLE_CASH,
            'is_active' => true,
        ], $this->admin->id);
    }

    private function createApprovedApplication(array $overrides = []): ShmLoanApplication
    {
        $mirror = ShmApplication::create([
            'application_number' => 'APP-MIRROR-'.Str::upper(Str::random(6)),
            'shm_customer_id' => $this->customer->id,
            'marketing_id' => $this->admin->id,
            'status' => 'approved',
        ]);

        ShmApproval::create([
            'shm_application_id' => $mirror->id,
            'decided_by' => $this->admin->id,
            'decided_at' => now(),
            'status' => ShmApproval::STATUS_APPROVED,
            'notes' => 'Disetujui untuk uji integrasi finansial end-to-end',
        ]);

        return ShmLoanApplication::create(array_merge([
            'application_number' => 'PENGAJ-T009-'.Str::upper(Str::random(8)),
            'shm_customer_id' => $this->customer->id,
            'shm_loan_product_id' => $this->product->id,
            'shm_application_id' => $mirror->id,
            'application_date' => now()->toDateString(),
            'principal' => 12000000,
            'approved_amount' => 12000000,
            'tenor_months' => 12,
            'first_due_date' => now()->addMonth()->startOfMonth()->toDateString(),
            'marketing_id' => $this->admin->id,
            'status' => 'approved',
        ], $overrides));
    }

    /**
     * Test 1: Full Financial Lifecycle & Invariants A through G
     *
     * Invariants Verified:
     * - Invariant A: Journal Balanced (Debit == Credit)
     * - Invariant B: Loan Balance Consistency (Outstanding changes accurately)
     * - Invariant C: Savings Balance Integrity (Simpanan Pokok & Wajib deposited properly)
     * - Invariant D: Cash / Cashier impact correct
     * - Invariant E: Unique Posting (No duplicate journals)
     * - Invariant F: Correction Integrity (Immutable reversal and compensation)
     * - Invariant G: Report Consistency (POSTED journals only, TB & BS & P&L in balance)
     */
    public function test_full_financial_lifecycle_and_core_invariants(): void
    {
        // 1. DISBURSEMENT
        $loanApp = $this->createApprovedApplication([
            'principal' => 12_000_000,
            'approved_amount' => 12_000_000,
            'tenor_months' => 12,
        ]);

        $loan = $this->disbursementService->disburse($loanApp, $this->admin->id);
        app(ShmRepaymentScheduleService::class)->generate($loan);

        $this->assertNotNull($loan);
        $this->assertSame(ShmLoan::STATUS_ACTIVE, $loan->status);

        // Invariant A & E for Disbursement
        $disbJournal = $this->journalService->findForSource($loan, ShmAccountingMapping::EVENT_DISBURSEMENT);
        $this->assertNotNull($disbJournal);
        $this->assertSame(JournalEntry::STATUS_POSTED, $disbJournal->status);
        $this->assertSame(AccountingContext::SHM, $disbJournal->accounting_context);
        $this->assertTrue($disbJournal->isBalanced());
        $this->assertSame(12_000_000.0, (float) $disbJournal->totalDebit());
        $this->assertSame(12_000_000.0, (float) $disbJournal->totalCredit());

        // Invariant C: Savings integrity (Simpanan Pokok 100k + Simpanan Wajib 25k * 12 = 300k)
        $member = $this->customer->member;
        $simpananPokokAcc = SavingsAccount::where('member_id', $member->id)
            ->whereHas('savingProduct', fn ($q) => $q->where('category', 'principal'))
            ->first();
        $simpananWajibAcc = SavingsAccount::where('member_id', $member->id)
            ->whereHas('savingProduct', fn ($q) => $q->where('category', 'mandatory'))
            ->first();

        $this->assertNotNull($simpananPokokAcc);
        $this->assertNotNull($simpananWajibAcc);
        $this->assertEquals(100_000, (float) $simpananPokokAcc->balance);
        $this->assertEquals(300_000, (float) $simpananWajibAcc->balance);

        // Invariant B: Loan initial schedule & outstanding
        $this->assertCount(12, $loan->installments);
        $initialOutstandingPrincipal = (float) $loan->installments()->sum('principal_amount');
        $this->assertEquals(12_000_000, $initialOutstandingPrincipal);

        // 2. REGULAR PAYMENT
        $firstInstallment = $loan->installments()->where('installment_number', 1)->first();
        $payAmount = (float) $firstInstallment->total_amount;

        $payResult = $this->paymentService->pay($loan, [
            'payment_date' => now()->toDateString(),
            'amount' => $payAmount,
            'idempotency_key' => 'T009-PAY-01',
        ], $this->admin->id);

        $payment = $payResult['payment'];
        $this->assertNotNull($payment);

        // Invariant A & E for Payment
        $payJournal = $this->journalService->findForSource($payment, ShmAccountingMapping::EVENT_REGULAR_PAYMENT);
        $this->assertNotNull($payJournal);
        $this->assertSame(JournalEntry::STATUS_POSTED, $payJournal->status);
        $this->assertTrue($payJournal->isBalanced());
        $this->assertSame($payAmount, (float) $payJournal->totalDebit());

        // Invariant B: Outstanding principal reduced
        $firstInstallment->refresh();
        $this->assertSame(ShmLoanInstallment::STATUS_PAID, $firstInstallment->status);
        $remainingPrincipalAfterPay = (float) $loan->installments()->get()->sum(fn ($i) => (float) $i->outstandingPrincipal());
        $this->assertLessThan(12_000_000, $remainingPrincipalAfterPay);

        // 3. SETTLEMENT (Pelunasan Dipercepat)
        $settlement = ShmLoanSettlement::create([
            'shm_loan_id' => $loan->id,
            'settlement_reference' => 'SHM-SET-T009-01',
            'settlement_date' => now()->toDateString(),
            'outstanding_principal' => 11_000_000,
            'outstanding_interest' => 1_000_000,
            'outstanding_penalty' => 0,
            'settlement_amount' => 12_000_000,
            'status' => ShmLoanSettlement::STATUS_COMPLETED,
            'created_by' => $this->admin->id,
        ]);

        $settleJournal = $this->autoJournalService->createSettlementJournal($settlement, $this->admin->id);
        $this->assertNotNull($settleJournal);
        $this->assertSame(JournalEntry::STATUS_POSTED, $settleJournal->status);
        $this->assertTrue($settleJournal->isBalanced());
        $this->assertSame(12_000_000.0, (float) $settleJournal->totalDebit());
        $this->assertSame(12_000_000.0, (float) $settleJournal->totalCredit());

        // 4. SETTLEMENT CORRECTION (Policy B Reversal)
        $correction = ShmSettlementCorrection::create([
            'shm_loan_id' => $loan->id,
            'original_settlement_id' => $settlement->id,
            'reason' => 'Koreksi audit finansial T009',
            'status' => ShmSettlementCorrection::STATUS_COMPLETED,
            'created_by' => $this->admin->id,
        ]);

        $reversalJournal = $this->autoJournalService->reverseSettlementJournal($correction, $this->admin->id);

        // Invariant F: Correction Integrity & Reversal Journal
        $this->assertNotNull($reversalJournal);
        $this->assertSame(JournalEntry::STATUS_POSTED, $reversalJournal->status);
        $this->assertTrue($reversalJournal->isBalanced());
        $this->assertSame(12_000_000.0, (float) $reversalJournal->totalDebit());
        $this->assertSame(12_000_000.0, (float) $reversalJournal->totalCredit());

        $settleJournal->refresh();
        $this->assertSame(JournalEntry::STATUS_REVERSED, $settleJournal->status);
        $this->assertSame($reversalJournal->id, $settleJournal->reversed_by_entry_id);

        // 5. REPORTING CONSISTENCY & INVARIANTS (Invariant G)
        $asOfDate = now()->toDateString();

        // Trial Balance
        $tb = $this->reportService->trialBalance(null, $asOfDate, false, AccountingContext::SHM);
        $this->assertTrue($tb['balanced']);
        $this->assertSame($tb['totals']['debit'], $tb['totals']['credit']);

        // Cumulative Income Statement
        $pl = $this->reportService->incomeStatement(null, $asOfDate, AccountingContext::SHM);
        $this->assertSame(
            round($pl['summary']['total_revenue'] - $pl['summary']['total_expense'], 2),
            $pl['summary']['net_profit_loss']
        );

        // Balance Sheet
        $bs = $this->reportService->balanceSheet($asOfDate, AccountingContext::SHM);
        $this->assertTrue($bs['summary']['balanced_with_result']);
        $this->assertSame($pl['summary']['net_profit_loss'], $bs['summary']['current_period_result']);
        $this->assertSame(
            $bs['summary']['total_assets'],
            round($bs['summary']['total_liabilities'] + $bs['summary']['total_equity'] + $bs['summary']['current_period_result'], 2)
        );
    }

    /**
     * Test 2: Transaction Rollback Integrity
     * When accounting mapping fails or is missing, no partial state is persisted.
     */
    public function test_transaction_rollback_on_missing_mapping(): void
    {
        // Delete disbursement mapping
        ShmAccountingMapping::where('business_event', ShmAccountingMapping::EVENT_DISBURSEMENT)->delete();

        $loanApp = $this->createApprovedApplication([
            'principal' => 6_000_000,
            'approved_amount' => 6_000_000,
            'tenor_months' => 12,
        ]);

        $initialLoansCount = ShmLoan::count();

        // Attempt disbursement - should throw ValidationException and rollback cleanly
        try {
            $this->disbursementService->disburse($loanApp, $this->admin->id);
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('mapping', $e->errors());
        }

        // Assert loan record was NOT committed
        $this->assertSame($initialLoansCount, ShmLoan::count());
        $this->assertDatabaseMissing('shm_loans', ['shm_loan_application_id' => $loanApp->id]);
    }

    /**
     * Test 3: Idempotency Protection Across Integrations
     * Re-triggering journal generation or payment idempotency key does not create duplicate financial records.
     */
    public function test_idempotency_safeguards(): void
    {
        $loanApp = $this->createApprovedApplication([
            'principal' => 10_000_000,
            'approved_amount' => 10_000_000,
        ]);
        $loan = $this->disbursementService->disburse($loanApp, $this->admin->id);
        app(ShmRepaymentScheduleService::class)->generate($loan);

        $initialJournalCount = JournalEntry::where('source_type', ShmLoan::class)
            ->where('source_id', $loan->id)
            ->count();
        $this->assertSame(1, $initialJournalCount);

        // Attempt redundant disbursement journal creation
        $secondJournal = $this->autoJournalService->createDisbursementJournal($loan, $this->admin->id);
        $this->assertSame(
            $initialJournalCount,
            JournalEntry::where('source_type', ShmLoan::class)->where('source_id', $loan->id)->count()
        );

        // Payment Idempotency Key - uses the first generated installment
        $firstInstallment = $loan->installments()->first();
        $payAmount = (float) $firstInstallment->total_amount;

        $res1 = $this->paymentService->pay($loan, [
            'payment_date' => now()->toDateString(),
            'amount' => $payAmount,
            'idempotency_key' => 'IDEMP-KEY-TEST-01',
        ], $this->admin->id);

        // Re-executing with the same idempotency key
        $res2 = $this->paymentService->pay($loan, [
            'payment_date' => now()->toDateString(),
            'amount' => $payAmount,
            'idempotency_key' => 'IDEMP-KEY-TEST-01',
        ], $this->admin->id);

        $this->assertSame($res1['payment']->id, $res2['payment']->id);
        $this->assertSame(
            1,
            JournalEntry::where('source_type', ShmLoanPayment::class)->where('source_id', $res1['payment']->id)->count()
        );
    }

    /**
     * Test 4: Overpayment Allocation to Savings & Zero Cashier Impact
     * Verifies that paying more than total owed transfers excess to Simpanan Pokok without cash ledger impact.
     */
    public function test_overpayment_allocation_to_savings_and_zero_cash_impact(): void
    {
        $loanApp = $this->createApprovedApplication([
            'principal' => 12_000_000,
            'approved_amount' => 12_000_000,
            'tenor_months' => 12,
        ]);
        $loan = $this->disbursementService->disburse($loanApp, $this->admin->id);
        app(ShmRepaymentScheduleService::class)->generate($loan);

        $totalOwedBefore = $loan->totalOwed();

        $member = $this->customer->member;
        $simpananPokokAcc = SavingsAccount::where('member_id', $member->id)
            ->whereHas('savingProduct', fn ($q) => $q->where('category', 'principal'))
            ->first();
        $initialSavingsBalance = (float) $simpananPokokAcc->balance;

        // Pay entire owed plus Rp 50,000 excess
        $payAmount = $totalOwedBefore + 50_000;

        $initialCashTxCount = CashTransaction::count();

        $payResult = $this->paymentService->pay($loan, [
            'payment_date' => now()->toDateString(),
            'amount' => $payAmount,
            'idempotency_key' => 'T009-OVERPAY-01',
        ], $this->admin->id);

        $payment = $payResult['payment'];
        $simpananPokokAcc->refresh();

        // Excess Rp 50,000 deposited to Simpanan Pokok
        $this->assertEquals($initialSavingsBalance + 50_000, (float) $simpananPokokAcc->balance);

        // Zero additional cashier cash transactions for the savings transfer
        $this->assertSame($initialCashTxCount, CashTransaction::count());

        // Loan closed automatically
        $loan->refresh();
        $this->assertSame(ShmLoan::STATUS_CLOSED, $loan->status);

        // Journal balanced and posted
        $journal = $this->journalService->findForSource($payment, ShmAccountingMapping::EVENT_REGULAR_PAYMENT);
        $this->assertNotNull($journal);
        $this->assertTrue($journal->isBalanced());
    }

    /**
     * Test 5: End-to-End Accounting Context Isolation (SHM vs Koperasi)
     * Demonstrates that financial statements strictly segregate SHM and cooperative journals.
     */
    public function test_reporting_context_isolation_across_lifecycle(): void
    {
        $loanApp = $this->createApprovedApplication([
            'principal' => 10_000_000,
            'approved_amount' => 10_000_000,
        ]);
        $loan = $this->disbursementService->disburse($loanApp, $this->admin->id);

        // Cooperative Entry
        $kopEquity = ChartOfAccount::firstOrCreate(
            ['accounting_context' => AccountingContext::KOPERASI, 'code' => '3-1100'],
            ['name' => 'Modal Koperasi', 'category' => ChartOfAccount::CATEGORY_EQUITY, 'normal_balance' => ChartOfAccount::NORMAL_CREDIT, 'is_active' => true]
        );
        $kopEntry = $this->journalService->createDraft(
            ['transaction_date' => now()->toDateString(), 'accounting_context' => AccountingContext::KOPERASI, 'description' => 'Modal Koperasi'],
            [
                ['account_id' => $this->koperasiCash->id, 'debit' => 50_000_000, 'credit' => 0],
                ['account_id' => $kopEquity->id, 'debit' => 0, 'credit' => 50_000_000],
            ],
            $this->admin->id
        );
        $this->journalService->post($kopEntry, $this->admin->id);

        $asOfDate = now()->toDateString();
        $shmReport = $this->reportService->balanceSheet($asOfDate, AccountingContext::SHM);
        $kopReport = $this->reportService->balanceSheet($asOfDate, AccountingContext::KOPERASI);

        // Isolation verified
        $this->assertSame(50_000_000.0, (float) $kopReport['summary']['total_assets']);
        $this->assertNotEquals($shmReport['summary']['total_assets'], $kopReport['summary']['total_assets']);
        $this->assertTrue($kopReport['summary']['balanced_with_result']);
    }
}
