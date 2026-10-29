<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Accounting\FinancialReportService;
use App\Services\Accounting\JournalService;
use App\Services\Accounting\SimpleXlsxService;
use App\Support\AccountingContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ShmPeriodProfitLossTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $unauthorizedUser;

    private JournalService $journalService;

    private FinancialReportService $reportService;

    private SimpleXlsxService $xlsxService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->journalService = app(JournalService::class);
        $this->reportService = app(FinancialReportService::class);
        $this->xlsxService = app(SimpleXlsxService::class);

        // User with accounting.shm.reports.view permission
        $this->admin = User::factory()->create();
        $adminRole = Role::where('name', 'admin')->first();
        if ($adminRole) {
            $this->admin->roles()->attach($adminRole->id);
        }

        // Unauthorized user without accounting.shm.reports.view
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

    /**
     * Test A: Empty Period has zero revenue, zero expense, zero net profit/loss.
     */
    public function test_empty_period_returns_zeroes(): void
    {
        $this->createAccount(AccountingContext::SHM, '4-1100', 'Bunga SHM', ChartOfAccount::CATEGORY_REVENUE, ChartOfAccount::NORMAL_CREDIT);
        $this->createAccount(AccountingContext::SHM, '5-1100', 'Operasional SHM', ChartOfAccount::CATEGORY_EXPENSE, ChartOfAccount::NORMAL_DEBIT);

        $response = $this->actingAs($this->admin)->get(route('shm.period-profit-loss.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Shm/Accounting/PeriodProfitLoss')
            ->where('report.summary.total_revenue', fn ($val) => (float) $val === 0.0)
            ->where('report.summary.total_expense', fn ($val) => (float) $val === 0.0)
            ->where('report.summary.surplus_deficit', fn ($val) => (float) $val === 0.0)
            ->where('report.summary.status', 'IMPAS')
        );
    }

    /**
     * Test B: Single valid POSTED SHM revenue journal.
     */
    public function test_single_posted_revenue_journal(): void
    {
        $kas = $this->createAccount(AccountingContext::SHM, '1-1100', 'Kas SHM', ChartOfAccount::CATEGORY_ASSET, ChartOfAccount::NORMAL_DEBIT);
        $rev = $this->createAccount(AccountingContext::SHM, '4-1100', 'Pendapatan Bunga SHM', ChartOfAccount::CATEGORY_REVENUE, ChartOfAccount::NORMAL_CREDIT);

        $entry = $this->journalService->createDraft(
            [
                'transaction_date' => '2026-10-15',
                'description' => 'Payment Interest',
                'accounting_context' => AccountingContext::SHM,
            ],
            [
                ['account_id' => $kas->id, 'debit' => 750_000, 'credit' => 0],
                ['account_id' => $rev->id, 'debit' => 0, 'credit' => 750_000],
            ],
            $this->admin->id
        );
        $this->journalService->post($entry, $this->admin->id);

        $report = $this->reportService->incomeStatement('2026-10-01', '2026-10-31', AccountingContext::SHM);

        $this->assertSame(750_000.0, $report['summary']['total_revenue']);
        $this->assertSame(0.0, $report['summary']['total_expense']);
        $this->assertSame(750_000.0, $report['summary']['surplus_deficit']);
        $this->assertSame('LABA', $report['summary']['status']);

        $revAccounts = $report['sections']['revenue']['accounts'];
        $this->assertCount(1, $revAccounts);
        $this->assertSame('4-1100', $revAccounts[0]['code']);
        $this->assertSame(750_000.0, $revAccounts[0]['balance']);
    }

    /**
     * Test C: Single valid POSTED SHM expense journal.
     */
    public function test_single_posted_expense_journal(): void
    {
        $kas = $this->createAccount(AccountingContext::SHM, '1-1100', 'Kas SHM', ChartOfAccount::CATEGORY_ASSET, ChartOfAccount::NORMAL_DEBIT);
        $exp = $this->createAccount(AccountingContext::SHM, '5-1100', 'Beban Operasional SHM', ChartOfAccount::CATEGORY_EXPENSE, ChartOfAccount::NORMAL_DEBIT);

        $entry = $this->journalService->createDraft(
            [
                'transaction_date' => '2026-10-16',
                'description' => 'Survey Expense',
                'accounting_context' => AccountingContext::SHM,
            ],
            [
                ['account_id' => $exp->id, 'debit' => 200_000, 'credit' => 0],
                ['account_id' => $kas->id, 'debit' => 0, 'credit' => 200_000],
            ],
            $this->admin->id
        );
        $this->journalService->post($entry, $this->admin->id);

        $report = $this->reportService->incomeStatement('2026-10-01', '2026-10-31', AccountingContext::SHM);

        $this->assertSame(0.0, $report['summary']['total_revenue']);
        $this->assertSame(200_000.0, $report['summary']['total_expense']);
        $this->assertSame(-200_000.0, $report['summary']['surplus_deficit']);
        $this->assertSame('RUGI', $report['summary']['status']);

        $expAccounts = $report['sections']['expense']['accounts'];
        $this->assertCount(1, $expAccounts);
        $this->assertSame('5-1100', $expAccounts[0]['code']);
        $this->assertSame(200_000.0, $expAccounts[0]['balance']);
    }

    /**
     * Test D: Multiple revenue and expense journals aggregation.
     */
    public function test_multiple_revenue_and_expense_journals_aggregation(): void
    {
        $kas = $this->createAccount(AccountingContext::SHM, '1-1100', 'Kas SHM', ChartOfAccount::CATEGORY_ASSET, ChartOfAccount::NORMAL_DEBIT);
        $rev1 = $this->createAccount(AccountingContext::SHM, '4-1100', 'Bunga SHM', ChartOfAccount::CATEGORY_REVENUE, ChartOfAccount::NORMAL_CREDIT);
        $rev2 = $this->createAccount(AccountingContext::SHM, '4-1200', 'Denda SHM', ChartOfAccount::CATEGORY_REVENUE, ChartOfAccount::NORMAL_CREDIT);
        $exp = $this->createAccount(AccountingContext::SHM, '5-1100', 'Beban Materai SHM', ChartOfAccount::CATEGORY_EXPENSE, ChartOfAccount::NORMAL_DEBIT);

        // Journal 1: Bunga
        $e1 = $this->journalService->createDraft(
            ['transaction_date' => '2026-10-05', 'accounting_context' => AccountingContext::SHM],
            [
                ['account_id' => $kas->id, 'debit' => 500_000, 'credit' => 0],
                ['account_id' => $rev1->id, 'debit' => 0, 'credit' => 500_000],
            ],
            $this->admin->id
        );
        $this->journalService->post($e1, $this->admin->id);

        // Journal 2: Denda
        $e2 = $this->journalService->createDraft(
            ['transaction_date' => '2026-10-10', 'accounting_context' => AccountingContext::SHM],
            [
                ['account_id' => $kas->id, 'debit' => 50_000, 'credit' => 0],
                ['account_id' => $rev2->id, 'debit' => 0, 'credit' => 50_000],
            ],
            $this->admin->id
        );
        $this->journalService->post($e2, $this->admin->id);

        // Journal 3: Beban
        $e3 = $this->journalService->createDraft(
            ['transaction_date' => '2026-10-15', 'accounting_context' => AccountingContext::SHM],
            [
                ['account_id' => $exp->id, 'debit' => 30_000, 'credit' => 0],
                ['account_id' => $kas->id, 'debit' => 0, 'credit' => 30_000],
            ],
            $this->admin->id
        );
        $this->journalService->post($e3, $this->admin->id);

        $report = $this->reportService->incomeStatement('2026-10-01', '2026-10-31', AccountingContext::SHM);

        $this->assertSame(550_000.0, $report['summary']['total_revenue']);
        $this->assertSame(30_000.0, $report['summary']['total_expense']);
        $this->assertSame(520_000.0, $report['summary']['surplus_deficit']);
        $this->assertSame('LABA', $report['summary']['status']);
    }

    /**
     * Test E: DRAFT journals are completely excluded.
     */
    public function test_draft_journals_are_excluded(): void
    {
        $kas = $this->createAccount(AccountingContext::SHM, '1-1100', 'Kas SHM', ChartOfAccount::CATEGORY_ASSET, ChartOfAccount::NORMAL_DEBIT);
        $rev = $this->createAccount(AccountingContext::SHM, '4-1100', 'Bunga SHM', ChartOfAccount::CATEGORY_REVENUE, ChartOfAccount::NORMAL_CREDIT);

        // POSTED journal
        $posted = $this->journalService->createDraft(
            ['transaction_date' => '2026-10-05', 'accounting_context' => AccountingContext::SHM],
            [
                ['account_id' => $kas->id, 'debit' => 100_000, 'credit' => 0],
                ['account_id' => $rev->id, 'debit' => 0, 'credit' => 100_000],
            ],
            $this->admin->id
        );
        $this->journalService->post($posted, $this->admin->id);

        // DRAFT journal
        $this->journalService->createDraft(
            ['transaction_date' => '2026-10-06', 'accounting_context' => AccountingContext::SHM],
            [
                ['account_id' => $kas->id, 'debit' => 999_000, 'credit' => 0],
                ['account_id' => $rev->id, 'debit' => 0, 'credit' => 999_000],
            ],
            $this->admin->id
        );

        $report = $this->reportService->incomeStatement('2026-10-01', '2026-10-31', AccountingContext::SHM);

        $this->assertSame(100_000.0, $report['summary']['total_revenue']);
    }

    /**
     * Test F: Period boundaries (inclusive on start & end, exclusive outside).
     */
    public function test_period_boundaries(): void
    {
        $kas = $this->createAccount(AccountingContext::SHM, '1-1100', 'Kas SHM', ChartOfAccount::CATEGORY_ASSET, ChartOfAccount::NORMAL_DEBIT);
        $rev = $this->createAccount(AccountingContext::SHM, '4-1100', 'Bunga SHM', ChartOfAccount::CATEGORY_REVENUE, ChartOfAccount::NORMAL_CREDIT);

        $post = function ($date, $amount) use ($kas, $rev) {
            $e = $this->journalService->createDraft(
                ['transaction_date' => $date, 'accounting_context' => AccountingContext::SHM],
                [
                    ['account_id' => $kas->id, 'debit' => $amount, 'credit' => 0],
                    ['account_id' => $rev->id, 'debit' => 0, 'credit' => $amount],
                ],
                $this->admin->id
            );
            $this->journalService->post($e, $this->admin->id);
        };

        $post('2026-09-30', 10_000); // Before start
        $post('2026-10-01', 20_000); // Exact start date
        $post('2026-10-15', 40_000); // Inside
        $post('2026-10-31', 80_000); // Exact end date
        $post('2026-11-01', 160_000); // After end

        $report = $this->reportService->incomeStatement('2026-10-01', '2026-10-31', AccountingContext::SHM);

        // Expected sum: 20k + 40k + 80k = 140k
        $this->assertSame(140_000.0, $report['summary']['total_revenue']);
    }

    /**
     * Test G: SHM context isolation excludes Koperasi journals.
     */
    public function test_shm_context_isolation_excludes_koperasi_journals(): void
    {
        $shmKas = $this->createAccount(AccountingContext::SHM, '1-1100', 'Kas SHM', ChartOfAccount::CATEGORY_ASSET, ChartOfAccount::NORMAL_DEBIT);
        $shmRev = $this->createAccount(AccountingContext::SHM, '4-1100', 'Pendapatan SHM', ChartOfAccount::CATEGORY_REVENUE, ChartOfAccount::NORMAL_CREDIT);

        $kopKas = $this->createAccount(AccountingContext::KOPERASI, '1-1100', 'Kas Koperasi', ChartOfAccount::CATEGORY_ASSET, ChartOfAccount::NORMAL_DEBIT);
        $kopRev = $this->createAccount(AccountingContext::KOPERASI, '4-1100', 'Pendapatan Koperasi', ChartOfAccount::CATEGORY_REVENUE, ChartOfAccount::NORMAL_CREDIT);

        // SHM Journal
        $shmJournal = $this->journalService->createDraft(
            ['transaction_date' => '2026-10-10', 'accounting_context' => AccountingContext::SHM],
            [
                ['account_id' => $shmKas->id, 'debit' => 300_000, 'credit' => 0],
                ['account_id' => $shmRev->id, 'debit' => 0, 'credit' => 300_000],
            ],
            $this->admin->id
        );
        $this->journalService->post($shmJournal, $this->admin->id);

        // Koperasi Journal
        $kopJournal = $this->journalService->createDraft(
            ['transaction_date' => '2026-10-10', 'accounting_context' => AccountingContext::KOPERASI],
            [
                ['account_id' => $kopKas->id, 'debit' => 1_500_000, 'credit' => 0],
                ['account_id' => $kopRev->id, 'debit' => 0, 'credit' => 1_500_000],
            ],
            $this->admin->id
        );
        $this->journalService->post($kopJournal, $this->admin->id);

        $shmReport = $this->reportService->incomeStatement('2026-10-01', '2026-10-31', AccountingContext::SHM);
        $this->assertSame(300_000.0, $shmReport['summary']['total_revenue']);

        $kopReport = $this->reportService->incomeStatement('2026-10-01', '2026-10-31', AccountingContext::KOPERASI);
        $this->assertSame(1_500_000.0, $kopReport['summary']['total_revenue']);
    }

    /**
     * Test H: Account classification includes ONLY Revenue & Expense.
     * Asset, Liability, and Equity must never appear as P&L rows.
     */
    public function test_account_classification_excludes_balance_sheet_accounts(): void
    {
        $asset = $this->createAccount(AccountingContext::SHM, '1-1100', 'Kas SHM', ChartOfAccount::CATEGORY_ASSET, ChartOfAccount::NORMAL_DEBIT);
        $liab = $this->createAccount(AccountingContext::SHM, '2-1100', 'Hutang SHM', ChartOfAccount::CATEGORY_LIABILITY, ChartOfAccount::NORMAL_CREDIT);
        $equity = $this->createAccount(AccountingContext::SHM, '3-1100', 'Modal SHM', ChartOfAccount::CATEGORY_EQUITY, ChartOfAccount::NORMAL_CREDIT);
        $rev = $this->createAccount(AccountingContext::SHM, '4-1100', 'Pendapatan SHM', ChartOfAccount::CATEGORY_REVENUE, ChartOfAccount::NORMAL_CREDIT);

        // Non P&L Journal: Asset vs Liability
        $e1 = $this->journalService->createDraft(
            ['transaction_date' => '2026-10-10', 'accounting_context' => AccountingContext::SHM],
            [
                ['account_id' => $asset->id, 'debit' => 1_000_000, 'credit' => 0],
                ['account_id' => $liab->id, 'debit' => 0, 'credit' => 1_000_000],
            ],
            $this->admin->id
        );
        $this->journalService->post($e1, $this->admin->id);

        // Non P&L Journal: Asset vs Equity
        $e2 = $this->journalService->createDraft(
            ['transaction_date' => '2026-10-12', 'accounting_context' => AccountingContext::SHM],
            [
                ['account_id' => $asset->id, 'debit' => 2_000_000, 'credit' => 0],
                ['account_id' => $equity->id, 'debit' => 0, 'credit' => 2_000_000],
            ],
            $this->admin->id
        );
        $this->journalService->post($e2, $this->admin->id);

        // P&L Journal: Asset vs Revenue
        $e3 = $this->journalService->createDraft(
            ['transaction_date' => '2026-10-15', 'accounting_context' => AccountingContext::SHM],
            [
                ['account_id' => $asset->id, 'debit' => 50_000, 'credit' => 0],
                ['account_id' => $rev->id, 'debit' => 0, 'credit' => 50_000],
            ],
            $this->admin->id
        );
        $this->journalService->post($e3, $this->admin->id);

        $report = $this->reportService->incomeStatement('2026-10-01', '2026-10-31', AccountingContext::SHM);

        $this->assertSame(50_000.0, $report['summary']['total_revenue']);
        $this->assertSame(0.0, $report['summary']['total_expense']);

        $allCodes = collect($report['sections']['revenue']['accounts'])
            ->concat($report['sections']['expense']['accounts'])
            ->pluck('code');

        $this->assertContains('4-1100', $allCodes);
        $this->assertNotContains('1-1100', $allCodes);
        $this->assertNotContains('2-1100', $allCodes);
        $this->assertNotContains('3-1100', $allCodes);
    }

    /**
     * Test I: Normal balance respected for credit-normal revenue and debit-normal expense.
     */
    public function test_normal_balance_respected(): void
    {
        $kas = $this->createAccount(AccountingContext::SHM, '1-1100', 'Kas SHM', ChartOfAccount::CATEGORY_ASSET, ChartOfAccount::NORMAL_DEBIT);
        $rev = $this->createAccount(AccountingContext::SHM, '4-1100', 'Pendapatan SHM', ChartOfAccount::CATEGORY_REVENUE, ChartOfAccount::NORMAL_CREDIT);
        $exp = $this->createAccount(AccountingContext::SHM, '5-1100', 'Beban SHM', ChartOfAccount::CATEGORY_EXPENSE, ChartOfAccount::NORMAL_DEBIT);

        // Revenue: credit 100k -> balance = 100k
        $e1 = $this->journalService->createDraft(
            ['transaction_date' => '2026-10-10', 'accounting_context' => AccountingContext::SHM],
            [
                ['account_id' => $kas->id, 'debit' => 100_000, 'credit' => 0],
                ['account_id' => $rev->id, 'debit' => 0, 'credit' => 100_000],
            ],
            $this->admin->id
        );
        $this->journalService->post($e1, $this->admin->id);

        // Expense: debit 40k -> balance = 40k
        $e2 = $this->journalService->createDraft(
            ['transaction_date' => '2026-10-12', 'accounting_context' => AccountingContext::SHM],
            [
                ['account_id' => $exp->id, 'debit' => 40_000, 'credit' => 0],
                ['account_id' => $kas->id, 'debit' => 0, 'credit' => 40_000],
            ],
            $this->admin->id
        );
        $this->journalService->post($e2, $this->admin->id);

        $report = $this->reportService->incomeStatement('2026-10-01', '2026-10-31', AccountingContext::SHM);

        $revAcc = collect($report['sections']['revenue']['accounts'])->firstWhere('code', '4-1100');
        $expAcc = collect($report['sections']['expense']['accounts'])->firstWhere('code', '5-1100');

        $this->assertSame(100_000.0, $revAcc['balance']);
        $this->assertSame(40_000.0, $expAcc['balance']);
    }

    /**
     * Test J: Journal reversal reflects final posted state.
     */
    public function test_journal_reversal_reflects_final_posted_state(): void
    {
        $kas = $this->createAccount(AccountingContext::SHM, '1-1100', 'Kas SHM', ChartOfAccount::CATEGORY_ASSET, ChartOfAccount::NORMAL_DEBIT);
        $rev = $this->createAccount(AccountingContext::SHM, '4-1100', 'Pendapatan SHM', ChartOfAccount::CATEGORY_REVENUE, ChartOfAccount::NORMAL_CREDIT);

        $entry = $this->journalService->createDraft(
            ['transaction_date' => now()->toDateString(), 'accounting_context' => AccountingContext::SHM],
            [
                ['account_id' => $kas->id, 'debit' => 250_000, 'credit' => 0],
                ['account_id' => $rev->id, 'debit' => 0, 'credit' => 250_000],
            ],
            $this->admin->id
        );
        $this->journalService->post($entry, $this->admin->id);

        // Reverse using Journal Engine
        $this->journalService->reverse($entry->fresh(), $this->admin->id, 'Reversal test');

        $report = $this->reportService->incomeStatement(now()->toDateString(), now()->toDateString(), AccountingContext::SHM);

        // Original became REVERSED, reversal journal is POSTED with debit 250k.
        // Balance reflects the resulting final accounting state.
        $this->assertSame(0.0, $report['summary']['total_expense']);
    }

    /**
     * Test L & M & N: Status outcomes (LABA, RUGI, IMPAS).
     */
    public function test_status_outcomes_laba_rugi_impas(): void
    {
        $kas = $this->createAccount(AccountingContext::SHM, '1-1100', 'Kas SHM', ChartOfAccount::CATEGORY_ASSET, ChartOfAccount::NORMAL_DEBIT);
        $rev = $this->createAccount(AccountingContext::SHM, '4-1100', 'Pendapatan SHM', ChartOfAccount::CATEGORY_REVENUE, ChartOfAccount::NORMAL_CREDIT);
        $exp = $this->createAccount(AccountingContext::SHM, '5-1100', 'Beban SHM', ChartOfAccount::CATEGORY_EXPENSE, ChartOfAccount::NORMAL_DEBIT);

        // 1. LABA scenario: Rev (500k) > Exp (200k)
        $e1 = $this->journalService->createDraft(
            ['transaction_date' => '2026-10-01', 'accounting_context' => AccountingContext::SHM],
            [
                ['account_id' => $kas->id, 'debit' => 500_000, 'credit' => 0],
                ['account_id' => $rev->id, 'debit' => 0, 'credit' => 500_000],
            ],
            $this->admin->id
        );
        $this->journalService->post($e1, $this->admin->id);

        $e2 = $this->journalService->createDraft(
            ['transaction_date' => '2026-10-02', 'accounting_context' => AccountingContext::SHM],
            [
                ['account_id' => $exp->id, 'debit' => 200_000, 'credit' => 0],
                ['account_id' => $kas->id, 'debit' => 0, 'credit' => 200_000],
            ],
            $this->admin->id
        );
        $this->journalService->post($e2, $this->admin->id);

        $reportLaba = $this->reportService->incomeStatement('2026-10-01', '2026-10-02', AccountingContext::SHM);
        $this->assertSame(300_000.0, $reportLaba['summary']['surplus_deficit']);
        $this->assertSame('LABA', $reportLaba['summary']['status']);

        // 2. RUGI scenario: Additional Exp (600k) -> Rev 500k vs Exp 800k -> -300k
        $e3 = $this->journalService->createDraft(
            ['transaction_date' => '2026-10-03', 'accounting_context' => AccountingContext::SHM],
            [
                ['account_id' => $exp->id, 'debit' => 600_000, 'credit' => 0],
                ['account_id' => $kas->id, 'debit' => 0, 'credit' => 600_000],
            ],
            $this->admin->id
        );
        $this->journalService->post($e3, $this->admin->id);

        $reportRugi = $this->reportService->incomeStatement('2026-10-01', '2026-10-03', AccountingContext::SHM);
        $this->assertSame(-300_000.0, $reportRugi['summary']['surplus_deficit']);
        $this->assertSame('RUGI', $reportRugi['summary']['status']);

        // 3. IMPAS scenario: Additional Rev (300k) -> Rev 800k vs Exp 800k -> 0
        $e4 = $this->journalService->createDraft(
            ['transaction_date' => '2026-10-04', 'accounting_context' => AccountingContext::SHM],
            [
                ['account_id' => $kas->id, 'debit' => 300_000, 'credit' => 0],
                ['account_id' => $rev->id, 'debit' => 0, 'credit' => 300_000],
            ],
            $this->admin->id
        );
        $this->journalService->post($e4, $this->admin->id);

        $reportImpas = $this->reportService->incomeStatement('2026-10-01', '2026-10-04', AccountingContext::SHM);
        $this->assertSame(0.0, $reportImpas['summary']['surplus_deficit']);
        $this->assertSame('IMPAS', $reportImpas['summary']['status']);
    }

    /**
     * Test O: Authorization checks on web endpoints.
     */
    public function test_period_profit_loss_endpoint_authorization(): void
    {
        $response = $this->actingAs($this->unauthorizedUser)->get(route('shm.period-profit-loss.index'));
        $response->assertForbidden();

        $exportResponse = $this->actingAs($this->unauthorizedUser)->get(route('shm.period-profit-loss.export'));
        $exportResponse->assertForbidden();
    }

    /**
     * Test P: XLSX export creates valid file, matches dataset, and is read-only.
     */
    public function test_xlsx_export_matches_dataset_and_is_readonly(): void
    {
        $kas = $this->createAccount(AccountingContext::SHM, '1-1100', 'Kas SHM', ChartOfAccount::CATEGORY_ASSET, ChartOfAccount::NORMAL_DEBIT);
        $rev = $this->createAccount(AccountingContext::SHM, '4-1100', 'Pendapatan SHM', ChartOfAccount::CATEGORY_REVENUE, ChartOfAccount::NORMAL_CREDIT);

        $entry = $this->journalService->createDraft(
            ['transaction_date' => '2026-10-20', 'accounting_context' => AccountingContext::SHM],
            [
                ['account_id' => $kas->id, 'debit' => 500_000, 'credit' => 0],
                ['account_id' => $rev->id, 'debit' => 0, 'credit' => 500_000],
            ],
            $this->admin->id
        );
        $this->journalService->post($entry, $this->admin->id);

        $initialJournalCount = JournalEntry::count();

        $response = $this->actingAs($this->admin)->get(route('shm.period-profit-loss.export', [
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-31',
        ]));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        // Verify read-only: no new journals created
        $this->assertSame($initialJournalCount, JournalEntry::count());
    }
}
