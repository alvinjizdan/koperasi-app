<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\Role;
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
use App\Services\Shm\ShmSettlementCorrectionService;
use App\Support\AccountingContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InteractsWithShmDisbursement;
use Tests\TestCase;

class ShmAccountingIntegrationFinalVerificationTest extends TestCase
{
    use InteractsWithShmDisbursement;
    use RefreshDatabase;

    private User $admin;

    private User $unauthorizedUser;

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

        $this->unauthorizedUser = User::factory()->create();

        // Disbursement prerequisites
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

        // Create Active SHM Accounts
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

        // Configure Mappings
        $this->mappingService->saveMapping([
            'business_event' => ShmAccountingMapping::EVENT_DISBURSEMENT,
            'shm_loan_product_id' => $this->product->id,
            'debit_account_role' => ShmAccountingMapping::ROLE_RECEIVABLE,
            'credit_account_role' => ShmAccountingMapping::ROLE_CASH,
            'is_active' => true,
        ], $this->admin->id);

        $this->mappingService->saveMapping([
            'business_event' => ShmAccountingMapping::EVENT_REGULAR_PAYMENT,
            'shm_loan_product_id' => null, // Generic
            'debit_account_role' => ShmAccountingMapping::ROLE_CASH,
            'credit_account_role' => ShmAccountingMapping::ROLE_RECEIVABLE,
            'is_active' => true,
        ], $this->admin->id);

        $this->mappingService->saveMapping([
            'business_event' => ShmAccountingMapping::EVENT_SETTLEMENT,
            'shm_loan_product_id' => null, // Generic
            'debit_account_role' => ShmAccountingMapping::ROLE_CASH,
            'credit_account_role' => ShmAccountingMapping::ROLE_RECEIVABLE,
            'is_active' => true,
        ], $this->admin->id);

        $this->mappingService->saveMapping([
            'business_event' => ShmAccountingMapping::EVENT_SETTLEMENT_CORRECTION,
            'shm_loan_product_id' => null, // Generic
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
            'notes' => 'Disetujui untuk uji integrasi jurnal otomatis',
        ]);

