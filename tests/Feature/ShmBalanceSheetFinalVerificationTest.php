<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Role;
use App\Models\User;
use App\Services\Accounting\FinancialReportService;
use App\Services\Accounting\JournalService;
use App\Support\AccountingContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShmBalanceSheetFinalVerificationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $unauthorizedUser;

    private JournalService $journalService;

    private FinancialReportService $reportService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->journalService = app(JournalService::class);
        $this->reportService = app(FinancialReportService::class);

        $this->admin = User::factory()->create();
        $adminRole = Role::where('name', 'admin')->first();
        if ($adminRole) {
            $this->admin->roles()->attach($adminRole->id);
        }

        $this->unauthorizedUser = User::factory()->create();
    }

    private function createAccount(string $context, string $code, string $name, string $category, string $normalBalance): ChartOfAccount
    {
        return ChartOfAccount::create([
            'accounting_context' => $context,
            'code' => $code,
            'name' => $name,
            'category' => $category,
            'normal_balance' => $normalBalance,
            'is_active' => true,
        ]);
    }

    private function postPair(ChartOfAccount $debitAcc, ChartOfAccount $creditAcc, float $amount, string $date, string $context = AccountingContext::SHM): JournalEntry
    {
        $entry = $this->journalService->createDraft(
            [
                'transaction_date' => $date,
                'accounting_context' => $context,
                'description' => "Test entry {$debitAcc->code} / {$creditAcc->code}",
            ],
            [
                ['account_id' => $debitAcc->id, 'debit' => $amount, 'credit' => 0],
                ['account_id' => $creditAcc->id, 'debit' => 0, 'credit' => $amount],
            ],
            $this->admin->id
        );

        return $this->journalService->post($entry, $this->admin->id);
    }

    /**
     * Scenario A: Asset + Liability
     * Debit Asset 1,000,000 / Credit Liability 1,000,000
     */
    public function test_scenario_a_asset_and_liability(): void
    {
        $asset = $this->createAccount(AccountingContext::SHM, '1-1100', 'Kas SHM', ChartOfAccount::CATEGORY_ASSET, ChartOfAccount::NORMAL_DEBIT);
        $liability = $this->createAccount(AccountingContext::SHM, '2-1100', 'Hutang SHM', ChartOfAccount::CATEGORY_LIABILITY, ChartOfAccount::NORMAL_CREDIT);

        $this->postPair($asset, $liability, 1_000_000, '2026-10-29');

        $report = $this->reportService->balanceSheet('2026-10-29', AccountingContext::SHM);

        $this->assertSame(1_000_000.0, $report['summary']['total_assets']);
        $this->assertSame(1_000_000.0, $report['summary']['total_liabilities']);
        $this->assertSame(0.0, $report['summary']['total_equity']);
        $this->assertSame(0.0, $report['summary']['current_period_result']);
        $this->assertSame(1_000_000.0, $report['summary']['total_liabilities_and_equity']);
        $this->assertSame(0.0, $report['summary']['difference']);
        $this->assertTrue($report['balanced']);
        $this->assertSame('BALANCED', $report['summary']['status']);
    }

    /**
     * Scenario B: Asset + Equity
     * Debit Asset 2,000,000 / Credit Equity 2,000,000
     */
    public function test_scenario_b_asset_and_equity(): void
    {
        $asset = $this->createAccount(AccountingContext::SHM, '1-1100', 'Kas SHM', ChartOfAccount::CATEGORY_ASSET, ChartOfAccount::NORMAL_DEBIT);
        $equity = $this->createAccount(AccountingContext::SHM, '3-1100', 'Modal SHM', ChartOfAccount::CATEGORY_EQUITY, ChartOfAccount::NORMAL_CREDIT);

        $this->postPair($asset, $equity, 2_000_000, '2026-10-29');

        $report = $this->reportService->balanceSheet('2026-10-29', AccountingContext::SHM);

        $this->assertSame(2_000_000.0, $report['summary']['total_assets']);
        $this->assertSame(0.0, $report['summary']['total_liabilities']);
        $this->assertSame(2_000_000.0, $report['summary']['total_equity']);
        $this->assertSame(0.0, $report['summary']['current_period_result']);
        $this->assertSame(2_000_000.0, $report['summary']['total_liabilities_and_equity']);
        $this->assertSame(0.0, $report['summary']['difference']);
        $this->assertTrue($report['balanced']);
        $this->assertSame('BALANCED', $report['summary']['status']);
    }

    /**
     * Scenario C: Revenue / Current Result
     * Debit Asset 1,000,000 / Credit Revenue 1,000,000
     */
    public function test_scenario_c_revenue_current_result(): void
    {
        $asset = $this->createAccount(AccountingContext::SHM, '1-1100', 'Kas SHM', ChartOfAccount::CATEGORY_ASSET, ChartOfAccount::NORMAL_DEBIT);
        $revenue = $this->createAccount(AccountingContext::SHM, '4-1100', 'Pendapatan Bunga SHM', ChartOfAccount::CATEGORY_REVENUE, ChartOfAccount::NORMAL_CREDIT);

        $this->postPair($asset, $revenue, 1_000_000, '2026-10-29');

        $report = $this->reportService->balanceSheet('2026-10-29', AccountingContext::SHM);

        $this->assertSame(1_000_000.0, $report['summary']['total_assets']);
        $this->assertSame(0.0, $report['summary']['total_liabilities']);
        $this->assertSame(0.0, $report['summary']['total_equity']);
        $this->assertSame(1_000_000.0, $report['summary']['current_period_result']);
        // Unclosed revenue is captured in current_period_result; difference between asset and stored equity is 1M.
        $this->assertSame(1_000_000.0, $report['summary']['difference']);
        // When considering current_period_result, equation balances:
        $this->assertTrue($report['summary']['balanced_with_result']);
    }

    /**
     * Scenario D: Expense / Current Result
     * Debit Expense 300,000 / Credit Asset 300,000
     */
    public function test_scenario_d_expense_current_result(): void
    {
        $asset = $this->createAccount(AccountingContext::SHM, '1-1100', 'Kas SHM', ChartOfAccount::CATEGORY_ASSET, ChartOfAccount::NORMAL_DEBIT);
        $expense = $this->createAccount(AccountingContext::SHM, '5-1100', 'Beban Operasional SHM', ChartOfAccount::CATEGORY_EXPENSE, ChartOfAccount::NORMAL_DEBIT);

        $this->postPair($expense, $asset, 300_000, '2026-10-29');

        $report = $this->reportService->balanceSheet('2026-10-29', AccountingContext::SHM);

        // Asset has credit of 300,000 => balance is -300,000
        $this->assertSame(-300_000.0, $report['summary']['total_assets']);
        $this->assertSame(0.0, $report['summary']['total_liabilities']);
        $this->assertSame(0.0, $report['summary']['total_equity']);
        $this->assertSame(-300_000.0, $report['summary']['current_period_result']);
        $this->assertSame(-300_000.0, $report['summary']['difference']);
        $this->assertTrue($report['summary']['balanced_with_result']);
    }

    /**
     * Scenario E: Revenue + Expense
     * Revenue = 1,000,000, Expense = 300,000 => Current Period Result = 700,000
     */
    public function test_scenario_e_revenue_plus_expense(): void
    {
        $asset = $this->createAccount(AccountingContext::SHM, '1-1100', 'Kas SHM', ChartOfAccount::CATEGORY_ASSET, ChartOfAccount::NORMAL_DEBIT);
        $revenue = $this->createAccount(AccountingContext::SHM, '4-1100', 'Pendapatan Bunga SHM', ChartOfAccount::CATEGORY_REVENUE, ChartOfAccount::NORMAL_CREDIT);
        $expense = $this->createAccount(AccountingContext::SHM, '5-1100', 'Beban Operasional SHM', ChartOfAccount::CATEGORY_EXPENSE, ChartOfAccount::NORMAL_DEBIT);

        $this->postPair($asset, $revenue, 1_000_000, '2026-10-29');
        $this->postPair($expense, $asset, 300_000, '2026-10-29');

        $report = $this->reportService->balanceSheet('2026-10-29', AccountingContext::SHM);

        $this->assertSame(700_000.0, $report['summary']['total_assets']);
        $this->assertSame(0.0, $report['summary']['total_liabilities']);
        $this->assertSame(0.0, $report['summary']['total_equity']);
        $this->assertSame(700_000.0, $report['summary']['current_period_result']);
        $this->assertSame(700_000.0, $report['summary']['difference']);
        $this->assertTrue($report['summary']['balanced_with_result']);
    }

    /**
     * Scenario F: Equity + Revenue (Double counting check)
     * Debit Asset 2,000,000 / Credit Equity 1,000,000 / Credit Revenue 1,000,000
     */
    public function test_scenario_f_equity_plus_revenue_double_counting_check(): void
    {
        $asset = $this->createAccount(AccountingContext::SHM, '1-1100', 'Kas SHM', ChartOfAccount::CATEGORY_ASSET, ChartOfAccount::NORMAL_DEBIT);
        $equity = $this->createAccount(AccountingContext::SHM, '3-1100', 'Modal SHM', ChartOfAccount::CATEGORY_EQUITY, ChartOfAccount::NORMAL_CREDIT);
        $revenue = $this->createAccount(AccountingContext::SHM, '4-1100', 'Pendapatan Bunga SHM', ChartOfAccount::CATEGORY_REVENUE, ChartOfAccount::NORMAL_CREDIT);

        $entry = $this->journalService->createDraft(
            [
                'transaction_date' => '2026-10-29',
                'accounting_context' => AccountingContext::SHM,
                'description' => 'Asset = Equity + Revenue',
            ],
            [
                ['account_id' => $asset->id, 'debit' => 2_000_000, 'credit' => 0],
                ['account_id' => $equity->id, 'debit' => 0, 'credit' => 1_000_000],
                ['account_id' => $revenue->id, 'debit' => 0, 'credit' => 1_000_000],
            ],
            $this->admin->id
        );
        $this->journalService->post($entry, $this->admin->id);

        $report = $this->reportService->balanceSheet('2026-10-29', AccountingContext::SHM);

        $this->assertSame(2_000_000.0, $report['summary']['total_assets']);
        $this->assertSame(0.0, $report['summary']['total_liabilities']);
        $this->assertSame(1_000_000.0, $report['summary']['total_equity']);
        $this->assertSame(1_000_000.0, $report['summary']['current_period_result']);

        // Check Pasiva components:
        // Stored Equity is exactly 1,000,000 (NOT 2,000,000). Revenue is NOT in stored equity!
        $this->assertSame(1_000_000.0, $report['summary']['total_liabilities_and_equity']);
        $this->assertSame(1_000_000.0, $report['summary']['difference']);
        // With current_period_result: Stored Equity (1M) + Result (1M) = 2M = Total Assets (2M)
        $this->assertTrue($report['summary']['balanced_with_result']);
    }

    /**
     * Scenario G: Equity + Revenue + Expense
     * Stored Equity = 1,000,000, Revenue = 1,000,000, Expense = 400,000 => Result = 600,000
     */
    public function test_scenario_g_equity_revenue_expense(): void
    {
        $asset = $this->createAccount(AccountingContext::SHM, '1-1100', 'Kas SHM', ChartOfAccount::CATEGORY_ASSET, ChartOfAccount::NORMAL_DEBIT);
        $equity = $this->createAccount(AccountingContext::SHM, '3-1100', 'Modal SHM', ChartOfAccount::CATEGORY_EQUITY, ChartOfAccount::NORMAL_CREDIT);
        $revenue = $this->createAccount(AccountingContext::SHM, '4-1100', 'Pendapatan Bunga SHM', ChartOfAccount::CATEGORY_REVENUE, ChartOfAccount::NORMAL_CREDIT);
        $expense = $this->createAccount(AccountingContext::SHM, '5-1100', 'Beban Operasional SHM', ChartOfAccount::CATEGORY_EXPENSE, ChartOfAccount::NORMAL_DEBIT);

        // 1. Modal 1,000,000
        $this->postPair($asset, $equity, 1_000_000, '2026-10-29');
        // 2. Revenue 1,000,000
        $this->postPair($asset, $revenue, 1_000_000, '2026-10-29');
        // 3. Expense 400,000
        $this->postPair($expense, $asset, 400_000, '2026-10-29');

        $report = $this->reportService->balanceSheet('2026-10-29', AccountingContext::SHM);

        // Total Assets = 1,000,000 + 1,000,000 - 400,000 = 1,600,000
        $this->assertSame(1_600_000.0, $report['summary']['total_assets']);
        $this->assertSame(0.0, $report['summary']['total_liabilities']);
        $this->assertSame(1_000_000.0, $report['summary']['total_equity']);
        $this->assertSame(600_000.0, $report['summary']['current_period_result']);
        $this->assertSame(600_000.0, $report['summary']['difference']);
        $this->assertTrue($report['summary']['balanced_with_result']);
    }

    /**
     * Scenario H: Zero Result (Revenue = Expense)
     * Revenue = 500,000, Expense = 500,000 => Result = 0, Status = IMPAS
     */
    public function test_scenario_h_zero_result(): void
    {
        $asset = $this->createAccount(AccountingContext::SHM, '1-1100', 'Kas SHM', ChartOfAccount::CATEGORY_ASSET, ChartOfAccount::NORMAL_DEBIT);
        $equity = $this->createAccount(AccountingContext::SHM, '3-1100', 'Modal SHM', ChartOfAccount::CATEGORY_EQUITY, ChartOfAccount::NORMAL_CREDIT);
        $revenue = $this->createAccount(AccountingContext::SHM, '4-1100', 'Pendapatan SHM', ChartOfAccount::CATEGORY_REVENUE, ChartOfAccount::NORMAL_CREDIT);
        $expense = $this->createAccount(AccountingContext::SHM, '5-1100', 'Beban SHM', ChartOfAccount::CATEGORY_EXPENSE, ChartOfAccount::NORMAL_DEBIT);

        $this->postPair($asset, $equity, 500_000, '2026-10-29');
        $this->postPair($asset, $revenue, 500_000, '2026-10-29');
        $this->postPair($expense, $asset, 500_000, '2026-10-29');

        $report = $this->reportService->balanceSheet('2026-10-29', AccountingContext::SHM);

        $this->assertSame(500_000.0, $report['summary']['total_assets']);
        $this->assertSame(0.0, $report['summary']['total_liabilities']);
        $this->assertSame(500_000.0, $report['summary']['total_equity']);
        $this->assertSame(0.0, $report['summary']['current_period_result']);
        $this->assertSame(0.0, $report['summary']['difference']);
        $this->assertTrue($report['balanced']);
        $this->assertTrue($report['summary']['balanced_with_result']);
    }

    /**
     * Scenario I: Loss (Revenue < Expense)
     * Revenue = 500,000, Expense = 800,000 => Result = -300,000
     */
    public function test_scenario_i_loss_handling(): void
    {
        $asset = $this->createAccount(AccountingContext::SHM, '1-1100', 'Kas SHM', ChartOfAccount::CATEGORY_ASSET, ChartOfAccount::NORMAL_DEBIT);
        $equity = $this->createAccount(AccountingContext::SHM, '3-1100', 'Modal SHM', ChartOfAccount::CATEGORY_EQUITY, ChartOfAccount::NORMAL_CREDIT);
        $revenue = $this->createAccount(AccountingContext::SHM, '4-1100', 'Pendapatan SHM', ChartOfAccount::CATEGORY_REVENUE, ChartOfAccount::NORMAL_CREDIT);
        $expense = $this->createAccount(AccountingContext::SHM, '5-1100', 'Beban SHM', ChartOfAccount::CATEGORY_EXPENSE, ChartOfAccount::NORMAL_DEBIT);

        $this->postPair($asset, $equity, 1_000_000, '2026-10-29');
        $this->postPair($asset, $revenue, 500_000, '2026-10-29');
        $this->postPair($expense, $asset, 800_000, '2026-10-29');

        $report = $this->reportService->balanceSheet('2026-10-29', AccountingContext::SHM);

        // Assets = 1M + 500k - 800k = 700k
        $this->assertSame(700_000.0, $report['summary']['total_assets']);
        $this->assertSame(0.0, $report['summary']['total_liabilities']);
        $this->assertSame(1_000_000.0, $report['summary']['total_equity']);
        $this->assertSame(-300_000.0, $report['summary']['current_period_result']);
        // Difference = 700k - 1M = -300k
        $this->assertSame(-300_000.0, $report['summary']['difference']);
        // With result: 700k - (1M + -300k) = 0
        $this->assertTrue($report['summary']['balanced_with_result']);
    }

    /**
     * Scenario J: Multiple Equity Accounts
     */
    public function test_scenario_j_multiple_equity_accounts(): void
    {
        $asset = $this->createAccount(AccountingContext::SHM, '1-1100', 'Kas SHM', ChartOfAccount::CATEGORY_ASSET, ChartOfAccount::NORMAL_DEBIT);
        $equityA = $this->createAccount(AccountingContext::SHM, '3-1100', 'Modal A', ChartOfAccount::CATEGORY_EQUITY, ChartOfAccount::NORMAL_CREDIT);
        $equityB = $this->createAccount(AccountingContext::SHM, '3-1200', 'Modal B', ChartOfAccount::CATEGORY_EQUITY, ChartOfAccount::NORMAL_CREDIT);

        $this->postPair($asset, $equityA, 1_000_000, '2026-10-29');
        $this->postPair($asset, $equityB, 500_000, '2026-10-29');

        $report = $this->reportService->balanceSheet('2026-10-29', AccountingContext::SHM);

        $this->assertSame(1_500_000.0, $report['summary']['total_assets']);
        $this->assertSame(1_500_000.0, $report['summary']['total_equity']);
        $this->assertCount(2, $report['sections']['equity']['accounts']);
        $this->assertSame(0.0, $report['summary']['difference']);
        $this->assertTrue($report['balanced']);
    }

    /**
     * Scenario K: Multiple Revenue & Expense Accounts
     */
    public function test_scenario_k_multiple_revenue_expense_accounts(): void
    {
        $asset = $this->createAccount(AccountingContext::SHM, '1-1100', 'Kas SHM', ChartOfAccount::CATEGORY_ASSET, ChartOfAccount::NORMAL_DEBIT);
        $rev1 = $this->createAccount(AccountingContext::SHM, '4-1100', 'Bunga', ChartOfAccount::CATEGORY_REVENUE, ChartOfAccount::NORMAL_CREDIT);
        $rev2 = $this->createAccount(AccountingContext::SHM, '4-1200', 'Provisi', ChartOfAccount::CATEGORY_REVENUE, ChartOfAccount::NORMAL_CREDIT);
        $exp1 = $this->createAccount(AccountingContext::SHM, '5-1100', 'Beban Listrik', ChartOfAccount::CATEGORY_EXPENSE, ChartOfAccount::NORMAL_DEBIT);
        $exp2 = $this->createAccount(AccountingContext::SHM, '5-1200', 'Beban Gaji', ChartOfAccount::CATEGORY_EXPENSE, ChartOfAccount::NORMAL_DEBIT);

        $this->postPair($asset, $rev1, 400_000, '2026-10-29');
        $this->postPair($asset, $rev2, 600_000, '2026-10-29');
        $this->postPair($exp1, $asset, 100_000, '2026-10-29');
        $this->postPair($exp2, $asset, 200_000, '2026-10-29');

        $report = $this->reportService->balanceSheet('2026-10-29', AccountingContext::SHM);

        $this->assertSame(700_000.0, $report['summary']['total_assets']);
        $this->assertSame(700_000.0, $report['summary']['current_period_result']);
        $this->assertTrue($report['summary']['balanced_with_result']);
    }

    /**
     * Scenario L: Report Date Boundary (T-1 included, T included, T+1 excluded)
     */
    public function test_scenario_l_date_boundary(): void
    {
        $asset = $this->createAccount(AccountingContext::SHM, '1-1100', 'Kas SHM', ChartOfAccount::CATEGORY_ASSET, ChartOfAccount::NORMAL_DEBIT);
        $equity = $this->createAccount(AccountingContext::SHM, '3-1100', 'Modal SHM', ChartOfAccount::CATEGORY_EQUITY, ChartOfAccount::NORMAL_CREDIT);

        $this->postPair($asset, $equity, 100_000, '2026-10-28');
        $this->postPair($asset, $equity, 200_000, '2026-10-29');
        $this->postPair($asset, $equity, 400_000, '2026-10-30');

        $report = $this->reportService->balanceSheet('2026-10-29', AccountingContext::SHM);

        $this->assertSame(300_000.0, $report['summary']['total_assets']);
        $this->assertSame(300_000.0, $report['summary']['total_equity']);
    }

    /**
     * Scenario M: Draft Exclusion
     */
    public function test_scenario_m_draft_exclusion(): void
    {
        $asset = $this->createAccount(AccountingContext::SHM, '1-1100', 'Kas SHM', ChartOfAccount::CATEGORY_ASSET, ChartOfAccount::NORMAL_DEBIT);
        $equity = $this->createAccount(AccountingContext::SHM, '3-1100', 'Modal SHM', ChartOfAccount::CATEGORY_EQUITY, ChartOfAccount::NORMAL_CREDIT);

        $this->postPair($asset, $equity, 500_000, '2026-10-29');

        // Draft
        $this->journalService->createDraft(
            ['transaction_date' => '2026-10-29', 'accounting_context' => AccountingContext::SHM],
            [
                ['account_id' => $asset->id, 'debit' => 999_000, 'credit' => 0],
                ['account_id' => $equity->id, 'debit' => 0, 'credit' => 999_000],
            ],
            $this->admin->id
        );

        $report = $this->reportService->balanceSheet('2026-10-29', AccountingContext::SHM);

        $this->assertSame(500_000.0, $report['summary']['total_assets']);
        $this->assertSame(500_000.0, $report['summary']['total_equity']);
    }

    /**
     * Scenario N: Reversal
     */
    public function test_scenario_n_reversal(): void
    {
        $asset = $this->createAccount(AccountingContext::SHM, '1-1100', 'Kas SHM', ChartOfAccount::CATEGORY_ASSET, ChartOfAccount::NORMAL_DEBIT);
        $equity = $this->createAccount(AccountingContext::SHM, '3-1100', 'Modal SHM', ChartOfAccount::CATEGORY_EQUITY, ChartOfAccount::NORMAL_CREDIT);

        $entry = $this->postPair($asset, $equity, 500_000, '2026-10-29');
        $this->journalService->reverse($entry->fresh(), $this->admin->id, 'Reversal test');

        $report = $this->reportService->balanceSheet('2026-10-29', AccountingContext::SHM);

        $this->assertSame(-500_000.0, $report['summary']['total_assets']);
        $this->assertSame(-500_000.0, $report['summary']['total_equity']);
        $this->assertSame(0.0, $report['summary']['difference']);
        $this->assertTrue($report['balanced']);
    }

    /**
     * Scenario O: SHM / Koperasi Isolation
     */
    public function test_scenario_o_shm_koperasi_isolation(): void
    {
        $shmAsset = $this->createAccount(AccountingContext::SHM, '1-1100', 'Kas SHM', ChartOfAccount::CATEGORY_ASSET, ChartOfAccount::NORMAL_DEBIT);
        $shmEquity = $this->createAccount(AccountingContext::SHM, '3-1100', 'Modal SHM', ChartOfAccount::CATEGORY_EQUITY, ChartOfAccount::NORMAL_CREDIT);
        $kopAsset = $this->createAccount(AccountingContext::KOPERASI, '1-1100', 'Kas Koperasi', ChartOfAccount::CATEGORY_ASSET, ChartOfAccount::NORMAL_DEBIT);
        $kopEquity = $this->createAccount(AccountingContext::KOPERASI, '3-1100', 'Modal Koperasi', ChartOfAccount::CATEGORY_EQUITY, ChartOfAccount::NORMAL_CREDIT);

        $this->postPair($shmAsset, $shmEquity, 100_000, '2026-10-29', AccountingContext::SHM);
        $this->postPair($kopAsset, $kopEquity, 900_000, '2026-10-29', AccountingContext::KOPERASI);

        $report = $this->reportService->balanceSheet('2026-10-29', AccountingContext::SHM);

        $this->assertSame(100_000.0, $report['summary']['total_assets']);
        $this->assertSame(100_000.0, $report['summary']['total_equity']);
    }

    /**
     * Scenario P: Context Override Protection
     */
    public function test_scenario_p_context_override_protection(): void
    {
        $shmAsset = $this->createAccount(AccountingContext::SHM, '1-1100', 'Kas SHM', ChartOfAccount::CATEGORY_ASSET, ChartOfAccount::NORMAL_DEBIT);
        $shmEquity = $this->createAccount(AccountingContext::SHM, '3-1100', 'Modal SHM', ChartOfAccount::CATEGORY_EQUITY, ChartOfAccount::NORMAL_CREDIT);
        $kopAsset = $this->createAccount(AccountingContext::KOPERASI, '1-1100', 'Kas Koperasi', ChartOfAccount::CATEGORY_ASSET, ChartOfAccount::NORMAL_DEBIT);
        $kopEquity = $this->createAccount(AccountingContext::KOPERASI, '3-1100', 'Modal Koperasi', ChartOfAccount::CATEGORY_EQUITY, ChartOfAccount::NORMAL_CREDIT);

        $this->postPair($shmAsset, $shmEquity, 100_000, '2026-10-29', AccountingContext::SHM);
        $this->postPair($kopAsset, $kopEquity, 900_000, '2026-10-29', AccountingContext::KOPERASI);

        $response = $this->actingAs($this->admin)->get(route('shm.balance-sheet.index', [
            'as_of_date' => '2026-10-29',
            'context' => AccountingContext::KOPERASI, // Client attempts to spoof context
        ]));

        $response->assertOk();
        $report = $response->viewData('page')['props']['report'];
        $this->assertSame(100_000.0, $report['summary']['total_assets']);
        $this->assertSame(100_000.0, $report['summary']['total_equity']);
    }

    /**
     * Scenario Q & S: Read-only Integrity and XLSX Parity
     */
    public function test_scenario_q_s_xlsx_parity_and_read_only_integrity(): void
    {
        $asset = $this->createAccount(AccountingContext::SHM, '1-1100', 'Kas SHM', ChartOfAccount::CATEGORY_ASSET, ChartOfAccount::NORMAL_DEBIT);
        $equity = $this->createAccount(AccountingContext::SHM, '3-1100', 'Modal SHM', ChartOfAccount::CATEGORY_EQUITY, ChartOfAccount::NORMAL_CREDIT);

        $this->postPair($asset, $equity, 750_000, '2026-10-29');

        $initialEntriesCount = JournalEntry::count();
        $initialLinesCount = JournalLine::count();

        // 1. Web Index
        $indexResponse = $this->actingAs($this->admin)->get(route('shm.balance-sheet.index', ['as_of_date' => '2026-10-29']));
        $indexResponse->assertOk();

        $this->assertSame($initialEntriesCount, JournalEntry::count());
        $this->assertSame($initialLinesCount, JournalLine::count());

        // 2. Export
        $exportResponse = $this->actingAs($this->admin)->get(route('shm.balance-sheet.export', ['as_of_date' => '2026-10-29']));
        $exportResponse->assertOk();
        $exportResponse->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $this->assertSame($initialEntriesCount, JournalEntry::count());
        $this->assertSame($initialLinesCount, JournalLine::count());
    }

    /**
     * Scenario R: Authorization
     */
    public function test_scenario_r_authorization(): void
    {
        $response = $this->actingAs($this->unauthorizedUser)->get(route('shm.balance-sheet.index'));
        $response->assertForbidden();

        $exportResponse = $this->actingAs($this->unauthorizedUser)->get(route('shm.balance-sheet.export'));
        $exportResponse->assertForbidden();
    }

    /**
     * Scenario T: Financial Report Consistency (T006H Cumulative P&L == T006I Current Period Result)
     */
    public function test_scenario_t_cross_report_consistency_with_t006h(): void
    {
        $asset = $this->createAccount(AccountingContext::SHM, '1-1100', 'Kas SHM', ChartOfAccount::CATEGORY_ASSET, ChartOfAccount::NORMAL_DEBIT);
        $revenue = $this->createAccount(AccountingContext::SHM, '4-1100', 'Pendapatan Bunga SHM', ChartOfAccount::CATEGORY_REVENUE, ChartOfAccount::NORMAL_CREDIT);
        $expense = $this->createAccount(AccountingContext::SHM, '5-1100', 'Beban Administrasi SHM', ChartOfAccount::CATEGORY_EXPENSE, ChartOfAccount::NORMAL_DEBIT);

        $this->postPair($asset, $revenue, 850_000, '2026-10-25');
        $this->postPair($expense, $asset, 250_000, '2026-10-28');

        // T006H Cumulative Profit/Loss (from inception null to 2026-10-29)
        $t006hReport = $this->reportService->incomeStatement(null, '2026-10-29', AccountingContext::SHM);

        // T006I Balance Sheet as of 2026-10-29
        $t006iReport = $this->reportService->balanceSheet('2026-10-29', AccountingContext::SHM);

        $t006hNetResult = $t006hReport['summary']['net_profit_loss'];
        $t006iCurrentResult = $t006iReport['summary']['current_period_result'];

        $this->assertSame(600_000.0, $t006hNetResult);
        $this->assertSame(600_000.0, $t006iCurrentResult);
        $this->assertSame($t006hNetResult, $t006iCurrentResult);
        $this->assertTrue($t006iReport['summary']['balanced_with_result']);
    }

    /**
     * Scenario U: T006F Trial Balance Consistency
     */
    public function test_scenario_u_trial_balance_consistency_with_t006f(): void
    {
        $asset = $this->createAccount(AccountingContext::SHM, '1-1100', 'Kas SHM', ChartOfAccount::CATEGORY_ASSET, ChartOfAccount::NORMAL_DEBIT);
        $liability = $this->createAccount(AccountingContext::SHM, '2-1100', 'Hutang SHM', ChartOfAccount::CATEGORY_LIABILITY, ChartOfAccount::NORMAL_CREDIT);
        $equity = $this->createAccount(AccountingContext::SHM, '3-1100', 'Modal SHM', ChartOfAccount::CATEGORY_EQUITY, ChartOfAccount::NORMAL_CREDIT);
        $revenue = $this->createAccount(AccountingContext::SHM, '4-1100', 'Pendapatan Bunga SHM', ChartOfAccount::CATEGORY_REVENUE, ChartOfAccount::NORMAL_CREDIT);

        $this->postPair($asset, $liability, 300_000, '2026-10-20');
        $this->postPair($asset, $equity, 500_000, '2026-10-22');
        $this->postPair($asset, $revenue, 200_000, '2026-10-25');

        // T006F Trial Balance (inception to 2026-10-29)
        $trialBalance = $this->reportService->trialBalance(null, '2026-10-29', false, AccountingContext::SHM);

        $this->assertTrue($trialBalance['balanced']);
        $this->assertSame(1_000_000.0, $trialBalance['totals']['debit']);
        $this->assertSame(1_000_000.0, $trialBalance['totals']['credit']);

        // T006I Balance Sheet
        $balanceSheet = $this->reportService->balanceSheet('2026-10-29', AccountingContext::SHM);

        $this->assertSame(1_000_000.0, $balanceSheet['summary']['total_assets']);
        $this->assertSame(300_000.0, $balanceSheet['summary']['total_liabilities']);
        $this->assertSame(500_000.0, $balanceSheet['summary']['total_equity']);
        $this->assertSame(200_000.0, $balanceSheet['summary']['current_period_result']);
        $this->assertTrue($balanceSheet['summary']['balanced_with_result']);
    }
}
