<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\Member;
use App\Models\Role;
use App\Models\ShmAccountingMapping;
use App\Models\ShmApplication;
use App\Models\ShmApproval;
use App\Models\ShmCustomer;
use App\Models\ShmLoan;
use App\Models\ShmLoanApplication;
use App\Models\ShmLoanInstallment;
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

class ShmAutomaticJournalIntegrationTest extends TestCase
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

        // T002C disbursement prerequisites
        $this->seedShmSavingsProducts();
        $this->openCashierSessionFor($this->admin);

        // 1. Valid Customer with active member
        $this->customer = $this->createShmCustomerWithMember();

        // 2. SHM Product with rate tiers
        $this->product = ShmLoanProduct::firstOrCreate(
            ['code' => 'PJS'],
            ['name' => 'Pinjaman Siaga', 'category' => ShmLoanProduct::CATEGORY_PERUMAHAN, 'is_active' => true]
        );
        $this->product->rateTiers()->delete();
        $this->product->rateTiers()->create([
            'min_tenor_months' => 12,
            'max_tenor_months' => 18,
            'rate_percent' => 1.30,
            'rate_period' => 'monthly',
            'calculation_method' => 'flat',
        ]);

        // 3. Active SHM Accounts
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

        // 4. Koperasi Account
        $this->koperasiCash = ChartOfAccount::firstOrCreate(
            ['accounting_context' => AccountingContext::KOPERASI, 'code' => '1-1100'],
            [
                'name' => 'Kas Koperasi',
                'category' => ChartOfAccount::CATEGORY_ASSET,
                'normal_balance' => ChartOfAccount::NORMAL_DEBIT,
                'is_active' => true,
            ]
        );

        // 5. Product Accounting Configuration
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

        // 6. Accounting Mappings
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
            'application_number' => 'PENGAJ-T006E-'.Str::upper(Str::random(8)),
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
    // A. DISBURSEMENT AUTOMATIC JOURNAL
    // =========================================================================

    public function test_disbursement_creates_balanced_posted_journal_automatically(): void
    {
        $application = $this->createApprovedApplication([
            'shm_loan_product_id' => $this->product->id,
            'principal' => 50000000,
            'approved_amount' => 50000000,
            'tenor_months' => 12,
        ]);

        $disbursementService = app(ShmLoanDisbursementService::class);
        $loan = $disbursementService->disburse($application, $this->admin->id);

        $this->assertNotNull($loan->id);

        // Cari jurnal otomatis yang terbentuk
        $journal = $this->journalService->findForSource($loan, ShmAccountingMapping::EVENT_DISBURSEMENT);

        $this->assertNotNull($journal, 'Automatic journal should be created for disbursed loan');
        $this->assertEquals(JournalEntry::STATUS_POSTED, $journal->status);
        $this->assertEquals(AccountingContext::SHM, $journal->accounting_context);
        $this->assertEquals($loan->id, $journal->source_id);
        $this->assertEquals(ShmLoan::class, $journal->source_type);
        $this->assertTrue($journal->isBalanced());
        $this->assertEquals(50000000, $journal->totalDebit());
        $this->assertEquals(50000000, $journal->totalCredit());

        // Verifikasi baris debit & credit
        $lines = $journal->lines()->orderBy('line_order')->get();
        $this->assertEquals($this->shmReceivable->id, $lines[0]->account_id);
        $this->assertEquals(50000000, (float) $lines[0]->debit);
        $this->assertEquals(0, (float) $lines[0]->credit);

        $this->assertEquals($this->shmCash->id, $lines[1]->account_id);
        $this->assertEquals(0, (float) $lines[1]->debit);
        $this->assertEquals(50000000, (float) $lines[1]->credit);
    }

    public function test_disbursement_journal_idempotency_on_retry(): void
    {
        $application = $this->createApprovedApplication([
            'shm_loan_product_id' => $this->product->id,
            'principal' => 30000000,
            'approved_amount' => 30000000,
            'tenor_months' => 12,
        ]);

        $disbursementService = app(ShmLoanDisbursementService::class);
        $loan = $disbursementService->disburse($application, $this->admin->id);

        $countBefore = JournalEntry::where('source_type', ShmLoan::class)->where('source_id', $loan->id)->count();
        $this->assertEquals(1, $countBefore);

        // Panggil ulang pembuatan jurnal untuk loan yang sama
        $this->autoJournalService->createDisbursementJournal($loan, $this->admin->id);
        $countAfter = JournalEntry::where('source_type', ShmLoan::class)->where('source_id', $loan->id)->count();

        $this->assertEquals(1, $countAfter);
    }

    public function test_disbursement_without_mapping_fails_closed(): void
    {
        $productNoMap = ShmLoanProduct::create([
            'code' => 'NO-MAP',
            'name' => 'Produk Tanpa Mapping',
            'category' => ShmLoanProduct::CATEGORY_PERUMAHAN,
            'is_active' => true,
        ]);

        // Hapus generic mapping agar fail
        ShmAccountingMapping::query()->delete();

        $loan = ShmLoan::create([
            'loan_number' => 'SHM-TEST-NOMAP',
            'shm_customer_id' => $this->customer->id,
            'shm_loan_product_id' => $productNoMap->id,
            'principal' => 10000000,
            'tenor_months' => 12,
            'status' => ShmLoan::STATUS_ACTIVE,
        ]);

        $this->expectException(ValidationException::class);
        $this->autoJournalService->createDisbursementJournal($loan, $this->admin->id);
    }

    // =========================================================================
    // B. REGULAR PAYMENT AUTOMATIC JOURNAL
    // =========================================================================

    public function test_regular_payment_creates_balanced_posted_journal_automatically(): void
    {
        $application = $this->createApprovedApplication([
            'shm_loan_product_id' => $this->product->id,
            'principal' => 12000000,
            'approved_amount' => 12000000,
            'tenor_months' => 12,
        ]);

        $loan = app(ShmLoanDisbursementService::class)->disburse($application, $this->admin->id);

        // Buat jadwal angsuran
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
            'idempotency_key' => 'PAY-TEST-001',
        ], $this->admin->id);

        $payment = $result['payment'];
        $this->assertNotNull($payment->id);

        $journal = $this->journalService->findForSource($payment, ShmAccountingMapping::EVENT_REGULAR_PAYMENT);

        $this->assertNotNull($journal, 'Automatic journal should be created for regular payment');
        $this->assertEquals(JournalEntry::STATUS_POSTED, $journal->status);
        $this->assertEquals(AccountingContext::SHM, $journal->accounting_context);
        $this->assertTrue($journal->isBalanced());
        $this->assertEquals(1200000, $journal->totalDebit());
        $this->assertEquals(1200000, $journal->totalCredit());

        // Lines: Kas (Debit: 1.2M), Piutang (Credit: 1.0M), Bunga (Credit: 200K)
        $lines = $journal->lines()->orderBy('line_order')->get();
        $this->assertCount(3, $lines);

        $this->assertEquals($this->shmCash->id, $lines[0]->account_id);
        $this->assertEquals(1200000, (float) $lines[0]->debit);
        $this->assertEquals(0, (float) $lines[0]->credit);

        $this->assertEquals($this->shmReceivable->id, $lines[1]->account_id);
        $this->assertEquals(0, (float) $lines[1]->debit);
        $this->assertEquals(1000000, (float) $lines[1]->credit);

        $this->assertEquals($this->shmInterest->id, $lines[2]->account_id);
        $this->assertEquals(0, (float) $lines[2]->debit);
        $this->assertEquals(200000, (float) $lines[2]->credit);
    }

    // =========================================================================
    // C. SETTLEMENT & CORRECTION AUTOMATIC JOURNAL
    // =========================================================================

    public function test_settlement_creates_journal_and_correction_reverses_it(): void
    {
        $loan = ShmLoan::create([
            'loan_number' => 'SHM-SETTLE-001',
            'shm_customer_id' => $this->customer->id,
            'shm_loan_product_id' => $this->product->id,
            'principal' => 50000000,
            'tenor_months' => 12,
            'status' => ShmLoan::STATUS_ACTIVE,
            'disbursed_at' => now()->subMonths(2)->toDateString(),
        ]);

        $settlement = ShmLoanSettlement::create([
            'shm_loan_id' => $loan->id,
            'settlement_reference' => 'SHM-SET-20261029-0001',
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
        $this->assertEquals(JournalEntry::STATUS_POSTED, $journal->status);
        $this->assertEquals(AccountingContext::SHM, $journal->accounting_context);
        $this->assertTrue($journal->isBalanced());
        $this->assertEquals(45000000, $journal->totalDebit());
        $this->assertEquals(45000000, $journal->totalCredit());

        // 2. Correction reverses the original journal
        $correction = ShmSettlementCorrection::create([
            'shm_loan_id' => $loan->id,
            'original_settlement_id' => $settlement->id,
            'reason' => 'Koreksi salah hitung settlement',
            'status' => ShmSettlementCorrection::STATUS_COMPLETED,
            'created_by' => $this->admin->id,
        ]);

        $reversalJournal = $this->autoJournalService->reverseSettlementJournal($correction, $this->admin->id);

        $this->assertNotNull($reversalJournal);
        $this->assertEquals(JournalEntry::STATUS_POSTED, $reversalJournal->status);
        $this->assertEquals(JournalEntry::STATUS_REVERSED, $journal->fresh()->status);
        $this->assertEquals($reversalJournal->id, $journal->fresh()->reversed_by_entry_id);
        $this->assertTrue($reversalJournal->isBalanced());
        $this->assertEquals(45000000, $reversalJournal->totalDebit());
        $this->assertEquals(45000000, $reversalJournal->totalCredit());
    }

    public function test_settlement_discount_without_configuration_fails_closed(): void
    {
        // Null-kan settlement discount account
        $productConfig = ShmProductAccounting::where('shm_loan_product_id', $this->product->id)->first();
        $productConfig->settlement_discount_account_id = null;
        $productConfig->save();

        $loan = ShmLoan::create([
            'loan_number' => 'SHM-SETTLE-FAIL',
            'shm_customer_id' => $this->customer->id,
            'shm_loan_product_id' => $this->product->id,
            'principal' => 50000000,
            'tenor_months' => 12,
            'status' => ShmLoan::STATUS_ACTIVE,
            'disbursed_at' => now()->subMonths(2)->toDateString(),
        ]);

        $settlementWithDiscount = ShmLoanSettlement::create([
            'shm_loan_id' => $loan->id,
            'settlement_reference' => 'SHM-SET-FAIL-DISCOUNT',
            'settlement_date' => now()->toDateString(),
            'outstanding_principal' => 40000000,
            'outstanding_interest' => 5000000,
            'discount_applied' => true,
            'discount_amount' => 1000000, // Ada diskon!
            'settlement_amount' => 44000000,
            'status' => ShmLoanSettlement::STATUS_COMPLETED,
            'created_by' => $this->admin->id,
        ]);

        $this->expectException(ValidationException::class);
        $this->autoJournalService->createSettlementJournal($settlementWithDiscount, $this->admin->id);
    }
}
