<?php

namespace Tests\Feature;

use App\Enums\ShmApplicationStatus;
use App\Models\CashierSession;
use App\Models\CashTransaction;
use App\Models\Shm\ShmSavingProduct;
use App\Models\ShmApplication;
use App\Models\ShmCustomer;
use App\Models\ShmLoan;
use App\Models\ShmLoanApplication;
use App\Models\ShmLoanProduct;
use App\Models\User;
use App\Services\CashierService;
use App\Services\DashboardService;
use App\Services\Reports\CashTransactionReport;
use App\Services\Reports\ReportPeriod;
use App\Services\Shm\ShmLoanDisbursementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashierDomainIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->superadmin()->create();
    }

    private function openSession(float $openingBalance = 50_000_000): CashierSession
    {
        return CashierSession::create([
            'session_number' => CashierSession::generateSessionNumber(),
            'operational_date' => now()->toDateString(),
            'opened_by' => $this->user->id,
            'opening_balance' => $openingBalance,
            'status' => CashierSession::STATUS_OPEN,
            'opened_at' => now(),
        ]);
    }

    /**
     * Test A: SHM Loan Disbursement creates a CashTransaction with domain = 'SHM'.
     */
    public function test_shm_loan_disbursement_creates_cash_transaction_with_domain_shm(): void
    {
        $session = $this->openSession(100_000_000);

        ShmSavingProduct::create([
            'code' => 'SP-POKOK-SHM',
            'name' => 'Simpanan Pokok SHM',
            'category' => ShmSavingProduct::CATEGORY_PRINCIPAL,
            'is_active' => true,
        ]);
        ShmSavingProduct::create([
            'code' => 'SW-WAJIB-SHM',
            'name' => 'Simpanan Wajib SHM',
            'category' => ShmSavingProduct::CATEGORY_MANDATORY,
            'is_active' => true,
        ]);

        $customer = ShmCustomer::create([
            'customer_number' => 'CUST-'.uniqid(),
            'name' => 'Budi Customer',
            'nik' => '3201010101010001',
            'phone' => '081234567890',
            'address' => 'Jl. Merdeka No 1',
        ]);

        $product = ShmLoanProduct::factory()->create();

        $shmApp = ShmApplication::create([
            'application_number' => 'MIRROR-SHM-001',
            'customer_name' => 'Budi Customer',
            'shm_customer_id' => $customer->id,
            'marketing_id' => $this->user->id,
            'proposed_amount' => 10_000_000,
            'proposed_tenor' => 12,
            'status' => ShmApplicationStatus::Approved,
        ]);

        $shmApp->approvals()->create([
            'stage' => 'approval',
            'status' => 'approved',
            'decided_by' => $this->user->id,
            'decided_at' => now(),
        ]);

        $application = ShmLoanApplication::create([
            'application_number' => 'APP-SHM-TEST-001',
            'shm_customer_id' => $customer->id,
            'shm_application_id' => $shmApp->id,
            'shm_loan_product_id' => $product->id,
            'marketing_id' => $this->user->id,
            'principal' => 10_000_000,
            'approved_amount' => 10_000_000,
            'tenor_months' => 12,
            'first_due_date' => now()->addMonth()->toDateString(),
            'application_date' => now()->toDateString(),
            'status' => 'approved',
        ]);

        $disbursementService = app(ShmLoanDisbursementService::class);
        $loan = $disbursementService->disburse($application, $this->user->id);

        $this->assertNotNull($loan);

        $cashTransaction = CashTransaction::where('source_type', ShmLoan::class)
            ->where('source_id', $loan->id)
            ->first();

        $this->assertNotNull($cashTransaction);
        $this->assertSame(CashTransaction::DOMAIN_SHM, $cashTransaction->domain);
        $this->assertSame(CashTransaction::TYPE_EXPENSE, $cashTransaction->type);
        $this->assertSame($session->id, $cashTransaction->cashier_session_id);
    }

    /**
     * Test B: Cooperative CashTransactionReport excludes SHM cash transactions.
     */
    public function test_cooperative_cash_transaction_report_excludes_shm_transactions(): void
    {
        $session = $this->openSession(50_000_000);

        // 1. Create a Cooperative Cash Transaction
        CashTransaction::create([
            'cashier_session_id' => $session->id,
            'transaction_number' => 'KAS-KOP-001',
            'transaction_date' => now()->toDateString(),
            'transacted_at' => now(),
            'type' => CashTransaction::TYPE_EXPENSE,
            'category' => 'Beban Operasional',
            'amount' => 250_000,
            'description' => 'Biaya listrik koperasi',
            'processed_by' => $this->user->id,
            'status' => CashTransaction::STATUS_POSTED,
            'domain' => CashTransaction::DOMAIN_KOPERASI,
        ]);

        // 2. Create an SHM Cash Transaction
        CashTransaction::create([
            'cashier_session_id' => $session->id,
            'transaction_number' => 'KAS-SHM-001',
            'transaction_date' => now()->toDateString(),
            'transacted_at' => now(),
            'type' => CashTransaction::TYPE_EXPENSE,
            'category' => 'Pencairan SHM',
            'amount' => 10_000_000,
            'description' => 'Disbursement SHM',
            'processed_by' => $this->user->id,
            'status' => CashTransaction::STATUS_POSTED,
            'domain' => CashTransaction::DOMAIN_SHM,
        ]);

        $period = ReportPeriod::fromFilters(['period' => ReportPeriod::PRESET_TODAY]);
        $reportService = new CashTransactionReport($period);
        $paginator = $reportService->paginate([
            'date_from' => now()->toDateString(),
            'date_to' => now()->toDateString(),
        ], 10);
        $summary = $reportService->summary([
            'date_from' => now()->toDateString(),
            'date_to' => now()->toDateString(),
        ]);

        $numbers = collect($paginator->items())->pluck('transaction_number')->all();

        $this->assertContains('KAS-KOP-001', $numbers);
        $this->assertNotContains('KAS-SHM-001', $numbers);
        $this->assertSame(250_000.0, (float) $summary['expense_total']);
    }

    /**
     * Test C: CashTransaction query scopes filter domain correctly.
     */
    public function test_cash_transaction_scopes_filter_by_domain(): void
    {
        $session = $this->openSession(10_000_000);

        CashTransaction::create([
            'cashier_session_id' => $session->id,
            'transaction_number' => 'KAS-KOP-SCOPE',
            'transaction_date' => now()->toDateString(),
            'transacted_at' => now(),
            'type' => CashTransaction::TYPE_INCOME,
            'amount' => 500_000,
            'processed_by' => $this->user->id,
            'status' => CashTransaction::STATUS_POSTED,
            'domain' => CashTransaction::DOMAIN_KOPERASI,
        ]);

        CashTransaction::create([
            'cashier_session_id' => $session->id,
            'transaction_number' => 'KAS-SHM-SCOPE',
            'transaction_date' => now()->toDateString(),
            'transacted_at' => now(),
            'type' => CashTransaction::TYPE_INCOME,
            'amount' => 700_000,
            'processed_by' => $this->user->id,
            'status' => CashTransaction::STATUS_POSTED,
            'domain' => CashTransaction::DOMAIN_SHM,
        ]);

        $koperasiTx = CashTransaction::koperasi()->pluck('transaction_number')->all();
        $shmTx = CashTransaction::shm()->pluck('transaction_number')->all();

        $this->assertContains('KAS-KOP-SCOPE', $koperasiTx);
        $this->assertNotContains('KAS-SHM-SCOPE', $koperasiTx);

        $this->assertContains('KAS-SHM-SCOPE', $shmTx);
        $this->assertNotContains('KAS-KOP-SCOPE', $shmTx);
    }

    /**
     * Test D: Standard CashierService operations default to DOMAIN_KOPERASI.
     */
    public function test_standard_cashier_service_records_default_to_domain_koperasi(): void
    {
        $session = $this->openSession(10_000_000);
        $cashierService = app(CashierService::class);

        $incomeTx = $cashierService->recordIncome($session, [
            'category' => 'Pendapatan Lain-lain',
            'amount' => 150_000,
            'transaction_date' => now()->toDateString(),
            'description' => 'Penjualan formulir',
        ], $this->user->id);

        $this->assertSame(CashTransaction::DOMAIN_KOPERASI, $incomeTx->domain);

        $expenseTx = $cashierService->recordExpense($session, [
            'category' => 'Konsumsi Rapat',
            'amount' => 75_000,
            'transaction_date' => now()->toDateString(),
            'description' => 'Snack rapat pengurus',
        ], $this->user->id);

        $this->assertSame(CashTransaction::DOMAIN_KOPERASI, $expenseTx->domain);
    }

    /**
     * Test E: BSM Mikro DashboardService recent activity and cash metrics exclude SHM transactions.
     */
    public function test_dashboard_service_excludes_shm_transactions(): void
    {
        $session = $this->openSession(10_000_000);

        CashTransaction::create([
            'cashier_session_id' => $session->id,
            'transaction_number' => 'KAS-KOP-DASH',
            'transaction_date' => now()->toDateString(),
            'transacted_at' => now(),
            'type' => CashTransaction::TYPE_INCOME,
            'category' => 'Operasional',
            'amount' => 100_000,
            'description' => 'Penerimaan umum',
            'processed_by' => $this->user->id,
            'status' => CashTransaction::STATUS_POSTED,
            'domain' => CashTransaction::DOMAIN_KOPERASI,
        ]);

        CashTransaction::create([
            'cashier_session_id' => $session->id,
            'transaction_number' => 'KAS-SHM-DASH',
            'transaction_date' => now()->toDateString(),
            'transacted_at' => now(),
            'type' => CashTransaction::TYPE_EXPENSE,
            'category' => 'Pencairan Pinjaman SHM',
            'amount' => 5_000_000,
            'description' => 'Pencairan pinjaman nasabah SHM',
            'processed_by' => $this->user->id,
            'status' => CashTransaction::STATUS_POSTED,
            'domain' => CashTransaction::DOMAIN_SHM,
        ]);

        $dashboardService = app(DashboardService::class);
        $period = ReportPeriod::fromFilters(['period' => ReportPeriod::PRESET_TODAY]);
        $data = $dashboardService->overview($period);

        // 1. Cash metrics income total should only count Koperasi
        $this->assertSame(100_000.0, (float) $data['cash']['income_total']);
        $this->assertSame(0.0, (float) $data['cash']['expense_total']);

        // 2. Activities feed should not contain SHM cash transaction
        $cashActivities = collect($data['activities']['transactions'])->where('kind', 'cash');
        $titles = $cashActivities->pluck('title')->all();

        $this->assertTrue(collect($titles)->contains(fn ($t) => str_contains($t, 'Penerimaan')));
        $this->assertFalse(collect($titles)->contains(fn ($t) => str_contains($t, 'Pencairan Pinjaman SHM')));
    }
}