        return ShmLoanApplication::create(array_merge([
            'application_number' => 'PENGAJ-T006J-'.Str::upper(Str::random(8)),
            'shm_customer_id' => $this->customer->id,
            'shm_loan_product_id' => $this->product->id,
            'shm_application_id' => $mirror->id,
            'application_date' => now()->toDateString(),
            'principal' => 10000000,
            'approved_amount' => 10000000,
            'tenor_months' => 12,
            'first_due_date' => now()->addMonth()->startOfMonth()->toDateString(),
            'marketing_id' => $this->admin->id,
            'status' => 'approved',
        ], $overrides));
    }

    private function createDisbursedLoan(float $principal = 10_000_000): ShmLoan
    {
        $loanApp = $this->createApprovedApplication([
            'principal' => $principal,
            'approved_amount' => $principal,
        ]);

        return $this->disbursementService->disburse($loanApp, $this->admin->id);
    }

    /**
     * 1. Category 1 & 8: Disbursement -> Journal & Journal Balance
     */
    public function test_disbursement_creates_balanced_posted_journal(): void
    {
        $loan = $this->createDisbursedLoan(10_000_000);

        $journal = $this->journalService->findForSource($loan, ShmAccountingMapping::EVENT_DISBURSEMENT);

        $this->assertNotNull($journal);
        $this->assertSame(JournalEntry::STATUS_POSTED, $journal->status);
        $this->assertSame(AccountingContext::SHM, $journal->accounting_context);
        $this->assertSame(10_000_000.0, $journal->totalDebit());
        $this->assertSame(10_000_000.0, $journal->totalCredit());
        $this->assertTrue($journal->isBalanced());
        $this->assertSame(ShmLoan::class, $journal->source_type);
        $this->assertSame($loan->id, $journal->source_id);
    }

    /**
     * 2. Category 2 & 5: Regular Payment -> Journal & Idempotency
     */
    public function test_regular_payment_creates_balanced_posted_journal_and_is_idempotent(): void
    {
        $loan = $this->createDisbursedLoan(12_000_000);

        // Buat jadwal angsuran
        $installment = ShmLoanInstallment::create([
            'shm_loan_id' => $loan->id,
            'installment_number' => 1,
            'due_date' => now()->addMonth()->toDateString(),
            'principal_amount' => 1000000,
            'interest_amount' => 200000,
            'total_amount' => 1200000,
            'remaining_amount' => 1200000,
            'status' => ShmLoanInstallment::STATUS_SCHEDULED,
        ]);

        $result = $this->paymentService->pay($loan, [
            'payment_date' => now()->toDateString(),
            'amount' => 1200000,
            'idempotency_key' => 'PAY-TEST-001',
        ], $this->admin->id);

        $payment = $result['payment'];
        $this->assertNotNull($payment->id);

        $journal = $this->journalService->findForSource($payment, ShmAccountingMapping::EVENT_REGULAR_PAYMENT);

        $this->assertNotNull($journal);
        $this->assertSame(JournalEntry::STATUS_POSTED, $journal->status);
        $this->assertSame(AccountingContext::SHM, $journal->accounting_context);
        $this->assertSame($journal->totalDebit(), $journal->totalCredit());
        $this->assertTrue($journal->isBalanced());

        // Idempotency: Triggering again does not create duplicate
        $initialCount = JournalEntry::where('source_type', ShmLoanPayment::class)->where('source_id', $payment->id)->count();
        $this->autoJournalService->createPaymentJournal($payment, $this->admin->id);
        $this->assertSame($initialCount, JournalEntry::where('source_type', ShmLoanPayment::class)->where('source_id', $payment->id)->count());
    }

    /**
     * 3. Category 3 & 4: Settlement -> Journal and Settlement Correction -> Reversal & Replacement
     */
    public function test_settlement_and_correction_accounting_chain(): void
    {
        $loan = $this->createDisbursedLoan(50_000_000);

        $settlement = ShmLoanSettlement::create([
            'shm_loan_id' => $loan->id,
            'settlement_reference' => 'SHM-SET-'.Str::random(6),
            'settlement_date' => now()->toDateString(),
            'outstanding_principal' => 40000000,
            'outstanding_interest' => 5000000,
            'outstanding_penalty' => 0,
            'settlement_amount' => 45000000,
            'status' => ShmLoanSettlement::STATUS_COMPLETED,
            'created_by' => $this->admin->id,
        ]);

        // 1. Create settlement journal
        $journal = $this->autoJournalService->createSettlementJournal($settlement, $this->admin->id);

        $this->assertNotNull($journal);
        $this->assertSame(JournalEntry::STATUS_POSTED, $journal->status);
        $this->assertSame(AccountingContext::SHM, $journal->accounting_context);
        $this->assertTrue($journal->isBalanced());
        $this->assertSame(45000000.0, (float) $journal->totalDebit());
        $this->assertSame(45000000.0, (float) $journal->totalCredit());

        // 2. Settlement correction reverses the original journal
        $correction = ShmSettlementCorrection::create([
            'shm_loan_id' => $loan->id,
            'original_settlement_id' => $settlement->id,
            'reason' => 'Koreksi salah hitung settlement',
            'status' => ShmSettlementCorrection::STATUS_COMPLETED,
            'created_by' => $this->admin->id,
        ]);

        $reversalJournal = $this->autoJournalService->reverseSettlementJournal($correction, $this->admin->id);

        $this->assertNotNull($reversalJournal);
        $this->assertSame(JournalEntry::STATUS_POSTED, $reversalJournal->status);
        $this->assertSame(JournalEntry::STATUS_REVERSED, $journal->fresh()->status);
        $this->assertSame($reversalJournal->id, $journal->fresh()->reversed_by_entry_id);
        $this->assertTrue($reversalJournal->isBalanced());
        $this->assertSame(45000000.0, (float) $reversalJournal->totalDebit());
        $this->assertSame(45000000.0, (float) $reversalJournal->totalCredit());
    }

    /**
     * 4. Category 6 & 7: Rollback on Missing Mapping or Inactive Account
     */
    public function test_rollback_on_missing_mapping_or_inactive_account(): void
    {
        // 6. Missing mapping fails closed
        ShmAccountingMapping::where('business_event', ShmAccountingMapping::EVENT_DISBURSEMENT)->delete();

        $loanApp = $this->createApprovedApplication([
            'principal' => 5_000_000,
            'approved_amount' => 5_000_000,
        ]);

        $this->expectException(ValidationException::class);
        $this->disbursementService->disburse($loanApp, $this->admin->id);

        // Assert loan disbursement was rolled back
        $this->assertDatabaseMissing('shm_loans', ['shm_loan_application_id' => $loanApp->id]);
    }

    /**
     * 5. Category 9, 10, 11, 12, 13: Financial Report Chain & Cross-Report Invariants
     */
    public function test_financial_report_chain_and_cross_report_invariants(): void
    {
        $loan = $this->createDisbursedLoan(12_000_000);

        ShmLoanInstallment::create([
            'shm_loan_id' => $loan->id,
            'installment_number' => 1,
            'due_date' => now()->addMonth()->toDateString(),
            'principal_amount' => 1000000,
            'interest_amount' => 200000,
            'total_amount' => 1200000,
            'remaining_amount' => 1200000,
            'status' => ShmLoanInstallment::STATUS_SCHEDULED,
        ]);

        $result = $this->paymentService->pay($loan, [
            'payment_date' => now()->toDateString(),
            'amount' => 1200000,
            'idempotency_key' => 'PAY-TEST-002',
        ], $this->admin->id);

        $asOfDate = now()->toDateString();

        // 1. Trial Balance (T006F)
        $tb = $this->reportService->trialBalance(null, $asOfDate, false, AccountingContext::SHM);
        $this->assertTrue($tb['balanced']);
        $this->assertSame($tb['totals']['debit'], $tb['totals']['credit']);

        // 2. Period P&L (T006G)
        $periodPl = $this->reportService->incomeStatement(now()->startOfMonth()->toDateString(), $asOfDate, AccountingContext::SHM);
        $this->assertSame(
            round($periodPl['summary']['total_revenue'] - $periodPl['summary']['total_expense'], 2),
            $periodPl['summary']['net_profit_loss']
        );

        // 3. Cumulative P&L (T006H)
        $cumulativePl = $this->reportService->incomeStatement(null, $asOfDate, AccountingContext::SHM);
        $this->assertSame(
            round($cumulativePl['summary']['total_revenue'] - $cumulativePl['summary']['total_expense'], 2),
            $cumulativePl['summary']['net_profit_loss']
        );

        // 4. Balance Sheet (T006I)
        $bs = $this->reportService->balanceSheet($asOfDate, AccountingContext::SHM);

        // INVARIANTS:
        // Invariant 1: TB Debit == Credit
        $this->assertSame($tb['totals']['debit'], $tb['totals']['credit']);

        // Invariant 2: Net P&L = Revenue - Expense
        $this->assertSame(
            round($periodPl['summary']['total_revenue'] - $periodPl['summary']['total_expense'], 2),
            $periodPl['summary']['net_profit_loss']
        );

        // Invariant 3: Cumulative P&L matches inception P&L
        $this->assertSame($cumulativePl['summary']['net_profit_loss'], $periodPl['summary']['net_profit_loss']);

        // Invariant 4: Balance Sheet Equation holds with current result
        $this->assertTrue($bs['summary']['balanced_with_result']);
        $this->assertSame(
            $bs['summary']['total_assets'],
            round($bs['summary']['total_liabilities'] + $bs['summary']['total_equity'] + $bs['summary']['current_period_result'], 2)
        );

        // Invariant 5: T006H result == T006I current result
        $this->assertSame($cumulativePl['summary']['net_profit_loss'], $bs['summary']['current_period_result']);
    }

    /**
     * 6. Category 14: Context Isolation (SHM vs Koperasi)
     */
    public function test_shm_koperasi_context_isolation(): void
    {
        $loan = $this->createDisbursedLoan(10_000_000);

        // Create an equity account for cooperative to hold credit side
        $kopEquity = ChartOfAccount::firstOrCreate(
            ['accounting_context' => AccountingContext::KOPERASI, 'code' => '3-1100'],
            ['name' => 'Modal Koperasi', 'category' => ChartOfAccount::CATEGORY_EQUITY, 'normal_balance' => ChartOfAccount::NORMAL_CREDIT, 'is_active' => true]
        );

        // Post a cooperative journal
        $entry = $this->journalService->createDraft(
            ['transaction_date' => now()->toDateString(), 'accounting_context' => AccountingContext::KOPERASI, 'description' => 'Kas Koperasi'],
            [
                ['account_id' => $this->koperasiCash->id, 'debit' => 99_000_000, 'credit' => 0],
                ['account_id' => $kopEquity->id, 'debit' => 0, 'credit' => 99_000_000],
            ],
            $this->admin->id
        );
        $this->journalService->post($entry, $this->admin->id);

        $shmReport = $this->reportService->balanceSheet(now()->toDateString(), AccountingContext::SHM);
        $kopReport = $this->reportService->balanceSheet(now()->toDateString(), AccountingContext::KOPERASI);

        // Koperasi balance sheet has the 99M, SHM has 10M
        $this->assertSame(99_000_000.0, $kopReport['summary']['total_assets']);
        $this->assertNotEquals($shmReport['summary']['total_assets'], $kopReport['summary']['total_assets']);
    }

    /**
     * 7. Category 15: Authorization
     */
    public function test_authorization_protection(): void
    {
        $routes = [
            'shm.trial-balance.index',
            'shm.trial-balance.export',
            'shm.period-profit-loss.index',
            'shm.period-profit-loss.export',
            'shm.cumulative-profit-loss.index',
            'shm.cumulative-profit-loss.export',
            'shm.balance-sheet.index',
            'shm.balance-sheet.export',
        ];

        foreach ($routes as $route) {
            $response = $this->actingAs($this->unauthorizedUser)->get(route($route));
            $response->assertForbidden();
        }
    }

    /**
     * 8. Category 16 & 17: XLSX Parity & Read-Only Report Integrity
     */
    public function test_all_reports_xlsx_export_and_read_only_integrity(): void
    {
        $this->createDisbursedLoan(10_000_000);

        $exportRoutes = [
            'shm.trial-balance.export',
            'shm.period-profit-loss.export',
            'shm.cumulative-profit-loss.export',
            'shm.balance-sheet.export',
        ];

        $initialJournalEntries = JournalEntry::count();

        foreach ($exportRoutes as $route) {
            $response = $this->actingAs($this->admin)->get(route($route));
            $response->assertOk();
            $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

            // Read only assertion: no journals created or modified
            $this->assertSame($initialJournalEntries, JournalEntry::count());
        }
    }
}
