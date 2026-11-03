<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Member;
use App\Models\Role;
use App\Models\SavingProduct;
use App\Models\SavingsAccount;
use App\Models\SavingsTransaction;
use App\Models\Shm\ShmSavingProduct;
use App\Models\Shm\ShmSavingsAccount;
use App\Models\Shm\ShmSavingsTransaction;
use App\Models\ShmApplication;
use App\Models\ShmCustomer;
use App\Models\ShmLoan;
use App\Models\ShmLoanApplication;
use App\Models\ShmLoanInstallment;
use App\Models\ShmLoanProduct;
use App\Models\User;
use App\Services\CashierService;
use App\Services\SavingsTransactionService;
use App\Services\Shm\ShmLoanDisbursementService;
use App\Services\Shm\ShmRegularPaymentService;
use App\Support\AccountingContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\ShmSavingProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShmSavingsIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private ShmCustomer $customer;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->seed(ShmSavingProductSeeder::class);

        $this->admin = User::factory()->create(['email_verified_at' => now()]);
        $this->admin->roles()->attach(Role::query()->where('name', 'admin')->value('id'));

        // Cooperative member
        $this->member = Member::factory()->create(['status' => 'active']);
        $savingProductKoperasi = SavingProduct::factory()->create([
            'category' => SavingProduct::CATEGORY_PRINCIPAL,
            'is_active' => true,
        ]);
        SavingsAccount::create([
            'account_number' => 'REK-KOP-001',
            'member_id' => $this->member->id,
            'saving_product_id' => $savingProductKoperasi->id,
            'opened_at' => now()->toDateString(),
            'status' => 'active',
            'balance' => 500_000,
        ]);

        // SHM Customer
        $this->customer = ShmCustomer::factory()->create([
            'is_active' => true,
            'member_id' => $this->member->id,
        ]);
    }

    /**
     * Test A — SHM Saving Account terhubung ke ShmCustomer dan tidak butuh member_id.
     */
    public function test_shm_customer_has_direct_shm_savings_accounts(): void
    {
        $product = ShmSavingProduct::where('category', ShmSavingProduct::CATEGORY_PRINCIPAL)->firstOrFail();

        $account = ShmSavingsAccount::create([
            'account_number' => ShmSavingsAccount::generateAccountNumber(),
            'shm_customer_id' => $this->customer->id,
            'shm_saving_product_id' => $product->id,
            'opened_at' => now()->toDateString(),
            'status' => 'active',
            'balance' => 0,
        ]);

        $this->assertSame($this->customer->id, $account->customer->id);
        $this->assertCount(1, $this->customer->savingsAccounts);
    }

    /**
     * Test B — SHM Product memiliki jenis simpanan yang konsisten dengan Koperasi.
     */
    public function test_shm_saving_products_exist_with_matching_categories(): void
    {
        $categories = [
            ShmSavingProduct::CATEGORY_PRINCIPAL,
            ShmSavingProduct::CATEGORY_MANDATORY,
            ShmSavingProduct::CATEGORY_VOLUNTARY,
        ];

        foreach ($categories as $cat) {
            $this->assertTrue(
                ShmSavingProduct::where('category', $cat)->exists(),
                "Produk kategori {$cat} harus tersedia di SHM"
            );
        }
    }

    /**
     * Test C, F, G — Pencairan SHM mendeposit ke SHM Saving tanpa merubah Koperasi Saving,
     * dan saldo Koperasi terisolasi.
     */
    public function test_shm_disbursement_uses_shm_savings_and_isolates_cooperative_balance(): void
    {
        $coopAccountBefore = SavingsAccount::where('member_id', $this->member->id)->firstOrFail();
        $this->assertSame(500000.0, (float) $coopAccountBefore->balance);
        $coopTxCountBefore = SavingsTransaction::count();

        // Siapkan akun kas & sesi kasir
        $cash = ChartOfAccount::firstOrCreate(
            ['accounting_context' => AccountingContext::SHM, 'code' => '1-1101'],
            ['name' => 'Kas Operasional SHM', 'category' => ChartOfAccount::CATEGORY_ASSET, 'normal_balance' => ChartOfAccount::NORMAL_DEBIT, 'is_active' => true]
        );
        ChartOfAccount::firstOrCreate(
            ['accounting_context' => AccountingContext::SHM, 'code' => '1-1301'],
            ['name' => 'Piutang Pokok SHM', 'category' => ChartOfAccount::CATEGORY_ASSET, 'normal_balance' => ChartOfAccount::NORMAL_DEBIT, 'is_active' => true]
        );

        $cashier = app(CashierService::class);
        $cashier->openSession([
            'opening_balance' => 100_000_000,
            'operational_date' => now()->toDateString(),
        ], $this->admin->id);

        // Buat loan product & application
        $product = ShmLoanProduct::factory()->create();

        $shmApp = ShmApplication::create([
            'application_number' => 'MIRROR-SHM-001',
            'shm_customer_id' => $this->customer->id,
            'marketing_id' => $this->admin->id,
            'status' => 'approved',
        ]);
        $shmApp->approvals()->create([
            'stage' => 'approval',
            'status' => 'approved',
            'decided_by' => $this->admin->id,
            'decided_at' => now(),
        ]);

        $application = ShmLoanApplication::create([
            'application_number' => 'APP-SHM-TEST-001',
            'shm_customer_id' => $this->customer->id,
            'shm_application_id' => $shmApp->id,
            'shm_loan_product_id' => $product->id,
            'marketing_id' => $this->admin->id,
            'principal' => 10_000_000,
            'approved_amount' => 10_000_000,
            'tenor_months' => 12,
            'first_due_date' => now()->addMonth()->toDateString(),
            'application_date' => now()->toDateString(),
            'status' => 'approved',
        ]);

        $disbursementService = app(ShmLoanDisbursementService::class);
        $loan = $disbursementService->disburse($application, $this->admin->id);

        $this->assertNotNull($loan);

        // Verifikasi mutasi simpanan SHM tercipta
        $shmTx = ShmSavingsTransaction::all();
        $this->assertCount(2, $shmTx, 'Harus ada 2 transaksi Simpanan SHM: Pokok (100rb) dan Wajib (300rb)');

        $shmAccounts = ShmSavingsAccount::where('shm_customer_id', $this->customer->id)->get();
        $this->assertCount(2, $shmAccounts);
        $this->assertEqualsWithDelta(400_000, $shmAccounts->sum('balance'), 0.01);

        // Verifikasi saldo Koperasi TIDAK BERUBAH SAMA SEKALI
        $coopAccountAfter = SavingsAccount::where('member_id', $this->member->id)->firstOrFail();
        $this->assertSame(500000.0, (float) $coopAccountAfter->balance);
        $this->assertSame($coopTxCountBefore, SavingsTransaction::count(), 'Tidak boleh ada transaksi koperasi baru.');
    }

    /**
     * Test D — Regular payment overpayment masuk ke SHM Saving.
     */
    public function test_shm_regular_payment_overpayment_posts_to_shm_saving(): void
    {
        // Buat SHM Loan
        $product = ShmLoanProduct::factory()->create();
        $application = ShmLoanApplication::create([
            'application_number' => 'APP-REG-001',
            'shm_customer_id' => $this->customer->id,
            'shm_loan_product_id' => $product->id,
            'marketing_id' => $this->admin->id,
            'principal' => 1_000_000,
            'approved_amount' => 1_000_000,
            'tenor_months' => 1,
            'first_due_date' => now()->addMonth()->toDateString(),
            'application_date' => now()->toDateString(),
            'status' => 'approved',
        ]);

        $loan = ShmLoan::create([
            'shm_loan_application_id' => $application->id,
            'shm_customer_id' => $this->customer->id,
            'shm_loan_product_id' => $product->id,
            'loan_number' => 'LN-REG-001',
            'principal' => 1_000_000,
            'tenor_months' => 1,
            'rate_percent' => 1.0,
            'rate_period' => 'monthly',
            'calculation_method' => 'flat',
            'first_due_date' => now()->addMonth()->toDateString(),
            'disbursed_at' => now(),
            'status' => 'active',
        ]);

        $loan->installments()->create([
            'installment_number' => 1,
            'due_date' => now()->addMonth()->toDateString(),
            'principal_amount' => 1_000_000,
            'interest_amount' => 10_000,
            'total_amount' => 1_010_000,
            'remaining_amount' => 1_010_000,
            'paid_amount' => 0,
            'paid_interest_amount' => 0,
            'paid_principal_amount' => 0,
            'status' => ShmLoanInstallment::STATUS_SCHEDULED,
        ]);

        $coopTxCountBefore = SavingsTransaction::count();

        $paymentService = app(ShmRegularPaymentService::class);
        // Bayar 1.100.000 (ada kelebihan bayar 90.000)
        $paymentService->pay($loan, [
            'amount' => 1_100_000,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'cash',
        ], $this->admin->id);

        // Verifikasi kelebihan bayar masuk ke Simpanan Pokok SHM
        $shmAccount = ShmSavingsAccount::where('shm_customer_id', $this->customer->id)
            ->whereHas('savingProduct', fn ($q) => $q->where('category', ShmSavingProduct::CATEGORY_PRINCIPAL))
            ->firstOrFail();

        $this->assertEqualsWithDelta(90_000, (float) $shmAccount->balance, 0.01);

        // Simpanan Koperasi tidak tersentuh
        $this->assertSame($coopTxCountBefore, SavingsTransaction::count());
    }

    /**
     * Test I — Koperasi Saving tetap utuh dan normal.
     */
    public function test_cooperative_saving_continues_working_normally(): void
    {
        $coopAccount = SavingsAccount::where('member_id', $this->member->id)->firstOrFail();
        $this->assertSame(500000.0, (float) $coopAccount->balance);

        $tx = app(SavingsTransactionService::class)->deposit($coopAccount, [
            'amount' => 200_000,
            'transaction_date' => now()->toDateString(),
            'description' => 'Setoran Koperasi biasa',
            'processed_by' => $this->admin->id,
        ]);

        $this->assertInstanceOf(SavingsTransaction::class, $tx);
        $coopAccount->refresh();
        $this->assertEqualsWithDelta(700000.0, (float) $coopAccount->balance, 0.01);
    }
}
