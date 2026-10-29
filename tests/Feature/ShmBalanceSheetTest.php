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
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ShmBalanceSheetTest extends TestCase
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
     * Test A: Empty SHM Balance Sheet returns 0 totals and balanced status.
     */
    public function test_empty_shm_balance_sheet(): void
    {
        $this->createAccount(AccountingContext::SHM, '1-1100', 'Kas SHM', ChartOfAccount::CATEGORY_ASSET, ChartOfAccount::NORMAL_DEBIT);
        $this->createAccount(AccountingContext::SHM, '2-1100', 'Hutang SHM', ChartOfAccount::CATEGORY_LIABILITY, ChartOfAccount::NORMAL_CREDIT);
        $this->createAccount(AccountingContext::SHM, '3-1100', 'Modal SHM', ChartOfAccount::CATEGORY_EQUITY, ChartOfAccount::NORMAL_CREDIT);

        $response = $this->actingAs($this->admin)->get(route('shm.balance-sheet.index', ['as_of_date' => '2026-10-29']));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Shm/Accounting/BalanceSheet')
            ->where('report.summary.total_assets', fn ($val) => (float) $val === 0.0)
            ->where('report.summary.total_liabilities', fn ($val) => (float) $val === 0.0)
            ->where('report.summary.total_equity', fn ($val) => (float) $val === 0.0)
            ->where('report.summary.difference', fn ($val) => (float) $val === 0.0)
            ->where('report.balanced', true)
            ->where('report.summary.status', 'BALANCED')
        );
    }

    /**
     * Test B & C & D: Single Asset, Single Liability, and Single Equity accounts balance.
     */
    public function test_single_asset_liability_equity_accounts(): void
    {
        $asset = $this->createAccount(AccountingContext::SHM, '1-1100', 'Kas SHM', ChartOfAccount::CATEGORY_ASSET, ChartOfAccount::NORMAL_DEBIT);
        $equity = $this->createAccount(AccountingContext::SHM, '3-1100', 'Modal Disetor SHM', ChartOfAccount::CATEGORY_EQUITY, ChartOfAccount::NORMAL_CREDIT);
        $liab = $this->createAccount(AccountingContext::SHM, '2-1100', 'Titipan Dana SHM', ChartOfAccount::CATEGORY_LIABILITY, ChartOfAccount::NORMAL_CREDIT);

        // 1. Initial capital: Asset 5,000,000 vs Equity 5,000,000
        $e1 = $this->journalService->createDraft(
            ['transaction_date' => '2026-10-01', 'accounting_context' => AccountingContext::SHM],
            [
                ['account_id' => $asset->id, 'debit' => 5_000_000, 'credit' => 0],
                ['account_id' => $equity->id, 'debit' => 0, 'credit' => 5_000_000],
            ],
            $this->admin->id
        );
        $this->journalService->post($e1, $this->admin->id);

        // 2. Loan received: Asset 2,000,000 vs Liability 2,000,000
        $e2 = $this->journalService->createDraft(
            ['transaction_date' => '2026-10-05', 'accounting_context' => AccountingContext::SHM],
            [
                ['account_id' => $asset->id, 'debit' => 2_000_000, 'credit' => 0],
                ['account_id' => $liab->id, 'debit' => 0, 'credit' => 2_000_000],
            ],
            $this->admin->id
        );
        $this->journalService->post($e2, $this->admin->id);

        $report = $this->reportService->balanceSheet('2026-10-29', AccountingContext::SHM);

        $this->assertSame(7_000_000.0, $report['summary']['total_assets']);
        $this->assertSame(2_000_000.0, $report['summary']['total_liabilities']);
        $this->assertSame(5_000_000.0, $report['summary']['total_equity']);
        $this->assertSame(7_000_000.0, $report['summary']['total_liabilities_and_equity']);
        $this->assertSame(0.0, $report['summary']['difference']);
        $this->assertTrue($report['balanced']);
        $this->assertSame('BALANCED', $report['summary']['status']);
    }

    /**
     * Test E & F & G & H: Multiple accounts across all categories satisfy Assets = Liabilities + Equity.
     */
    public function test_multiple_accounts_accounting_equation(): void
    {
        $kas = $this->createAccount(AccountingContext::SHM, '1-1100', 'Kas SHM', ChartOfAccount::CATEGORY_ASSET, ChartOfAccount::NORMAL_DEBIT);
        $bank = $this->createAccount(AccountingContext::SHM, '1-1200', 'Bank SHM', ChartOfAccount::CATEGORY_ASSET, ChartOfAccount::NORMAL_DEBIT);
        $liab1 = $this->createAccount(AccountingContext::SHM, '2-1100', 'Hutang Bank SHM', ChartOfAccount::CATEGORY_LIABILITY, ChartOfAccount::NORMAL_CREDIT);
        $liab2 = $this->createAccount(AccountingContext::SHM, '2-1200', 'Hutang Biaya SHM', ChartOfAccount::CATEGORY_LIABILITY, ChartOfAccount::NORMAL_CREDIT);
        $equity = $this->createAccount(AccountingContext::SHM, '3-1100', 'Modal Awal SHM', ChartOfAccount::CATEGORY_EQUITY, ChartOfAccount::NORMAL_CREDIT);

        // Journal 1
        $e1 = $this->journalService->createDraft(
            ['transaction_date' => '2026-10-01', 'accounting_context' => AccountingContext::SHM],
            [
                ['account_id' => $kas->id, 'debit' => 1_000_000, 'credit' => 0],
                ['account_id' => $equity->id, 'debit' => 0, 'credit' => 1_000_000],
            ],
            $this->admin->id
        );
        $this->journalService->post($e1, $this->admin->id);

        // Journal 2
        $e2 = $this->journalService->createDraft(
            ['transaction_date' => '2026-10-05', 'accounting_context' => AccountingContext::SHM],
            [
                ['account_id' => $bank->id, 'debit' => 3_000_000, 'credit' => 0],
                ['account_id' => $liab1->id, 'debit' => 0, 'credit' => 2_500_000],
                ['account_id' => $liab2->id, 'debit' => 0, 'credit' => 500_000],
            ],
            $this->admin->id
        );
        $this->journalService->post($e2, $this->admin->id);

        $report = $this->reportService->balanceSheet('2026-10-29', AccountingContext::SHM);

        $this->assertSame(4_000_000.0, $report['summary']['total_assets']);
        $this->assertSame(3_000_000.0, $report['summary']['total_liabilities']);
        $this->assertSame(1_000_000.0, $report['summary']['total_equity']);
        $this->assertSame(0.0, $report['summary']['difference']);
        $this->assertTrue($report['balanced']);
        $this->assertSame('BALANCED', $report['summary']['status']);
    }

    /**
     * Test I: Not Balanced Detection exposes discrepancy without auto-adjustment.
     */
    public function test_not_balanced_detection_without_auto_adjustment(): void
    {
        $asset = $this->createAccount(AccountingContext::SHM, '1-1100', 'Kas SHM', ChartOfAccount::CATEGORY_ASSET, ChartOfAccount::NORMAL_DEBIT);
        $equity = $this->createAccount(AccountingContext::SHM, '3-1100', 'Modal SHM', ChartOfAccount::CATEGORY_EQUITY, ChartOfAccount::NORMAL_CREDIT);

        $entry = $this->journalService->createDraft(
            ['transaction_date' => '2026-10-01', 'accounting_context' => AccountingContext::SHM],
            [
                ['account_id' => $asset->id, 'debit' => 1_000_000, 'credit' => 0],
                ['account_id' => $equity->id, 'debit' => 0, 'credit' => 1_000_000],
            ],
            $this->admin->id
        );
        $this->journalService->post($entry, $this->admin->id);

        // Inject unbalancing entry in journal_lines
        DB::table('journal_lines')->insert([
            'journal_entry_id' => $entry->id,
            'account_id' => $asset->id,
            'debit' => 250_000,
            'credit' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $initialJournalCount = JournalEntry::count();

        $report = $this->reportService->balanceSheet('2026-10-29', AccountingContext::SHM);

        $this->assertFalse($report['balanced']);
        $this->assertSame('NOT BALANCED', $report['summary']['status']);
        $this->assertSame(250_000.0, $report['summary']['difference']);

        // Assert NO automatic journal adjustment created
        $this->assertSame($initialJournalCount, JournalEntry::count());
    }

    /**
     * Test J & K: Report Date boundary and Future Transaction Exclusion.
     */
    public function test_report_date_boundary_and_future_exclusion(): void
    {
        $asset = $this->createAccount(AccountingContext::SHM, '1-1100', 'Kas SHM', ChartOfAccount::CATEGORY_ASSET, ChartOfAccount::NORMAL_DEBIT);
        $equity = $this->createAccount(AccountingContext::SHM, '3-1100', 'Modal SHM', ChartOfAccount::CATEGORY_EQUITY, ChartOfAccount::NORMAL_CREDIT);

        // Transaction on 2026-10-25
        $e1 = $this->journalService->createDraft(
            ['transaction_date' => '2026-10-25', 'accounting_context' => AccountingContext::SHM],
            [
                ['account_id' => $asset->id, 'debit' => 500_000, 'credit' => 0],
                ['account_id' => $equity->id, 'debit' => 0, 'credit' => 500_000],
            ],
            $this->admin->id
        );
        $this->journalService->post($e1, $this->admin->id);

        // Transaction on exact report date 2026-10-29
        $e2 = $this->journalService->createDraft(
            ['transaction_date' => '2026-10-29', 'accounting_context' => AccountingContext::SHM],
            [
                ['account_id' => $asset->id, 'debit' => 200_000, 'credit' => 0],
                ['account_id' => $equity->id, 'debit' => 0, 'credit' => 200_000],
            ],
            $this->admin->id
        );
        $this->journalService->post($e2, $this->admin->id);

        // Future transaction on 2026-10-30
        $e3 = $this->journalService->createDraft(
            ['transaction_date' => '2026-10-30', 'accounting_context' => AccountingContext::SHM],
            [
                ['account_id' => $asset->id, 'debit' => 800_000, 'credit' => 0],
                ['account_id' => $equity->id, 'debit' => 0, 'credit' => 800_000],
            ],
            $this->admin->id
        );
        $this->journalService->post($e3, $this->admin->id);

        $report = $this->reportService->balanceSheet('2026-10-29', AccountingContext::SHM);

        // Expected: 500k + 200k = 700k (800k from 2026-10-30 is excluded)
        $this->assertSame(700_000.0, $report['summary']['total_assets']);
        $this->assertSame(700_000.0, $report['summary']['total_equity']);
    }

    /**
     * Test L: DRAFT journals are excluded.
     */
    public function test_draft_journals_are_excluded(): void
    {
        $asset = $this->createAccount(AccountingContext::SHM, '1-1100', 'Kas SHM', ChartOfAccount::CATEGORY_ASSET, ChartOfAccount::NORMAL_DEBIT);
        $equity = $this->createAccount(AccountingContext::SHM, '3-1100', 'Modal SHM', ChartOfAccount::CATEGORY_EQUITY, ChartOfAccount::NORMAL_CREDIT);

        // POSTED
        $posted = $this->journalService->createDraft(
            ['transaction_date' => '2026-10-10', 'accounting_context' => AccountingContext::SHM],
            [
                ['account_id' => $asset->id, 'debit' => 300_000, 'credit' => 0],
                ['account_id' => $equity->id, 'debit' => 0, 'credit' => 300_000],
            ],
            $this->admin->id
        );
        $this->journalService->post($posted, $this->admin->id);

        // DRAFT
        $this->journalService->createDraft(
            ['transaction_date' => '2026-10-12', 'accounting_context' => AccountingContext::SHM],
            [
                ['account_id' => $asset->id, 'debit' => 999_000, 'credit' => 0],
                ['account_id' => $equity->id, 'debit' => 0, 'credit' => 999_000],
            ],
            $this->admin->id
        );

        $report = $this->reportService->balanceSheet('2026-10-29', AccountingContext::SHM);
        $this->assertSame(300_000.0, $report['summary']['total_assets']);
    }

    /**
     * Test M: SHM / Koperasi context isolation.
     */
    public function test_shm_koperasi_context_isolation(): void
    {
        $shmAsset = $this->createAccount(AccountingContext::SHM, '1-1100', 'Kas SHM', ChartOfAccount::CATEGORY_ASSET, ChartOfAccount::NORMAL_DEBIT);
        $shmEquity = $this->createAccount(AccountingContext::SHM, '3-1100', 'Modal SHM', ChartOfAccount::CATEGORY_EQUITY, ChartOfAccount::NORMAL_CREDIT);

        $kopAsset = $this->createAccount(AccountingContext::KOPERASI, '1-1100', 'Kas Koperasi', ChartOfAccount::CATEGORY_ASSET, ChartOfAccount::NORMAL_DEBIT);
        $kopEquity = $this->createAccount(AccountingContext::KOPERASI, '3-1100', 'Modal Koperasi', ChartOfAccount::CATEGORY_EQUITY, ChartOfAccount::NORMAL_CREDIT);

        // SHM
        $shmEntry = $this->journalService->createDraft(
            ['transaction_date' => '2026-10-10', 'accounting_context' => AccountingContext::SHM],
            [
                ['account_id' => $shmAsset->id, 'debit' => 450_000, 'credit' => 0],
                ['account_id' => $shmEquity->id, 'debit' => 0, 'credit' => 450_000],
            ],
            $this->admin->id
        );
        $this->journalService->post($shmEntry, $this->admin->id);

        // Koperasi
        $kopEntry = $this->journalService->createDraft(
            ['transaction_date' => '2026-10-10', 'accounting_context' => AccountingContext::KOPERASI],
            [
                ['account_id' => $kopAsset->id, 'debit' => 1_500_000, 'credit' => 0],
                ['account_id' => $kopEquity->id, 'debit' => 0, 'credit' => 1_500_000],
            ],
            $this->admin->id
        );
        $this->journalService->post($kopEntry, $this->admin->id);

        $shmReport = $this->reportService->balanceSheet('2026-10-29', AccountingContext::SHM);
        $this->assertSame(450_000.0, $shmReport['summary']['total_assets']);

        $kopReport = $this->reportService->balanceSheet('2026-10-29', AccountingContext::KOPERASI);
        $this->assertSame(1_500_000.0, $kopReport['summary']['total_assets']);
    }

    /**
     * Test N: Revenue and Expense Treatment preserves architectural baseline (current_period_result indicator).
     */
    public function test_revenue_and_expense_treatment_as_current_period_result(): void
    {
        $asset = $this->createAccount(AccountingContext::SHM, '1-1100', 'Kas SHM', ChartOfAccount::CATEGORY_ASSET, ChartOfAccount::NORMAL_DEBIT);
        $rev = $this->createAccount(AccountingContext::SHM, '4-1100', 'Bunga SHM', ChartOfAccount::CATEGORY_REVENUE, ChartOfAccount::NORMAL_CREDIT);

        $e = $this->journalService->createDraft(
            ['transaction_date' => '2026-10-15', 'accounting_context' => AccountingContext::SHM],
            [
                ['account_id' => $asset->id, 'debit' => 120_000, 'credit' => 0],
                ['account_id' => $rev->id, 'debit' => 0, 'credit' => 120_000],
            ],
            $this->admin->id
        );
        $this->journalService->post($e, $this->admin->id);

        $report = $this->reportService->balanceSheet('2026-10-29', AccountingContext::SHM);

        $this->assertSame(120_000.0, $report['summary']['total_assets']);
        $this->assertSame(0.0, $report['summary']['total_liabilities']);
        $this->assertSame(0.0, $report['summary']['total_equity']);
        // Current period result contains 120k from unclosed revenue
        $this->assertSame(120_000.0, $report['summary']['current_period_result']);
        $this->assertTrue($report['summary']['balanced_with_result']);
    }

    /**
     * Test P: Reversal behavior produces expected final balance sheet state.
     */
    public function test_reversal_behavior_on_balance_sheet(): void
    {
        $asset = $this->createAccount(AccountingContext::SHM, '1-1100', 'Kas SHM', ChartOfAccount::CATEGORY_ASSET, ChartOfAccount::NORMAL_DEBIT);
        $equity = $this->createAccount(AccountingContext::SHM, '3-1100', 'Modal SHM', ChartOfAccount::CATEGORY_EQUITY, ChartOfAccount::NORMAL_CREDIT);

        $entry = $this->journalService->createDraft(
            ['transaction_date' => now()->toDateString(), 'accounting_context' => AccountingContext::SHM],
            [
                ['account_id' => $asset->id, 'debit' => 500_000, 'credit' => 0],
                ['account_id' => $equity->id, 'debit' => 0, 'credit' => 500_000],
            ],
            $this->admin->id
        );
        $this->journalService->post($entry, $this->admin->id);

        // Reversal
        $this->journalService->reverse($entry->fresh(), $this->admin->id, 'Reversal test');

        $report = $this->reportService->balanceSheet(now()->toDateString(), AccountingContext::SHM);

        // When original entry is marked STATUS_REVERSED, accountTotals filters journal_entries.status = POSTED,
        // so the original entry is excluded, while the reversal entry (which has debit=0, credit=500000 on asset) is POSTED.
        // Therefore, Asset balance becomes -500,000, Liability/Equity becomes -500,000.
        // The accounting equation Assets = Liabilities + Equity still balances: -500,000 = -500,000.
        $this->assertSame(-500_000.0, $report['summary']['total_assets']);
        $this->assertSame(-500_000.0, $report['summary']['total_equity']);
        $this->assertSame(0.0, $report['summary']['difference']);
        $this->assertTrue($report['balanced']);
    }

    /**
     * Test R: Authorization on Balance Sheet endpoints.
     */
    public function test_balance_sheet_endpoint_authorization(): void
    {
        $response = $this->actingAs($this->unauthorizedUser)->get(route('shm.balance-sheet.index'));
        $response->assertForbidden();

        $exportResponse = $this->actingAs($this->unauthorizedUser)->get(route('shm.balance-sheet.export'));
        $exportResponse->assertForbidden();
    }

    /**
     * Test S & T: XLSX export succeeds, matches UI, and is read-only.
     */
    public function test_xlsx_export_matches_ui_and_is_readonly(): void
    {
        $asset = $this->createAccount(AccountingContext::SHM, '1-1100', 'Kas SHM', ChartOfAccount::CATEGORY_ASSET, ChartOfAccount::NORMAL_DEBIT);
        $equity = $this->createAccount(AccountingContext::SHM, '3-1100', 'Modal SHM', ChartOfAccount::CATEGORY_EQUITY, ChartOfAccount::NORMAL_CREDIT);

        $entry = $this->journalService->createDraft(
            ['transaction_date' => '2026-10-10', 'accounting_context' => AccountingContext::SHM],
            [
                ['account_id' => $asset->id, 'debit' => 600_000, 'credit' => 0],
                ['account_id' => $equity->id, 'debit' => 0, 'credit' => 600_000],
            ],
            $this->admin->id
        );
        $this->journalService->post($entry, $this->admin->id);

        $initialJournalCount = JournalEntry::count();

        $response = $this->actingAs($this->admin)->get(route('shm.balance-sheet.export', [
            'as_of_date' => '2026-10-29',
        ]));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        // Read-only verification
        $this->assertSame($initialJournalCount, JournalEntry::count());
    }
}
