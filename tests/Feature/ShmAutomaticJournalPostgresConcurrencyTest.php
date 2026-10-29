<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\Member;
use App\Models\Role;
use App\Models\SavingProduct;
use App\Models\ShmAccountingMapping;
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
use App\Services\CashierService;
use App\Services\Shm\ShmLoanPaymentService;
use App\Services\Shm\ShmLoanSettlementService;
use App\Support\AccountingContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\Concurrency\ConcurrencyHarness;
use Tests\TestCase;

/**
 * T006E-FV — PostgreSQL Concurrency & Automatic Journal Verification.
 *
 * Menguji konkurensi pada PostgreSQL untuk:
 * 1. Payment Concurrency: Dua pembayaran angsuran paralel untuk pinjaman yang sama.
 * 2. Settlement Completion Concurrency: Dua completion settlement paralel untuk pinjaman yang sama.
 * 3. Settlement Correction Concurrency: Dua koreksi settlement paralel untuk settlement yang sama.
 *
 * Menjamin:
 * - Row locking mencegah duplicate execution.
 * - Idempotensi menjamin tepat 1 jurnal per event.
 * - Tidak terjadi double-allocation, double-reversal, atau duplicate journals.
 */
#[RequiresPhpExtension('pdo_pgsql')]
class ShmAutomaticJournalPostgresConcurrencyTest extends TestCase
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

        $this->admin = User::factory()->create(['email_verified_at' => now()]);
        $adminRole = Role::where('name', 'admin')->first();
        if ($adminRole) {
            $this->admin->roles()->attach($adminRole->id);
        }

        // Buka sesi kasir
        app(CashierService::class)->openSession([
            'operational_date' => now()->toDateString(),
            'opening_balance' => 5_000_000_000,
            'opening_notes' => 'Sesi uji konkurensi akuntansi SHM (T006E-FV).',
        ], $this->admin->id);
    }

    protected function tearDown(): void
    {
        if ($this->concurrencyDatabaseReady) {
            $this->harness->tearDownDatabase();
        }

        parent::tearDown();
    }

    private function createLoanFixture(): ShmLoan
    {
        $member = Member::factory()->create(['status' => 'active']);
        $customer = ShmCustomer::factory()->create(['member_id' => $member->id]);

        $product = ShmLoanProduct::firstOrCreate(
            ['code' => 'CONC-PROD'],
            ['name' => 'Pinjaman Konkurensi', 'category' => ShmLoanProduct::CATEGORY_PERUMAHAN, 'is_active' => true]
        );
        $product->rateTiers()->delete();
        $product->rateTiers()->create([
            'min_tenor_months' => 12,
            'max_tenor_months' => 24,
            'rate_percent' => 1.5,
            'rate_period' => 'monthly',
            'calculation_method' => 'flat',
        ]);

        $cash = ChartOfAccount::firstOrCreate(
            ['accounting_context' => AccountingContext::SHM, 'code' => '1-1101'],
            [
                'name' => 'Kas SHM',
                'category' => ChartOfAccount::CATEGORY_ASSET,
                'normal_balance' => ChartOfAccount::NORMAL_DEBIT,
                'is_active' => true,
            ]
        );

        $rec = ChartOfAccount::firstOrCreate(
            ['accounting_context' => AccountingContext::SHM, 'code' => '1-1301'],
            [
                'name' => 'Piutang SHM',
                'category' => ChartOfAccount::CATEGORY_ASSET,
                'normal_balance' => ChartOfAccount::NORMAL_DEBIT,
                'is_active' => true,
            ]
        );

        $interest = ChartOfAccount::firstOrCreate(
            ['accounting_context' => AccountingContext::SHM, 'code' => '4-1101'],
            [
                'name' => 'Pendapatan Bunga SHM',
                'category' => ChartOfAccount::CATEGORY_REVENUE,
                'normal_balance' => ChartOfAccount::NORMAL_CREDIT,
                'is_active' => true,
            ]
        );

        $penalty = ChartOfAccount::firstOrCreate(
            ['accounting_context' => AccountingContext::SHM, 'code' => '4-1201'],
            [
                'name' => 'Pendapatan Denda SHM',
                'category' => ChartOfAccount::CATEGORY_REVENUE,
                'normal_balance' => ChartOfAccount::NORMAL_CREDIT,
                'is_active' => true,
            ]
        );

        $discount = ChartOfAccount::firstOrCreate(
            ['accounting_context' => AccountingContext::SHM, 'code' => '4-1901'],
            [
                'name' => 'Diskon Pelunasan SHM',
                'category' => ChartOfAccount::CATEGORY_REVENUE,
                'normal_balance' => ChartOfAccount::NORMAL_DEBIT,
                'is_active' => true,
            ]
        );

        ShmProductAccounting::updateOrCreate(
            [
                'shm_loan_product_id' => $product->id,
                'accounting_context' => AccountingContext::SHM,
            ],
            [
                'cash_account_id' => $cash->id,
                'receivable_account_id' => $rec->id,
                'interest_revenue_account_id' => $interest->id,
                'penalty_revenue_account_id' => $penalty->id,
                'settlement_discount_account_id' => $discount->id,
                'is_active' => true,
            ]
        );

        ShmAccountingMapping::firstOrCreate(
            [
                'accounting_context' => AccountingContext::SHM,
                'business_event' => ShmAccountingMapping::EVENT_REGULAR_PAYMENT,
                'shm_loan_product_id' => null,
            ],
            [
                'debit_account_role' => ShmAccountingMapping::ROLE_CASH,
                'credit_account_role' => ShmAccountingMapping::ROLE_RECEIVABLE,
                'is_active' => true,
            ]
        );

        ShmAccountingMapping::firstOrCreate(
            [
                'accounting_context' => AccountingContext::SHM,
                'business_event' => ShmAccountingMapping::EVENT_SETTLEMENT,
                'shm_loan_product_id' => null,
            ],
            [
                'debit_account_role' => ShmAccountingMapping::ROLE_CASH,
                'credit_account_role' => ShmAccountingMapping::ROLE_RECEIVABLE,
                'is_active' => true,
            ]
        );

        // Simpanan Pokok
        SavingProduct::firstOrCreate(
            ['code' => 'SP-001'],
            [
                'name' => 'Simpanan Pokok',
                'category' => SavingProduct::CATEGORY_PRINCIPAL,
                'is_active' => true,
            ]
        );

        $app = ShmLoanApplication::create([
            'application_number' => 'PENGAJ-CONC-'.rand(1000, 9999),
            'shm_customer_id' => $customer->id,
            'shm_loan_product_id' => $product->id,
            'principal' => 24000000,
            'approved_amount' => 24000000,
            'tenor_months' => 12,
            'first_due_date' => now()->addMonth()->startOfMonth()->toDateString(),
            'application_date' => now()->toDateString(),
            'status' => 'approved',
        ]);

        $loan = ShmLoan::create([
            'loan_number' => 'SHM-CONC-'.rand(1000, 9999),
            'shm_customer_id' => $customer->id,
            'shm_loan_product_id' => $product->id,
            'shm_loan_application_id' => $app->id,
            'principal' => 24000000,
            'tenor_months' => 12,
            'status' => ShmLoan::STATUS_ACTIVE,
            'disbursed_at' => now()->subMonths(1)->toDateString(),
        ]);

        // 2 Angsuran
        ShmLoanInstallment::create([
            'shm_loan_id' => $loan->id,
            'installment_number' => 1,
            'due_date' => now()->toDateString(),
            'principal_amount' => 2000000,
            'interest_amount' => 360000,
            'total_amount' => 2360000,
            'remaining_amount' => 2360000,
            'status' => ShmLoanInstallment::STATUS_SCHEDULED,
        ]);

        ShmLoanInstallment::create([
            'shm_loan_id' => $loan->id,
            'installment_number' => 2,
            'due_date' => now()->addMonth()->toDateString(),
            'principal_amount' => 2000000,
            'interest_amount' => 360000,
            'total_amount' => 2360000,
            'remaining_amount' => 2360000,
            'status' => ShmLoanInstallment::STATUS_SCHEDULED,
        ]);

        return $loan;
    }

    /**
     * 1. Pembayaran paralel dengan idempotency key yang sama:
     * Tepat SATU worker yang memproses dan tepat SATU jurnal yang dibuat.
     */
    public function test_concurrent_regular_payment_with_same_idempotency_key_creates_single_journal(): void
    {
        $loan = $this->createLoanFixture();
        $idemKey = 'CONC-IDEM-'.rand(10000, 99999);

        $results = $this->harness->runParallel([
            ['task' => 'shm_payment', 'params' => ['loan_id' => $loan->id, 'amount' => 2360000, 'idempotency_key' => $idemKey, 'user_id' => $this->admin->id]],
            ['task' => 'shm_payment', 'params' => ['loan_id' => $loan->id, 'amount' => 2360000, 'idempotency_key' => $idemKey, 'user_id' => $this->admin->id]],
        ]);

        $results->assertNoPartialEffects();

        // Tepat 1 record ShmLoanPayment dibuat
        $this->assertSame(1, ShmLoanPayment::where('shm_loan_id', $loan->id)->count());

        // Tepat 1 JournalEntry dibuat
        $journalCount = JournalEntry::where('source_type', ShmLoanPayment::class)->count();
        $this->assertSame(1, $journalCount);

        $journal = JournalEntry::where('source_type', ShmLoanPayment::class)->first();
        $this->assertSame(JournalEntry::STATUS_POSTED, $journal->status);
        $this->assertTrue($journal->isBalanced());
    }

    /**
     * 2. Penyelesaian settlement paralel untuk pinjaman yang sama:
     * Hanya SATU yang berhasil, tidak ada duplikasi penyelesaian atau jurnal.
     */
    public function test_concurrent_settlement_completion_creates_single_journal(): void
    {
        $loan = $this->createLoanFixture();

        // Siapkan settlement yang sudah diverifikasi pembayarannya
        $settlementService = app(ShmLoanSettlementService::class);
        $paymentService = app(ShmLoanPaymentService::class);

        $settlement = $settlementService->request($loan, ['notes' => 'Settlement conc'], $this->admin->id);
        $settlement = $settlementService->approve($loan, $this->admin->id);

        $payment = $paymentService->create($loan, [
            'payment_date' => now()->toDateString(),
            'amount' => (float) $settlement->settlement_amount,
        ], $this->admin->id);

        $settlement = $settlementService->recordPayment($loan, $payment->id, $this->admin->id);
        $settlement = $settlementService->verifyPayment($loan, $this->admin->id);

        $results = $this->harness->runParallel([
            ['task' => 'shm_settlement_complete', 'params' => ['loan_id' => $loan->id, 'user_id' => $this->admin->id]],
            ['task' => 'shm_settlement_complete', 'params' => ['loan_id' => $loan->id, 'user_id' => $this->admin->id]],
        ]);

        $results->assertNoPartialEffects();

        // Keduanya mengembalikan ok (idempotent: worker kedua mendapatkan instance settlement yang sama)
        $this->assertSame(2, $results->whereOk()->count());

        // Tepat 1 settlement journal dibuat (idempotensi jurnal terbukti di PostgreSQL paralel)
        $journalCount = JournalEntry::where('source_type', ShmLoanSettlement::class)
            ->where('posting_type', ShmAccountingMapping::EVENT_SETTLEMENT)
            ->count();
        $this->assertSame(1, $journalCount);

        $journal = JournalEntry::where('source_type', ShmLoanSettlement::class)->first();
        $this->assertSame(JournalEntry::STATUS_POSTED, $journal->status);
        $this->assertTrue($journal->isBalanced());
    }

    /**
     * 3. Koreksi settlement paralel untuk settlement yang sama:
     * Hanya SATU yang berhasil, jurnal pembalik tepat 1.
     */
    public function test_concurrent_settlement_correction_creates_single_reversal_journal(): void
    {
        $loan = $this->createLoanFixture();

        $settlementService = app(ShmLoanSettlementService::class);
        $paymentService = app(ShmLoanPaymentService::class);

        $settlement = $settlementService->request($loan, ['notes' => 'Settlement for corr'], $this->admin->id);
        $settlement = $settlementService->approve($loan, $this->admin->id);

        $payment = $paymentService->create($loan, [
            'payment_date' => now()->toDateString(),
            'amount' => (float) $settlement->settlement_amount,
        ], $this->admin->id);

        $settlement = $settlementService->recordPayment($loan, $payment->id, $this->admin->id);
        $settlement = $settlementService->verifyPayment($loan, $this->admin->id);
        $settlement = $settlementService->complete($loan, $this->admin->id);

        // Settlement journal terbentuk
        $originalJournal = JournalEntry::where('source_type', ShmLoanSettlement::class)
            ->where('source_id', $settlement->id)
            ->where('posting_type', ShmAccountingMapping::EVENT_SETTLEMENT)
            ->first();
        $this->assertNotNull($originalJournal);

        $results = $this->harness->runParallel([
            ['task' => 'shm_settlement_correct', 'params' => ['loan_id' => $loan->id, 'settlement_id' => $settlement->id, 'reason' => 'Alasan koreksi paralel A', 'user_id' => $this->admin->id]],
            ['task' => 'shm_settlement_correct', 'params' => ['loan_id' => $loan->id, 'settlement_id' => $settlement->id, 'reason' => 'Alasan koreksi paralel B', 'user_id' => $this->admin->id]],
        ]);

        $results->assertNoPartialEffects();

        // Tepat 1 koreksi berhasil
        $this->assertSame(1, $results->whereOk()->count());

        // Tepat 1 record ShmSettlementCorrection dibuat
        $this->assertSame(1, ShmSettlementCorrection::where('original_settlement_id', $settlement->id)->count());

        // Jurnal asli ditandai REVERSED
        $this->assertSame(JournalEntry::STATUS_REVERSED, $originalJournal->fresh()->status);

        // Tepat 1 reversal journal dibuat
        $reversalCount = JournalEntry::where('source_type', ShmLoanSettlement::class)
            ->where('posting_type', ShmAccountingMapping::EVENT_SETTLEMENT.'_reversal')
            ->count();
        $this->assertSame(1, $reversalCount);
    }

    private function postgresAvailable(): bool
    {
        try {
            config(['database.connections.pgsql_test_check' => [
                'driver' => 'pgsql',
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => env('DB_PORT', '5432'),
                'database' => 'postgres',
                'username' => env('DB_USERNAME', 'postgres'),
                'password' => env('DB_PASSWORD', ''),
                'charset' => 'utf8',
            ]]);
            DB::connection('pgsql_test_check')->getPdo();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
