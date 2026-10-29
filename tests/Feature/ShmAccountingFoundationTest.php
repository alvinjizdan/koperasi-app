<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Role;
use App\Models\User;
use App\Services\Accounting\FinancialReportService;
use App\Services\Accounting\JournalService;
use App\Support\AccountingContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ShmAccountingFoundationTest extends TestCase
{
    use RefreshDatabase;

    private JournalService $journalService;

    private FinancialReportService $reportService;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->journalService = app(JournalService::class);
        $this->reportService = app(FinancialReportService::class);

        $this->admin = User::factory()->create();
        $adminRole = Role::where('name', 'admin')->first();
        if ($adminRole) {
            $this->admin->roles()->attach($adminRole->id);
        }
    }

    /**
     * Test 1: Cooperative and SHM can both have an account with the identical code (scoped uniqueness).
     */
    public function test_scoped_coa_code_uniqueness_between_koperasi_and_shm(): void
    {
        $koperasiAccount = ChartOfAccount::create([
            'accounting_context' => AccountingContext::KOPERASI,
            'code' => '1-1100',
            'name' => 'Kas Operasional Koperasi',
            'category' => ChartOfAccount::CATEGORY_ASSET,
            'normal_balance' => ChartOfAccount::NORMAL_DEBIT,
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('chart_of_accounts', [
            'id' => $koperasiAccount->id,
            'accounting_context' => AccountingContext::KOPERASI,
            'code' => '1-1100',
        ]);

        $shmAccount = ChartOfAccount::create([
            'accounting_context' => AccountingContext::SHM,
            'code' => '1-1100',
            'name' => 'Kas Operasional SHM',
            'category' => ChartOfAccount::CATEGORY_ASSET,
            'normal_balance' => ChartOfAccount::NORMAL_DEBIT,
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('chart_of_accounts', [
            'id' => $shmAccount->id,
            'accounting_context' => AccountingContext::SHM,
            'code' => '1-1100',
        ]);

        $this->assertNotEquals($koperasiAccount->id, $shmAccount->id);
    }

    /**
     * Test 2: Cooperative Accounting queries do not return SHM accounts.
     */
    public function test_cooperative_accounting_does_not_query_shm_coa(): void
    {
        ChartOfAccount::create([
            'accounting_context' => AccountingContext::SHM,
            'code' => '9-9999',
            'name' => 'Akun Khusus SHM',
            'category' => ChartOfAccount::CATEGORY_ASSET,
            'normal_balance' => ChartOfAccount::NORMAL_DEBIT,
            'is_active' => true,
        ]);

        $koperasiAccounts = ChartOfAccount::query()->koperasi()->get();

        $this->assertFalse($koperasiAccounts->contains('code', '9-9999'));
    }

    /**
     * Test 3: SHM Accounting queries do not return Cooperative accounts.
     */
    public function test_shm_accounting_does_not_query_cooperative_coa(): void
    {
        ChartOfAccount::create([
            'accounting_context' => AccountingContext::SHM,
            'code' => '1-9999',
            'name' => 'Akun Eksklusif SHM',
            'category' => ChartOfAccount::CATEGORY_ASSET,
            'normal_balance' => ChartOfAccount::NORMAL_DEBIT,
            'is_active' => true,
        ]);

        $shmAccounts = ChartOfAccount::query()->shm()->get();

        $this->assertTrue($shmAccounts->contains('code', '1-9999'));
        foreach ($shmAccounts as $account) {
            $this->assertEquals(AccountingContext::SHM, $account->accounting_context);
        }
    }

    /**
     * Test 4: Mixed-context journal lines are strictly rejected.
     */
    public function test_mixed_context_journal_entry_is_strictly_rejected(): void
    {
        $koperasiAccount = ChartOfAccount::create([
            'accounting_context' => AccountingContext::KOPERASI,
            'code' => '1-1199',
            'name' => 'Kas Koperasi Test',
            'category' => ChartOfAccount::CATEGORY_ASSET,
            'normal_balance' => ChartOfAccount::NORMAL_DEBIT,
            'is_active' => true,
        ]);

        $shmAccount = ChartOfAccount::create([
            'accounting_context' => AccountingContext::SHM,
            'code' => '1-8888',
            'name' => 'Kas Khusus SHM',
            'category' => ChartOfAccount::CATEGORY_ASSET,
            'normal_balance' => ChartOfAccount::NORMAL_DEBIT,
            'is_active' => true,
        ]);

        $this->expectException(ValidationException::class);

        $this->journalService->createDraft(
            [
                'transaction_date' => now()->toDateString(),
                'description' => 'Mixed Context Attempt',
                'accounting_context' => AccountingContext::KOPERASI,
            ],
            [
                ['account_id' => $koperasiAccount->id, 'debit' => 100000, 'credit' => 0],
                ['account_id' => $shmAccount->id, 'debit' => 0, 'credit' => 100000],
            ],
            $this->admin->id
        );
    }

    /**
     * Test 5: SHM journal entries do not appear in Cooperative financial reports.
     */
    public function test_shm_journals_do_not_appear_in_cooperative_reports(): void
    {
        // 1. Create SHM accounts
        $shmAsset = ChartOfAccount::create([
            'accounting_context' => AccountingContext::SHM,
            'code' => '1-1001',
            'name' => 'Bank SHM',
            'category' => ChartOfAccount::CATEGORY_ASSET,
            'normal_balance' => ChartOfAccount::NORMAL_DEBIT,
            'is_active' => true,
        ]);

        $shmRevenue = ChartOfAccount::create([
            'accounting_context' => AccountingContext::SHM,
            'code' => '4-1001',
            'name' => 'Pendapatan SHM',
            'category' => ChartOfAccount::CATEGORY_REVENUE,
            'normal_balance' => ChartOfAccount::NORMAL_CREDIT,
            'is_active' => true,
        ]);

        // 2. Create and post SHM journal
        $shmEntry = $this->journalService->createDraft(
            [
                'transaction_date' => now()->toDateString(),
                'description' => 'SHM Transaction Only',
                'accounting_context' => AccountingContext::SHM,
            ],
            [
                ['account_id' => $shmAsset->id, 'debit' => 5000000, 'credit' => 0],
                ['account_id' => $shmRevenue->id, 'debit' => 0, 'credit' => 5000000],
            ],
            $this->admin->id
        );
        $this->journalService->post($shmEntry, $this->admin->id);

        // 3. Generate Koperasi Trial Balance
        $tbKoperasi = $this->reportService->trialBalance(null, null, true, AccountingContext::KOPERASI);
        $tbKoperasiCodes = collect($tbKoperasi['rows'])->pluck('code')->all();

        $this->assertNotContains('1-1001', $tbKoperasiCodes);
        $this->assertNotContains('4-1001', $tbKoperasiCodes);

        // 4. Generate SHM Trial Balance
        $tbShm = $this->reportService->trialBalance(null, null, true, AccountingContext::SHM);
        $tbShmCodes = collect($tbShm['rows'])->pluck('code')->all();

        $this->assertContains('1-1001', $tbShmCodes);
        $this->assertContains('4-1001', $tbShmCodes);
        $this->assertEquals(5000000.0, $tbShm['totals']['debit']);
        $this->assertEquals(5000000.0, $tbShm['totals']['credit']);
    }

    /**
     * Test 6: Ledger queries respect context boundaries.
     */
    public function test_ledger_respects_context_boundaries(): void
    {
        $shmAsset = ChartOfAccount::create([
            'accounting_context' => AccountingContext::SHM,
            'code' => '1-1002',
            'name' => 'Kas SHM',
            'category' => ChartOfAccount::CATEGORY_ASSET,
            'normal_balance' => ChartOfAccount::NORMAL_DEBIT,
            'is_active' => true,
        ]);

        // Attempting to query an SHM account via Koperasi ledger context must fail
        $this->expectException(ValidationException::class);
        $this->journalService->ledger($shmAsset->id, null, null, AccountingContext::KOPERASI);
    }

    /**
     * Test 7: SHM accounting permissions exist in the permissions table.
     */
    public function test_shm_accounting_permissions_exist(): void
    {
        $expected = [
            'accounting.shm.view',
            'accounting.shm.accounts.manage',
            'accounting.shm.journals.view',
            'accounting.shm.journals.create',
            'accounting.shm.journals.post',
            'accounting.shm.journals.reverse',
            'accounting.shm.ledger.view',
            'accounting.shm.reports.view',
        ];

        foreach ($expected as $name) {
            $this->assertDatabaseHas('permissions', ['name' => $name]);
        }
    }
}
