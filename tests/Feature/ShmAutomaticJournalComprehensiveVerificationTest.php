<?php

namespace Tests\Feature;

use App\Models\CashTransaction;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\Role;
use App\Models\SavingsTransaction;
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
use App\Services\Accounting\JournalService;
use App\Services\Accounting\ShmAccountingMappingService;
use App\Services\Accounting\ShmAutomaticJournalService;
use App\Services\Shm\ShmLoanDisbursementService;
use App\Services\Shm\ShmRegularPaymentService;
use App\Support\AccountingContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InteractsWithShmDisbursement;
use Tests\TestCase;

class ShmAutomaticJournalComprehensiveVerificationTest extends TestCase
{
    use InteractsWithShmDisbursement;
    use RefreshDatabase;

    private ShmAutomaticJournalService $autoJournalService;

    private JournalService $journalService;

    private ShmAccountingMappingService $mappingService;

    private User $admin;

    private ShmLoanProduct $product;

    private ChartOfAccount $shmCash;

    private ChartOfAccount $shmReceivable;

    private ChartOfAccount $shmInterest;

    private ChartOfAccount $shmPenalty;

    private ChartOfAccount $shmDiscount;

    private ChartOfAccount $koperasiCash;

    private ShmCustomer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->autoJournalService = app(ShmAutomaticJournalService::class);
        $this->journalService = app(JournalService::class);
        $this->mappingService = app(ShmAccountingMappingService::class);

        $this->admin = User::factory()->create();
        $adminRole = Role::where('name', 'admin')->first();
        if ($adminRole) {
            $this->admin->roles()->attach($adminRole->id);
        }

        $this->seedShmSavingsProducts();
        $this->openCashierSessionFor($this->admin);

        $this->customer = $this->createShmCustomerWithMember();

        $this->product = ShmLoanProduct::firstOrCreate(
            ['code' => 'PJS-COMP'],
            ['name' => 'Pinjaman Siaga Komprehensif', 'category' => ShmLoanProduct::CATEGORY_PERUMAHAN, 'is_active' => true]
        );
        $this->product->rateTiers()->delete();
        $this->product->rateTiers()->create([
            'min_tenor_months' => 12,
            'max_tenor_months' => 18,
            'rate_percent' => 1.30,
            'rate_period' => 'monthly',
            'calculation_method' => 'flat',
        ]);

        $this->shmCash = ChartOfAccount::firstOrCreate(
            ['accounting_context' => AccountingContext::SHM, 'code' => '1-1101'],
            [
                'name' => 'Kas Operasional SHM',
                'category' => ChartOfAccount::CATEGORY_ASSET,
                'normal_balance' => ChartOfAccount::NORMAL_DEBIT,
                'is_active' => true,
            ]
        );

        $this->shmReceivable = ChartOfAccount::firstOrCreate(
            ['accounting_context' => AccountingContext::SHM, 'code' => '1-1301'],
            [
                'name' => 'Piutang Pokok SHM',
                'category' => ChartOfAccount::CATEGORY_ASSET,
                'normal_balance' => ChartOfAccount::NORMAL_DEBIT,
                'is_active' => true,
            ]
        );

        $this->shmInterest = ChartOfAccount::firstOrCreate(
            ['accounting_context' => AccountingContext::SHM, 'code' => '4-1101'],
            [
                'name' => 'Pendapatan Bunga SHM',
                'category' => ChartOfAccount::CATEGORY_REVENUE,
                'normal_balance' => ChartOfAccount::NORMAL_CREDIT,
                'is_active' => true,
            ]
        );

        $this->shmPenalty = ChartOfAccount::firstOrCreate(
            ['accounting_context' => AccountingContext::SHM, 'code' => '4-1201'],
            [
                'name' => 'Pendapatan Denda SHM',
                'category' => ChartOfAccount::CATEGORY_REVENUE,
                'normal_balance' => ChartOfAccount::NORMAL_CREDIT,
                'is_active' => true,
            ]
        );

        $this->shmDiscount = ChartOfAccount::firstOrCreate(
            ['accounting_context' => AccountingContext::SHM, 'code' => '4-1901'],
            [
                'name' => 'Diskon Pelunasan SHM',
                'category' => ChartOfAccount::CATEGORY_REVENUE,
                'normal_balance' => ChartOfAccount::NORMAL_DEBIT,
                'is_active' => true,
            ]
        );

