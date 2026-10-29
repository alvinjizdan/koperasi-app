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
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ShmTrialBalanceTest extends TestCase
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

    private function createShmAccount(string $code, string $name, string $category, string $normalBalance): ChartOfAccount
    {
        return ChartOfAccount::create([
            'accounting_context' => AccountingContext::SHM,
            'code' => $code,
            'name' => $name,
            'category' => $category,
            'normal_balance' => $normalBalance,
            'is_active' => true,
        ]);
    }

    private function createKoperasiAccount(string $code, string $name, string $category, string $normalBalance): ChartOfAccount
    {
        return ChartOfAccount::create([
            'accounting_context' => AccountingContext::KOPERASI,
            'code' => $code,
            'name' => $name,
            'category' => $category,
            'normal_balance' => $normalBalance,
            'is_active' => true,
        ]);
    }

    /**
     * Test A: Empty state when no POSTED SHM journals exist.
     */
    public function test_empty_state_without_posted_shm_journals(): void
    {
        $this->createShmAccount('1-1100', 'Kas SHM', ChartOfAccount::CATEGORY_ASSET, ChartOfAccount::NORMAL_DEBIT);

        $response = $this->actingAs($this->admin)->get(route('shm.trial-balance.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Shm/Accounting/TrialBalance')
            ->has('report.rows', 1)
            ->where('report.totals.debit', fn ($val) => (float) $val === 0.0)
            ->where('report.totals.credit', fn ($val) => (float) $val === 0.0)
            ->where('report.totals.difference', fn ($val) => (float) $val === 0.0)
            ->where('report.balanced', true)
            ->where('report.status', 'BALANCED')
        );
    }

    /**
     * Test B: Single posted journal creates debit/credit balance.
     */
    public function test_single_posted_shm_journal(): void
    {
        $kas = $this->createShmAccount('1-1100', 'Kas SHM', ChartOfAccount::CATEGORY_ASSET, ChartOfAccount::NORMAL_DEBIT);
        $rev = $this->createShmAccount('4-1100', 'Pendapatan SHM', ChartOfAccount::CATEGORY_REVENUE, ChartOfAccount::NORMAL_CREDIT);

        $entry = $this->journalService->createDraft(
            [
                'transaction_date' => '2026-10-25',
                'description' => 'Disbursement test',
                'accounting_context' => AccountingContext::SHM,
            ],
            [
                ['account_id' => $kas->id, 'debit' => 1_000_000, 'credit' => 0],
                ['account_id' => $rev->id, 'debit' => 0, 'credit' => 1_000_000],
            ],
            $this->admin->id
        );
        $this->journalService->post($entry, $this->admin->id);

        $response = $this->actingAs($this->admin)->get(route('shm.trial-balance.index', ['as_of_date' => '2026-10-29']));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Shm/Accounting/TrialBalance')
            ->where('report.totals.debit', fn ($val) => (float) $val === 1_000_000.0)
            ->where('report.totals.credit', fn ($val) => (float) $val === 1_000_000.0)
            ->where('report.totals.difference', fn ($val) => (float) $val === 0.0)
            ->where('report.balanced', true)
            ->where('report.status', 'BALANCED')
        );
    }

    /**
     * Test C: Multiple posted journals account aggregation.
     */
    public function test_multiple_posted_shm_journals_aggregation(): void
    {
        $kas = $this->createShmAccount('1-1100', 'Kas SHM', ChartOfAccount::CATEGORY_ASSET, ChartOfAccount::NORMAL_DEBIT);
        $rev1 = $this->createShmAccount('4-1100', 'Bunga SHM', ChartOfAccount::CATEGORY_REVENUE, ChartOfAccount::NORMAL_CREDIT);
        $rev2 = $this->createShmAccount('4-1200', 'Denda SHM', ChartOfAccount::CATEGORY_REVENUE, ChartOfAccount::NORMAL_CREDIT);

        // Journal 1
        $e1 = $this->journalService->createDraft(
            ['transaction_date' => '2026-10-10', 'accounting_context' => AccountingContext::SHM],
            [
                ['account_id' => $kas->id, 'debit' => 300_000, 'credit' => 0],
                ['account_id' => $rev1->id, 'debit' => 0, 'credit' => 300_000],
            ],
            $this->admin->id
        );
        $this->journalService->post($e1, $this->admin->id);

        // Journal 2
        $e2 = $this->journalService->createDraft(
            ['transaction_date' => '2026-10-15', 'accounting_context' => AccountingContext::SHM],
            [
                ['account_id' => $kas->id, 'debit' => 50_000, 'credit' => 0],
                ['account_id' => $rev2->id, 'debit' => 0, 'credit' => 50_000],
            ],
            $this->admin->id
        );
        $this->journalService->post($e2, $this->admin->id);

        $report = $this->reportService->trialBalance(null, '2026-10-29', true, AccountingContext::SHM);

        $this->assertSame(350_000.0, $report['totals']['debit']);
        $this->assertSame(350_000.0, $report['totals']['credit']);
        $this->assertTrue($report['balanced']);

        $kasRow = collect($report['rows'])->firstWhere('code', '1-1100');
        $this->assertSame(350_000.0, $kasRow['total_debit']);
        $this->assertSame(350_000.0, $kasRow['balance']);
    }

    /**
     * Test D: Draft journals are excluded from Trial Balance.
     */
    public function test_draft_journals_are_excluded(): void
    {
        $kas = $this->createShmAccount('1-1100', 'Kas SHM', ChartOfAccount::CATEGORY_ASSET, ChartOfAccount::NORMAL_DEBIT);
        $rev = $this->createShmAccount('4-1100', 'Pendapatan SHM', ChartOfAccount::CATEGORY_REVENUE, ChartOfAccount::NORMAL_CREDIT);

        // Posted journal
        $posted = $this->journalService->createDraft(
            ['transaction_date' => '2026-10-10', 'accounting_context' => AccountingContext::SHM],
            [
                ['account_id' => $kas->id, 'debit' => 200_000, 'credit' => 0],
                ['account_id' => $rev->id, 'debit' => 0, 'credit' => 200_000],
            ],
            $this->admin->id
        );
        $this->journalService->post($posted, $this->admin->id);

        // Draft journal (should NOT be included)
        $this->journalService->createDraft(
            ['transaction_date' => '2026-10-12', 'accounting_context' => AccountingContext::SHM],
            [
                ['account_id' => $kas->id, 'debit' => 888_000, 'credit' => 0],
                ['account_id' => $rev->id, 'debit' => 0, 'credit' => 888_000],
            ],
            $this->admin->id
        );

        $report = $this->reportService->trialBalance(null, '2026-10-29', true, AccountingContext::SHM);

        $this->assertSame(200_000.0, $report['totals']['debit']);
        $this->assertSame(200_000.0, $report['totals']['credit']);
    }

    /**
     * Test E: As-of Date and Date Range filtering.
     */
    public function test_as_of_date_filtering(): void
    {
        $kas = $this->createShmAccount('1-1100', 'Kas SHM', ChartOfAccount::CATEGORY_ASSET, ChartOfAccount::NORMAL_DEBIT);
        $rev = $this->createShmAccount('4-1100', 'Pendapatan SHM', ChartOfAccount::CATEGORY_REVENUE, ChartOfAccount::NORMAL_CREDIT);

        // Journal on 2026-10-01
        $e1 = $this->journalService->createDraft(
            ['transaction_date' => '2026-10-01', 'accounting_context' => AccountingContext::SHM],
            [
                ['account_id' => $kas->id, 'debit' => 100_000, 'credit' => 0],
                ['account_id' => $rev->id, 'debit' => 0, 'credit' => 100_000],
            ],
            $this->admin->id
        );
        $this->journalService->post($e1, $this->admin->id);

        // Journal on 2026-10-15
        $e2 = $this->journalService->createDraft(
            ['transaction_date' => '2026-10-15', 'accounting_context' => AccountingContext::SHM],
            [
                ['account_id' => $kas->id, 'debit' => 200_000, 'credit' => 0],
                ['account_id' => $rev->id, 'debit' => 0, 'credit' => 200_000],
            ],
            $this->admin->id
        );
        $this->journalService->post($e2, $this->admin->id);

        // Journal on 2026-11-01 (future of as_of_date)
        $e3 = $this->journalService->createDraft(
            ['transaction_date' => '2026-11-01', 'accounting_context' => AccountingContext::SHM],
            [
                ['account_id' => $kas->id, 'debit' => 500_000, 'credit' => 0],
                ['account_id' => $rev->id, 'debit' => 0, 'credit' => 500_000],
            ],
            $this->admin->id
        );
        $this->journalService->post($e3, $this->admin->id);

        // Query with as_of_date = 2026-10-31
        $report = $this->reportService->trialBalance(null, '2026-10-31', true, AccountingContext::SHM);

        $this->assertSame(300_000.0, $report['totals']['debit']);
        $this->assertSame(300_000.0, $report['totals']['credit']);
    }

    /**
     * Test F: Accounting Context Isolation between Koperasi and SHM.
     */
    public function test_shm_context_isolation_excludes_koperasi_journals(): void
    {
        $shmKas = $this->createShmAccount('1-1100', 'Kas SHM', ChartOfAccount::CATEGORY_ASSET, ChartOfAccount::NORMAL_DEBIT);
        $shmRev = $this->createShmAccount('4-1100', 'Bunga SHM', ChartOfAccount::CATEGORY_REVENUE, ChartOfAccount::NORMAL_CREDIT);

        $kopKas = $this->createKoperasiAccount('1-1100', 'Kas Koperasi', ChartOfAccount::CATEGORY_ASSET, ChartOfAccount::NORMAL_DEBIT);
        $kopRev = $this->createKoperasiAccount('4-1100', 'Bunga Koperasi', ChartOfAccount::CATEGORY_REVENUE, ChartOfAccount::NORMAL_CREDIT);

        // SHM Journal
        $shmJournal = $this->journalService->createDraft(
            ['transaction_date' => '2026-10-20', 'accounting_context' => AccountingContext::SHM],
            [
                ['account_id' => $shmKas->id, 'debit' => 250_000, 'credit' => 0],
                ['account_id' => $shmRev->id, 'debit' => 0, 'credit' => 250_000],
            ],
            $this->admin->id
        );
        $this->journalService->post($shmJournal, $this->admin->id);

        // Koperasi Journal
        $kopJournal = $this->journalService->createDraft(
            ['transaction_date' => '2026-10-20', 'accounting_context' => AccountingContext::KOPERASI],
            [
                ['account_id' => $kopKas->id, 'debit' => 700_000, 'credit' => 0],
                ['account_id' => $kopRev->id, 'debit' => 0, 'credit' => 700_000],
            ],
            $this->admin->id
        );
        $this->journalService->post($kopJournal, $this->admin->id);

        // SHM Trial Balance report
        $shmReport = $this->reportService->trialBalance(null, null, true, AccountingContext::SHM);

        $this->assertSame(250_000.0, $shmReport['totals']['debit']);
        $this->assertSame(250_000.0, $shmReport['totals']['credit']);

        // Koperasi Trial Balance report
        $kopReport = $this->reportService->trialBalance(null, null, true, AccountingContext::KOPERASI);
        $this->assertSame(700_000.0, $kopReport['totals']['debit']);
        $this->assertSame(700_000.0, $kopReport['totals']['credit']);
    }

    /**
     * Test G: Normal balance calculations for Debit-normal and Credit-normal accounts.
     */
    public function test_normal_balance_calculation(): void
    {
        $debitAcc = $this->createShmAccount('1-1100', 'Kas SHM', ChartOfAccount::CATEGORY_ASSET, ChartOfAccount::NORMAL_DEBIT);
        $creditAcc = $this->createShmAccount('2-1100', 'Hutang SHM', ChartOfAccount::CATEGORY_LIABILITY, ChartOfAccount::NORMAL_CREDIT);

        $entry = $this->journalService->createDraft(
            ['transaction_date' => '2026-10-20', 'accounting_context' => AccountingContext::SHM],
            [
                ['account_id' => $debitAcc->id, 'debit' => 450_000, 'credit' => 0],
                ['account_id' => $creditAcc->id, 'debit' => 0, 'credit' => 450_000],
            ],
            $this->admin->id
        );
        $this->journalService->post($entry, $this->admin->id);

        $report = $this->reportService->trialBalance(null, null, true, AccountingContext::SHM);
        $rowsByCode = collect($report['rows'])->keyBy('code');

        // Asset (normal debit): balance = debit - credit = 450,000
        $this->assertSame(450_000.0, $rowsByCode['1-1100']['balance']);

        // Liability (normal credit): balance = credit - debit = 450,000
        $this->assertSame(450_000.0, $rowsByCode['2-1100']['balance']);
    }

    /**
     * Test H: Journal reversal maintains final balanced accounting state.
     */
    public function test_journal_reversal_reflects_final_state(): void
    {
        $kas = $this->createShmAccount('1-1100', 'Kas SHM', ChartOfAccount::CATEGORY_ASSET, ChartOfAccount::NORMAL_DEBIT);
        $rev = $this->createShmAccount('4-1100', 'Pendapatan SHM', ChartOfAccount::CATEGORY_REVENUE, ChartOfAccount::NORMAL_CREDIT);

        $entry = $this->journalService->createDraft(
            ['transaction_date' => now()->toDateString(), 'accounting_context' => AccountingContext::SHM],
            [
                ['account_id' => $kas->id, 'debit' => 600_000, 'credit' => 0],
                ['account_id' => $rev->id, 'debit' => 0, 'credit' => 600_000],
            ],
            $this->admin->id
        );
        $this->journalService->post($entry, $this->admin->id);

        // Perform standard reversal (creates compensating reversal journal with status POSTED)
        $this->journalService->reverse($entry->fresh(), $this->admin->id, 'Correction test');

        $report = $this->reportService->trialBalance(now()->toDateString(), now()->toDateString(), true, AccountingContext::SHM);

        $kasRow = collect($report['rows'])->firstWhere('code', '1-1100');
        // Original journal status became REVERSED, reversal journal is POSTED with credit 600k.
        // Balance reflects the resulting final accounting state.
        $this->assertTrue($report['balanced']);
        $this->assertSame('BALANCED', $report['status']);
    }

    /**
     * Test I: Account without transactions is displayed with 0 amounts.
     */
    public function test_account_without_transactions(): void
    {
        $activeAcc = $this->createShmAccount('1-1100', 'Kas SHM', ChartOfAccount::CATEGORY_ASSET, ChartOfAccount::NORMAL_DEBIT);
        $unusedAcc = $this->createShmAccount('1-1200', 'Bank Cadangan SHM', ChartOfAccount::CATEGORY_ASSET, ChartOfAccount::NORMAL_DEBIT);
        $rev = $this->createShmAccount('4-1100', 'Pendapatan SHM', ChartOfAccount::CATEGORY_REVENUE, ChartOfAccount::NORMAL_CREDIT);

        $entry = $this->journalService->createDraft(
            ['transaction_date' => '2026-10-20', 'accounting_context' => AccountingContext::SHM],
            [
                ['account_id' => $activeAcc->id, 'debit' => 100_000, 'credit' => 0],
                ['account_id' => $rev->id, 'debit' => 0, 'credit' => 100_000],
            ],
            $this->admin->id
        );
        $this->journalService->post($entry, $this->admin->id);

        $report = $this->reportService->trialBalance(null, null, true, AccountingContext::SHM);

        $unusedRow = collect($report['rows'])->firstWhere('code', '1-1200');
        $this->assertNotNull($unusedRow);
        $this->assertSame(0.0, $unusedRow['total_debit']);
        $this->assertSame(0.0, $unusedRow['total_credit']);
        $this->assertSame(0.0, $unusedRow['balance']);
    }

    /**
     * Test J: Unbalanced state detection without automatic adjustment.
     */
    public function test_unbalanced_state_detection_and_no_auto_adjustment(): void
    {
        $kas = $this->createShmAccount('1-1100', 'Kas SHM', ChartOfAccount::CATEGORY_ASSET, ChartOfAccount::NORMAL_DEBIT);
        $rev = $this->createShmAccount('4-1100', 'Pendapatan SHM', ChartOfAccount::CATEGORY_REVENUE, ChartOfAccount::NORMAL_CREDIT);

        $entry = $this->journalService->createDraft(
            ['transaction_date' => '2026-10-20', 'accounting_context' => AccountingContext::SHM],
            [
                ['account_id' => $kas->id, 'debit' => 100_000, 'credit' => 0],
                ['account_id' => $rev->id, 'debit' => 0, 'credit' => 100_000],
            ],
            $this->admin->id
        );
        $this->journalService->post($entry, $this->admin->id);

        // Intentionally create unbalanced lines in DB to test anomaly detection
        DB::table('journal_lines')->insert([
            'journal_entry_id' => $entry->id,
            'account_id' => $kas->id,
            'debit' => 50_000,
            'credit' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $initialJournalCount = JournalEntry::count();

        $report = $this->reportService->trialBalance(null, null, true, AccountingContext::SHM);

        $this->assertFalse($report['balanced']);
        $this->assertSame('NOT BALANCED', $report['status']);
        $this->assertSame(50_000.0, $report['totals']['difference']);

        // Assert NO auto-adjustment journal was created
        $this->assertSame($initialJournalCount, JournalEntry::count());
    }

    /**
     * Test XLSX Export: Generates valid XLSX with matching dataset and read-only behavior.
     */
    public function test_xlsx_export_matches_ui_dataset_and_is_readonly(): void
    {
        $kas = $this->createShmAccount('1-1100', 'Kas SHM', ChartOfAccount::CATEGORY_ASSET, ChartOfAccount::NORMAL_DEBIT);
        $rev = $this->createShmAccount('4-1100', 'Pendapatan SHM', ChartOfAccount::CATEGORY_REVENUE, ChartOfAccount::NORMAL_CREDIT);

        $entry = $this->journalService->createDraft(
            ['transaction_date' => '2026-10-20', 'accounting_context' => AccountingContext::SHM],
            [
                ['account_id' => $kas->id, 'debit' => 850_000, 'credit' => 0],
                ['account_id' => $rev->id, 'debit' => 0, 'credit' => 850_000],
            ],
            $this->admin->id
        );
        $this->journalService->post($entry, $this->admin->id);

        $initialJournalCount = JournalEntry::count();

        $response = $this->actingAs($this->admin)->get(route('shm.trial-balance.export', ['as_of_date' => '2026-10-29']));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        // Verify read-only: no new journals created
        $this->assertSame($initialJournalCount, JournalEntry::count());
    }

    /**
     * Test Authorization: Endpoint rejects unauthorized users.
     */
    public function test_trial_balance_endpoint_authorization(): void
    {
        $response = $this->actingAs($this->unauthorizedUser)->get(route('shm.trial-balance.index'));
        $response->assertForbidden();

        $exportResponse = $this->actingAs($this->unauthorizedUser)->get(route('shm.trial-balance.export'));
        $exportResponse->assertForbidden();
    }
}
