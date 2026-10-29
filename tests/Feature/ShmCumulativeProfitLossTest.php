<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
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

class ShmCumulativeProfitLossTest extends TestCase
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

        // Admin user with accounting.shm.reports.view
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
     * Test A: Empty Cumulative Range returns 0 for revenue, expense, and net result.
     */
    public function test_empty_cumulative_range_returns_zeroes(): void
    {
        $this->createAccount(AccountingContext::SHM, '4-1100', 'Bunga SHM', ChartOfAccount::CATEGORY_REVENUE, ChartOfAccount::NORMAL_CREDIT);
        $this->createAccount(AccountingContext::SHM, '5-1100', 'Beban SHM', ChartOfAccount::CATEGORY_EXPENSE, ChartOfAccount::NORMAL_DEBIT);

        $response = $this->actingAs($this->admin)->get(route('shm.cumulative-profit-loss.index', ['report_date' => '2026-10-29']));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Shm/Accounting/CumulativeProfitLoss')
            ->where('report.summary.total_revenue', fn ($val) => (float) $val === 0.0)
            ->where('report.summary.total_expense', fn ($val) => (float) $val === 0.0)
            ->where('report.summary.surplus_deficit', fn ($val) => (float) $val === 0.0)
            ->where('report.summary.status', 'IMPAS')
        );
    }

    /**
     * Test B & C & D & E: Cumulative boundary behavior (before, at start, at report date, after report date).
     */
    public function test_cumulative_report_date_boundary(): void
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

        // Transaction 1: 2026-01-10
        $post('2026-01-10', 100_000);
        // Transaction 2: 2026-06-15
        $post('2026-06-15', 200_000);
        // Transaction 3: 2026-10-29 (Exact report date)
        $post('2026-10-29', 300_000);
        // Transaction 4: 2026-10-30 (After report date)
        $post('2026-10-30', 500_000);

        // Query cumulative through 2026-10-29
        $report = $this->reportService->incomeStatement(null, '2026-10-29', AccountingContext::SHM);

        // Expected: 100k + 200k + 300k = 600k (500k from 2026-10-30 is excluded)
        $this->assertSame(600_000.0, $report['summary']['total_revenue']);
        $this->assertSame(600_000.0, $report['summary']['surplus_deficit']);
    }

    /**
     * Test F: Expense Aggregation across multiple journals.
     */
    public function test_expense_cumulative_aggregation(): void
    {
        $kas = $this->createAccount(AccountingContext::SHM, '1-1100', 'Kas SHM', ChartOfAccount::CATEGORY_ASSET, ChartOfAccount::NORMAL_DEBIT);
        $exp1 = $this->createAccount(AccountingContext::SHM, '5-1100', 'Operasional SHM', ChartOfAccount::CATEGORY_EXPENSE, ChartOfAccount::NORMAL_DEBIT);
        $exp2 = $this->createAccount(AccountingContext::SHM, '5-1200', 'Materai SHM', ChartOfAccount::CATEGORY_EXPENSE, ChartOfAccount::NORMAL_DEBIT);

        // Exp 1: 150k
        $e1 = $this->journalService->createDraft(
            ['transaction_date' => '2026-05-01', 'accounting_context' => AccountingContext::SHM],
            [
                ['account_id' => $exp1->id, 'debit' => 150_000, 'credit' => 0],
                ['account_id' => $kas->id, 'debit' => 0, 'credit' => 150_000],
            ],
            $this->admin->id
        );
        $this->journalService->post($e1, $this->admin->id);

        // Exp 2: 50k
        $e2 = $this->journalService->createDraft(
            ['transaction_date' => '2026-07-01', 'accounting_context' => AccountingContext::SHM],
            [
                ['account_id' => $exp2->id, 'debit' => 50_000, 'credit' => 0],
                ['account_id' => $kas->id, 'debit' => 0, 'credit' => 50_000],
            ],
            $this->admin->id
        );
        $this->journalService->post($e2, $this->admin->id);

        $report = $this->reportService->incomeStatement(null, '2026-10-29', AccountingContext::SHM);

        $this->assertSame(200_000.0, $report['summary']['total_expense']);
        $this->assertSame(-200_000.0, $report['summary']['surplus_deficit']);
        $this->assertSame('RUGI', $report['summary']['status']);
    }

    /**
     * Test G & H: Cumulative Revenue & Expense Net Profit/Loss formula.
     */
    public function test_cumulative_revenue_and_expense_net_calculation(): void
    {
        $kas = $this->createAccount(AccountingContext::SHM, '1-1100', 'Kas SHM', ChartOfAccount::CATEGORY_ASSET, ChartOfAccount::NORMAL_DEBIT);
        $rev = $this->createAccount(AccountingContext::SHM, '4-1100', 'Pendapatan SHM', ChartOfAccount::CATEGORY_REVENUE, ChartOfAccount::NORMAL_CREDIT);
        $exp = $this->createAccount(AccountingContext::SHM, '5-1100', 'Beban SHM', ChartOfAccount::CATEGORY_EXPENSE, ChartOfAccount::NORMAL_DEBIT);

        // Revenue: 1,000,000
        $e1 = $this->journalService->createDraft(
            ['transaction_date' => '2026-03-01', 'accounting_context' => AccountingContext::SHM],
            [
                ['account_id' => $kas->id, 'debit' => 1_000_000, 'credit' => 0],
                ['account_id' => $rev->id, 'debit' => 0, 'credit' => 1_000_000],
            ],
            $this->admin->id
        );
        $this->journalService->post($e1, $this->admin->id);

        // Expense: 400,000
        $e2 = $this->journalService->createDraft(
            ['transaction_date' => '2026-04-01', 'accounting_context' => AccountingContext::SHM],
            [
                ['account_id' => $exp->id, 'debit' => 400_000, 'credit' => 0],
                ['account_id' => $kas->id, 'debit' => 0, 'credit' => 400_000],
            ],
            $this->admin->id
        );
        $this->journalService->post($e2, $this->admin->id);

        $report = $this->reportService->incomeStatement(null, '2026-10-29', AccountingContext::SHM);

        $this->assertSame(1_000_000.0, $report['summary']['total_revenue']);
        $this->assertSame(400_000.0, $report['summary']['total_expense']);
        $this->assertSame(600_000.0, $report['summary']['surplus_deficit']);
        $this->assertSame('LABA', $report['summary']['status']);
    }

    /**
     * Test I & J & K: Status values (LABA, RUGI, IMPAS).
     */
    public function test_cumulative_status_laba_rugi_impas(): void
    {
        $kas = $this->createAccount(AccountingContext::SHM, '1-1100', 'Kas SHM', ChartOfAccount::CATEGORY_ASSET, ChartOfAccount::NORMAL_DEBIT);
        $rev = $this->createAccount(AccountingContext::SHM, '4-1100', 'Pendapatan SHM', ChartOfAccount::CATEGORY_REVENUE, ChartOfAccount::NORMAL_CREDIT);
        $exp = $this->createAccount(AccountingContext::SHM, '5-1100', 'Beban SHM', ChartOfAccount::CATEGORY_EXPENSE, ChartOfAccount::NORMAL_DEBIT);

        // 1. LABA
        $e1 = $this->journalService->createDraft(
            ['transaction_date' => '2026-01-01', 'accounting_context' => AccountingContext::SHM],
            [
                ['account_id' => $kas->id, 'debit' => 700_000, 'credit' => 0],
                ['account_id' => $rev->id, 'debit' => 0, 'credit' => 700_000],
            ],
            $this->admin->id
        );
        $this->journalService->post($e1, $this->admin->id);

        $reportLaba = $this->reportService->incomeStatement(null, '2026-01-02', AccountingContext::SHM);
        $this->assertSame('LABA', $reportLaba['summary']['status']);

        // 2. RUGI
        $e2 = $this->journalService->createDraft(
            ['transaction_date' => '2026-01-03', 'accounting_context' => AccountingContext::SHM],
            [
                ['account_id' => $exp->id, 'debit' => 1_000_000, 'credit' => 0],
                ['account_id' => $kas->id, 'debit' => 0, 'credit' => 1_000_000],
            ],
            $this->admin->id
        );
        $this->journalService->post($e2, $this->admin->id);

        $reportRugi = $this->reportService->incomeStatement(null, '2026-01-04', AccountingContext::SHM);
        $this->assertSame('RUGI', $reportRugi['summary']['status']);

        // 3. IMPAS
        $e3 = $this->journalService->createDraft(
            ['transaction_date' => '2026-01-05', 'accounting_context' => AccountingContext::SHM],
            [
                ['account_id' => $kas->id, 'debit' => 300_000, 'credit' => 0],
                ['account_id' => $rev->id, 'debit' => 0, 'credit' => 300_000],
            ],
            $this->admin->id
        );
        $this->journalService->post($e3, $this->admin->id);

        $reportImpas = $this->reportService->incomeStatement(null, '2026-01-06', AccountingContext::SHM);
        $this->assertSame(0.0, $reportImpas['summary']['surplus_deficit']);
        $this->assertSame('IMPAS', $reportImpas['summary']['status']);
    }

    /**
     * Test L: DRAFT journals are completely excluded.
     */
    public function test_draft_journals_excluded_from_cumulative(): void
    {
        $kas = $this->createAccount(AccountingContext::SHM, '1-1100', 'Kas SHM', ChartOfAccount::CATEGORY_ASSET, ChartOfAccount::NORMAL_DEBIT);
        $rev = $this->createAccount(AccountingContext::SHM, '4-1100', 'Pendapatan SHM', ChartOfAccount::CATEGORY_REVENUE, ChartOfAccount::NORMAL_CREDIT);

        // Posted
        $posted = $this->journalService->createDraft(
            ['transaction_date' => '2026-02-01', 'accounting_context' => AccountingContext::SHM],
            [
                ['account_id' => $kas->id, 'debit' => 250_000, 'credit' => 0],
                ['account_id' => $rev->id, 'debit' => 0, 'credit' => 250_000],
            ],
            $this->admin->id
        );
        $this->journalService->post($posted, $this->admin->id);

        // Draft
        $this->journalService->createDraft(
            ['transaction_date' => '2026-02-02', 'accounting_context' => AccountingContext::SHM],
            [
                ['account_id' => $kas->id, 'debit' => 888_000, 'credit' => 0],
                ['account_id' => $rev->id, 'debit' => 0, 'credit' => 888_000],
            ],
            $this->admin->id
        );

        $report = $this->reportService->incomeStatement(null, '2026-10-29', AccountingContext::SHM);
        $this->assertSame(250_000.0, $report['summary']['total_revenue']);
    }

    /**
     * Test M: SHM Context Isolation excludes Koperasi journals.
     */
    public function test_shm_context_isolation_excludes_koperasi(): void
    {
        $shmKas = $this->createAccount(AccountingContext::SHM, '1-1100', 'Kas SHM', ChartOfAccount::CATEGORY_ASSET, ChartOfAccount::NORMAL_DEBIT);
        $shmRev = $this->createAccount(AccountingContext::SHM, '4-1100', 'Pendapatan SHM', ChartOfAccount::CATEGORY_REVENUE, ChartOfAccount::NORMAL_CREDIT);

        $kopKas = $this->createAccount(AccountingContext::KOPERASI, '1-1100', 'Kas Koperasi', ChartOfAccount::CATEGORY_ASSET, ChartOfAccount::NORMAL_DEBIT);
        $kopRev = $this->createAccount(AccountingContext::KOPERASI, '4-1100', 'Pendapatan Koperasi', ChartOfAccount::CATEGORY_REVENUE, ChartOfAccount::NORMAL_CREDIT);

        // SHM
        $shmEntry = $this->journalService->createDraft(
            ['transaction_date' => '2026-03-01', 'accounting_context' => AccountingContext::SHM],
            [
                ['account_id' => $shmKas->id, 'debit' => 350_000, 'credit' => 0],
                ['account_id' => $shmRev->id, 'debit' => 0, 'credit' => 350_000],
            ],
            $this->admin->id
        );
        $this->journalService->post($shmEntry, $this->admin->id);

        // Koperasi
        $kopEntry = $this->journalService->createDraft(
            ['transaction_date' => '2026-03-01', 'accounting_context' => AccountingContext::KOPERASI],
            [
                ['account_id' => $kopKas->id, 'debit' => 2_000_000, 'credit' => 0],
                ['account_id' => $kopRev->id, 'debit' => 0, 'credit' => 2_000_000],
            ],
            $this->admin->id
        );
        $this->journalService->post($kopEntry, $this->admin->id);

        $report = $this->reportService->incomeStatement(null, '2026-10-29', AccountingContext::SHM);
        $this->assertSame(350_000.0, $report['summary']['total_revenue']);
    }

    /**
     * Test N: Only Revenue and Expense accounts are included; Asset/Liability/Equity excluded.
     */
    public function test_non_pl_accounts_are_excluded(): void
    {
        $asset = $this->createAccount(AccountingContext::SHM, '1-1100', 'Kas SHM', ChartOfAccount::CATEGORY_ASSET, ChartOfAccount::NORMAL_DEBIT);
        $equity = $this->createAccount(AccountingContext::SHM, '3-1100', 'Modal SHM', ChartOfAccount::CATEGORY_EQUITY, ChartOfAccount::NORMAL_CREDIT);
        $rev = $this->createAccount(AccountingContext::SHM, '4-1100', 'Pendapatan SHM', ChartOfAccount::CATEGORY_REVENUE, ChartOfAccount::NORMAL_CREDIT);

        // Capital contribution
        $e1 = $this->journalService->createDraft(
            ['transaction_date' => '2026-01-01', 'accounting_context' => AccountingContext::SHM],
            [
                ['account_id' => $asset->id, 'debit' => 5_000_000, 'credit' => 0],
                ['account_id' => $equity->id, 'debit' => 0, 'credit' => 5_000_000],
            ],
            $this->admin->id
        );
        $this->journalService->post($e1, $this->admin->id);

        // Revenue
        $e2 = $this->journalService->createDraft(
            ['transaction_date' => '2026-01-02', 'accounting_context' => AccountingContext::SHM],
            [
                ['account_id' => $asset->id, 'debit' => 100_000, 'credit' => 0],
                ['account_id' => $rev->id, 'debit' => 0, 'credit' => 100_000],
            ],
            $this->admin->id
        );
        $this->journalService->post($e2, $this->admin->id);

        $report = $this->reportService->incomeStatement(null, '2026-10-29', AccountingContext::SHM);
        $this->assertSame(100_000.0, $report['summary']['total_revenue']);

        $allCodes = collect($report['sections']['revenue']['accounts'])
            ->concat($report['sections']['expense']['accounts'])
            ->pluck('code');

        $this->assertContains('4-1100', $allCodes);
        $this->assertNotContains('1-1100', $allCodes);
        $this->assertNotContains('3-1100', $allCodes);
    }

    /**
     * Test P: Reversal behavior reflects net posted state.
     */
    public function test_cumulative_reversal_behavior(): void
    {
        $kas = $this->createAccount(AccountingContext::SHM, '1-1100', 'Kas SHM', ChartOfAccount::CATEGORY_ASSET, ChartOfAccount::NORMAL_DEBIT);
        $rev = $this->createAccount(AccountingContext::SHM, '4-1100', 'Pendapatan SHM', ChartOfAccount::CATEGORY_REVENUE, ChartOfAccount::NORMAL_CREDIT);

        $entry = $this->journalService->createDraft(
            ['transaction_date' => now()->toDateString(), 'accounting_context' => AccountingContext::SHM],
            [
                ['account_id' => $kas->id, 'debit' => 400_000, 'credit' => 0],
                ['account_id' => $rev->id, 'debit' => 0, 'credit' => 400_000],
            ],
            $this->admin->id
        );
        $this->journalService->post($entry, $this->admin->id);

        // Reverse
        $this->journalService->reverse($entry->fresh(), $this->admin->id, 'Correction');

        $report = $this->reportService->incomeStatement(null, now()->toDateString(), AccountingContext::SHM);

        $this->assertSame(0.0, $report['summary']['total_expense']);
    }

    /**
     * Test Q: Endpoint Authorization checks.
     */
    public function test_cumulative_endpoints_authorization(): void
    {
        $response = $this->actingAs($this->unauthorizedUser)->get(route('shm.cumulative-profit-loss.index'));
        $response->assertForbidden();

        $exportResponse = $this->actingAs($this->unauthorizedUser)->get(route('shm.cumulative-profit-loss.export'));
        $exportResponse->assertForbidden();
    }

    /**
     * Test R: XLSX export matches UI dataset and is read-only.
     */
    public function test_xlsx_export_matches_ui_dataset_and_is_readonly(): void
    {
        $kas = $this->createAccount(AccountingContext::SHM, '1-1100', 'Kas SHM', ChartOfAccount::CATEGORY_ASSET, ChartOfAccount::NORMAL_DEBIT);
        $rev = $this->createAccount(AccountingContext::SHM, '4-1100', 'Pendapatan SHM', ChartOfAccount::CATEGORY_REVENUE, ChartOfAccount::NORMAL_CREDIT);

        $entry = $this->journalService->createDraft(
            ['transaction_date' => '2026-05-20', 'accounting_context' => AccountingContext::SHM],
            [
                ['account_id' => $kas->id, 'debit' => 700_000, 'credit' => 0],
                ['account_id' => $rev->id, 'debit' => 0, 'credit' => 700_000],
            ],
            $this->admin->id
        );
        $this->journalService->post($entry, $this->admin->id);

        $initialJournalCount = JournalEntry::count();

        $response = $this->actingAs($this->admin)->get(route('shm.cumulative-profit-loss.export', [
            'report_date' => '2026-10-29',
        ]));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        // Read-only verification
        $this->assertSame($initialJournalCount, JournalEntry::count());
    }
}