        $this->koperasiCash = ChartOfAccount::firstOrCreate(
            ['accounting_context' => AccountingContext::KOPERASI, 'code' => '1-1100'],
            [
                'name' => 'Kas Koperasi',
                'category' => ChartOfAccount::CATEGORY_ASSET,
                'normal_balance' => ChartOfAccount::NORMAL_DEBIT,
                'is_active' => true,
            ]
        );

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
            'notes' => 'Disetujui untuk uji komprehensif jurnal otomatis',
        ]);

        return ShmLoanApplication::create(array_merge([
            'application_number' => 'PENGAJ-COMP-'.Str::upper(Str::random(8)),
            'shm_customer_id' => $this->customer->id,
            'shm_loan_product_id' => $this->product->id,
            'shm_application_id' => $mirror->id,
            'application_date' => now()->toDateString(),
            'principal' => 50000000,
            'approved_amount' => 50000000,
            'tenor_months' => 12,
            'first_due_date' => now()->addMonth()->startOfMonth()->toDateString(),
            'marketing_id' => $this->admin->id,
            'status' => 'approved',
        ], $overrides));
    }

    // =========================================================================
    // 1. IDEMPOTENCY ACROSS ALL 4 EVENTS
    // =========================================================================

    public function test_disbursement_journal_idempotency_preserves_single_entry(): void
    {
        $application = $this->createApprovedApplication();
        $loan = app(ShmLoanDisbursementService::class)->disburse($application, $this->admin->id);

        $initialJournal = $this->journalService->findForSource($loan, ShmAccountingMapping::EVENT_DISBURSEMENT);
        $this->assertNotNull($initialJournal);

        // Retry journal call directly
        $retryJournal = $this->autoJournalService->createDisbursementJournal($loan, $this->admin->id);
        $this->assertEquals($initialJournal->id, $retryJournal->id);

        $entryCount = JournalEntry::where('source_type', ShmLoan::class)
            ->where('source_id', $loan->id)
            ->where('posting_type', ShmAccountingMapping::EVENT_DISBURSEMENT)
            ->count();
        $this->assertEquals(1, $entryCount);
    }

    public function test_payment_journal_idempotency_preserves_single_entry(): void
    {
        $application = $this->createApprovedApplication();
        $loan = app(ShmLoanDisbursementService::class)->disburse($application, $this->admin->id);

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

        $paymentService = app(ShmRegularPaymentService::class);
        $result = $paymentService->pay($loan, [
            'payment_date' => now()->toDateString(),
            'amount' => 1200000,
            'idempotency_key' => 'IDEM-PAY-001',
        ], $this->admin->id);

        $payment = $result['payment'];
        $initialJournal = $this->journalService->findForSource($payment, ShmAccountingMapping::EVENT_REGULAR_PAYMENT);
        $this->assertNotNull($initialJournal);

        // Retry journal creation
        $retryJournal = $this->autoJournalService->createPaymentJournal($payment, $this->admin->id);
        $this->assertEquals($initialJournal->id, $retryJournal->id);

        $count = JournalEntry::where('source_type', ShmLoanPayment::class)
            ->where('source_id', $payment->id)
            ->where('posting_type', ShmAccountingMapping::EVENT_REGULAR_PAYMENT)
            ->count();
        $this->assertEquals(1, $count);
    }

    public function test_settlement_journal_idempotency_preserves_single_entry(): void
    {
        $loan = ShmLoan::create([
            'loan_number' => 'SHM-SETTLE-IDEM',
            'shm_customer_id' => $this->customer->id,
            'shm_loan_product_id' => $this->product->id,
            'principal' => 50000000,
            'tenor_months' => 12,
            'status' => ShmLoan::STATUS_ACTIVE,
            'disbursed_at' => now()->subMonths(2)->toDateString(),
        ]);

        $settlement = ShmLoanSettlement::create([
            'shm_loan_id' => $loan->id,
            'settlement_reference' => 'SHM-SET-IDEM-001',
            'settlement_date' => now()->toDateString(),
            'outstanding_principal' => 40000000,
            'outstanding_interest' => 5000000,
            'outstanding_penalty' => 0,
            'settlement_amount' => 45000000,
            'status' => ShmLoanSettlement::STATUS_COMPLETED,
            'created_by' => $this->admin->id,
        ]);

        $initialJournal = $this->autoJournalService->createSettlementJournal($settlement, $this->admin->id);
        $this->assertNotNull($initialJournal);

        $retryJournal = $this->autoJournalService->createSettlementJournal($settlement, $this->admin->id);
        $this->assertEquals($initialJournal->id, $retryJournal->id);

        $count = JournalEntry::where('source_type', ShmLoanSettlement::class)
            ->where('source_id', $settlement->id)
            ->where('posting_type', ShmAccountingMapping::EVENT_SETTLEMENT)
            ->count();
        $this->assertEquals(1, $count);
    }

    public function test_correction_reversal_idempotency_does_not_duplicate_reversal(): void
    {
        $loan = ShmLoan::create([
            'loan_number' => 'SHM-CORR-IDEM',
            'shm_customer_id' => $this->customer->id,
            'shm_loan_product_id' => $this->product->id,
            'principal' => 50000000,
            'tenor_months' => 12,
            'status' => ShmLoan::STATUS_ACTIVE,
            'disbursed_at' => now()->subMonths(2)->toDateString(),
        ]);

        $settlement = ShmLoanSettlement::create([
            'shm_loan_id' => $loan->id,
            'settlement_reference' => 'SHM-SET-CORR-IDEM',
            'settlement_date' => now()->toDateString(),
            'outstanding_principal' => 40000000,
            'outstanding_interest' => 5000000,
            'settlement_amount' => 45000000,
            'status' => ShmLoanSettlement::STATUS_COMPLETED,
            'created_by' => $this->admin->id,
        ]);

        $journal = $this->autoJournalService->createSettlementJournal($settlement, $this->admin->id);

        $correction = ShmSettlementCorrection::create([
            'shm_loan_id' => $loan->id,
            'original_settlement_id' => $settlement->id,
            'reason' => 'Koreksi idempotensi',
            'status' => ShmSettlementCorrection::STATUS_COMPLETED,
            'created_by' => $this->admin->id,
        ]);

        $rev1 = $this->autoJournalService->reverseSettlementJournal($correction, $this->admin->id);
        $this->assertNotNull($rev1);

        // Attempting a second reversal on already reversed settlement journal should be idempotent (returning the existing reversal journal)
        $rev2 = $this->autoJournalService->reverseSettlementJournal($correction, $this->admin->id);
        $this->assertNotNull($rev2);
        $this->assertEquals($rev1->id, $rev2->id);

        $this->assertEquals(JournalEntry::STATUS_REVERSED, $journal->fresh()->status);
        $this->assertEquals($rev1->id, $journal->fresh()->reversed_by_entry_id);
    }

    // =========================================================================
    // 2. ATOMIC ROLLBACK ON INVALID / INACTIVE / WRONG CONTEXT CONFIGURATION
    // =========================================================================

    public function test_disbursement_rolls_back_when_mapping_is_missing(): void
    {
        // Delete all mappings
        ShmAccountingMapping::query()->delete();

        $application = $this->createApprovedApplication();
        $disbursementService = app(ShmLoanDisbursementService::class);

        $this->expectException(ValidationException::class);

        try {
            $disbursementService->disburse($application, $this->admin->id);
        } finally {
            // Verify full rollback: no loan, no cash movement, no savings deposit, no journal
            $this->assertEquals(0, ShmLoan::count());
            $this->assertEquals(0, JournalEntry::count());
            $this->assertEquals(0, CashTransaction::count());
            $this->assertEquals(0, SavingsTransaction::count());
        }
    }

    public function test_disbursement_rolls_back_when_account_is_inactive(): void
    {
        // Deactivate cash account
        $this->shmCash->is_active = false;
        $this->shmCash->save();

        $application = $this->createApprovedApplication();
        $disbursementService = app(ShmLoanDisbursementService::class);

        $this->expectException(ValidationException::class);

        try {
            $disbursementService->disburse($application, $this->admin->id);
        } finally {
            $this->assertEquals(0, ShmLoan::count());
            $this->assertEquals(0, JournalEntry::count());
            $this->assertEquals(0, CashTransaction::count());
            $this->assertEquals(0, SavingsTransaction::count());
        }
    }

    public function test_disbursement_rolls_back_when_account_has_wrong_context(): void
    {
        // Assign koperasi account to product accounting
        $config = ShmProductAccounting::where('shm_loan_product_id', $this->product->id)->first();
        $config->cash_account_id = $this->koperasiCash->id; // Wrong context!
        $config->save();

        $application = $this->createApprovedApplication();
        $disbursementService = app(ShmLoanDisbursementService::class);

        $this->expectException(ValidationException::class);

        try {
            $disbursementService->disburse($application, $this->admin->id);
        } finally {
            $this->assertEquals(0, ShmLoan::count());
            $this->assertEquals(0, JournalEntry::count());
            $this->assertEquals(0, CashTransaction::count());
        }
    }

    public function test_payment_rolls_back_when_product_accounting_is_inactive(): void
    {
        $application = $this->createApprovedApplication();
        $loan = app(ShmLoanDisbursementService::class)->disburse($application, $this->admin->id);

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

        // Deactivate product accounting
        $config = ShmProductAccounting::where('shm_loan_product_id', $this->product->id)->first();
        $config->is_active = false;
        $config->save();

        $paymentService = app(ShmRegularPaymentService::class);

        $this->expectException(ValidationException::class);

        try {
            $paymentService->pay($loan, [
                'payment_date' => now()->toDateString(),
                'amount' => 1200000,
                'idempotency_key' => 'FAIL-ROLLBACK-001',
            ], $this->admin->id);
        } finally {
            $this->assertEquals(0, ShmLoanPayment::count());
            $this->assertEquals(1, JournalEntry::count()); // Only disbursement journal exists
            $this->assertEquals(1200000, (float) ShmLoanInstallment::where('shm_loan_id', $loan->id)->first()->remaining_amount);
        }
    }

    // =========================================================================
    // 3. JOURNAL IMMUTABILITY & TRACEABILITY
    // =========================================================================

    public function test_posted_journal_is_immutable_and_cannot_be_reposted(): void
    {
        $application = $this->createApprovedApplication();
        $loan = app(ShmLoanDisbursementService::class)->disburse($application, $this->admin->id);

        $journal = $this->journalService->findForSource($loan, ShmAccountingMapping::EVENT_DISBURSEMENT);
        $this->assertEquals(JournalEntry::STATUS_POSTED, $journal->status);

        // Cannot repost an already posted journal
        $this->expectException(ValidationException::class);
        $this->journalService->post($journal, $this->admin->id);
    }

    public function test_source_traceability_links_business_models_to_journal(): void
    {
        $application = $this->createApprovedApplication();
        $loan = app(ShmLoanDisbursementService::class)->disburse($application, $this->admin->id);

        $journal = $this->journalService->findForSource($loan, ShmAccountingMapping::EVENT_DISBURSEMENT);

        $this->assertEquals(ShmLoan::class, $journal->source_type);
        $this->assertEquals($loan->id, $journal->source_id);
        $this->assertEquals(ShmAccountingMapping::EVENT_DISBURSEMENT, $journal->posting_type);
        $this->assertEquals(AccountingContext::SHM, $journal->accounting_context);
        $this->assertTrue($journal->isBalanced());

        foreach ($journal->lines as $line) {
            $this->assertEquals(AccountingContext::SHM, $line->account->accounting_context);
            $this->assertTrue($line->account->is_active);
        }
    }

    public function test_specific_mapping_takes_precedence_over_generic_mapping(): void
    {
        // Use a new product with active product accounting
        $newProduct = ShmLoanProduct::create([
            'code' => 'PJS-SPECIFIC',
            'name' => 'Pinjaman Khusus',
            'category' => ShmLoanProduct::CATEGORY_PERUMAHAN,
            'is_active' => true,
        ]);

        ShmProductAccounting::create([
            'shm_loan_product_id' => $newProduct->id,
            'accounting_context' => AccountingContext::SHM,
            'cash_account_id' => $this->shmCash->id,
            'receivable_account_id' => $this->shmReceivable->id,
            'is_active' => true,
        ]);

        $specialCash = ChartOfAccount::create([
            'accounting_context' => AccountingContext::SHM,
            'code' => '1-1199',
            'name' => 'Kas Khusus Produk Spesifik',
            'category' => ChartOfAccount::CATEGORY_ASSET,
            'normal_balance' => ChartOfAccount::NORMAL_DEBIT,
            'is_active' => true,
        ]);

        // Specific mapping direct to special account
        $this->mappingService->saveMapping([
            'business_event' => ShmAccountingMapping::EVENT_DISBURSEMENT,
            'shm_loan_product_id' => $newProduct->id,
            'debit_account_role' => ShmAccountingMapping::ROLE_RECEIVABLE,
            'credit_account_id' => $specialCash->id, // explicit account override
            'is_active' => true,
        ], $this->admin->id);

        $resolved = $this->mappingService->resolveAccountsForEvent(
            ShmAccountingMapping::EVENT_DISBURSEMENT,
            $newProduct->id
        );

        $this->assertEquals($specialCash->id, $resolved['credit_account']->id);
    }
}
