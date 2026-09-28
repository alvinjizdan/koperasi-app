<?php

namespace Tests\Feature;

use App\Models\CashierSession;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Accounting\FinancialReportService;
use App\Services\Accounting\JournalService;
use App\Services\CashierService;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ticket 19 — laporan keuangan di atas fondasi akuntansi Ticket 18.
 * Seluruh data dummy; laporan read-only terhadap jurnal.
 */
class FinancialReportsTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    private JournalService $journals;

    private FinancialReportService $reports;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->seed(ChartOfAccountSeeder::class);

        $this->superadmin = User::factory()->superadmin()->create();
        $this->journals = app(JournalService::class);
        $this->reports = app(FinancialReportService::class);
    }

    private function account(string $code): ChartOfAccount
    {
        return ChartOfAccount::query()->where('code', $code)->firstOrFail();
    }

    /**
     * Posting pasangan seimbang kas (debit) vs akun lawan (kredit).
     */
    private function postPair(string $debitCode, string $creditCode, float $amount, string $date): void
    {
        $entry = $this->journals->createDraft([
            'transaction_date' => $date,
            'description' => "Uji laporan {$debitCode}/{$creditCode}",
        ], [
            ['account_id' => $this->account($debitCode)->id, 'debit' => $amount, 'credit' => 0],
            ['account_id' => $this->account($creditCode)->id, 'debit' => 0, 'credit' => $amount],
        ], $this->superadmin->id);

        $this->journals->post($entry, $this->superadmin->id);
    }

    // =====================================================================
    // NERACA SALDO
    // =====================================================================

    public function test_trial_balance_totals_are_equal_for_posted_journals(): void
    {
        $this->postPair('1-1100', '4-1300', 500_000, '2026-05-01');
        $this->postPair('5-1100', '1-1100', 200_000, '2026-05-02');

        $result = $this->reports->trialBalance('2026-05-01', '2026-05-31');

        // Keseimbangan wajib pada MUTASI debit/kredit (double-entry);
        // saldo debit/kredit TIDAK harus sama (pendapatan bersaldo kredit, dst).
        $this->assertTrue($result['balanced']);
        $this->assertSame(700_000.0, $result['totals']['debit']);
        $this->assertSame(700_000.0, $result['totals']['credit']);

        // Saldo sisi: kas 300k debit, beban 200k debit, pendapatan 500k kredit
        // → total saldo debit = total saldo kredit (neraca saldo selalu
        // seimbang di kedua pasang kolom bila mutasi seimbang).
        $this->assertSame(500_000.0, $result['totals']['balance_debit']);
        $this->assertSame(500_000.0, $result['totals']['balance_credit']);
    }

    public function test_trial_balance_excludes_draft_journals(): void
    {
        $this->postPair('1-1100', '4-1300', 300_000, '2026-05-01');

        // Draft tidak boleh terhitung.
        $this->journals->createDraft([
            'transaction_date' => '2026-05-05',
        ], [
            ['account_id' => $this->account('1-1100')->id, 'debit' => 999_000, 'credit' => 0],
            ['account_id' => $this->account('4-1300')->id, 'debit' => 0, 'credit' => 999_000],
        ], $this->superadmin->id);

        $result = $this->reports->trialBalance('2026-05-01', '2026-05-31');

        $this->assertSame(300_000.0, $result['totals']['debit']);
        $this->assertSame(300_000.0, $result['totals']['credit']);
    }

    public function test_trial_balance_respects_period_filter(): void
    {
        $this->postPair('1-1100', '4-1300', 100_000, '2026-05-01');
        $this->postPair('1-1100', '4-1300', 250_000, '2026-06-01');

        $mayOnly = $this->reports->trialBalance('2026-05-01', '2026-05-31');
        $this->assertSame(100_000.0, $mayOnly['totals']['debit']);

        $juneOnly = $this->reports->trialBalance('2026-06-01', '2026-06-30');
        $this->assertSame(250_000.0, $juneOnly['totals']['debit']);

        $all = $this->reports->trialBalance(null, null);
        $this->assertSame(350_000.0, $all['totals']['debit']);
    }

    public function test_trial_balance_balance_side_follows_normal_balance(): void
    {
        // Kas (debit): 500k + 800k masuk, 200k keluar → saldo debit 1.100.000.
        $this->postPair('1-1100', '4-1300', 500_000, '2026-05-01');
        $this->postPair('5-1100', '1-1100', 200_000, '2026-05-02');

        // Simpanan sukarela (liabilitas, kredit): 800k.
        $this->postPair('1-1100', '2-1300', 800_000, '2026-05-03');

        $result = $this->reports->trialBalance('2026-05-01', '2026-05-31');

        $rowsByCode = collect($result['rows'])->keyBy('code');

        $kas = $rowsByCode['1-1100'];
        $this->assertSame(1_100_000.0, $kas['balance_debit']);
        $this->assertSame(0.0, $kas['balance_credit']);

        $savings = $rowsByCode['2-1300'];
        $this->assertSame(0.0, $savings['balance_debit']);
        $this->assertSame(800_000.0, $savings['balance_credit']);

        $revenue = $rowsByCode['4-1300'];
        $this->assertSame(500_000.0, $revenue['balance_credit']);
    }

    public function test_trial_balance_reversed_journal_still_balances_with_its_reversal(): void
    {
        $entry = $this->journals->createDraft([
            'transaction_date' => '2026-05-01',
        ], [
            ['account_id' => $this->account('1-1100')->id, 'debit' => 400_000, 'credit' => 0],
            ['account_id' => $this->account('4-1300')->id, 'debit' => 0, 'credit' => 400_000],
        ], $this->superadmin->id);
        $this->journals->post($entry, $this->superadmin->id);

        // Reversal: menambah baris pembalik yang juga POSTED.
        $this->journals->reverse($entry->fresh(), $this->superadmin->id, 'Koreksi laporan');

        $result = $this->reports->trialBalance('2026-05-01', '2026-05-31');

        // Jurnal asal + pembalik sama-sama POSTED → saldo kas nol, total tetap seimbang.
        $kas = collect($result['rows'])->firstWhere('code', '1-1100');
        $this->assertSame(0.0, $kas['balance_debit']);
        $this->assertTrue($result['balanced']);
    }

    public function test_trial_balance_includes_accounts_without_activity_when_requested(): void
    {
        $this->postPair('1-1100', '4-1300', 50_000, '2026-05-01');

        $withEmpty = $this->reports->trialBalance('2026-05-01', '2026-05-31', true);
        $this->assertGreaterThan(1, count($withEmpty['rows']));
        $emptyRow = collect($withEmpty['rows'])->firstWhere('code', '1-1200'); // Bank tanpa mutasi
        $this->assertNotNull($emptyRow);
        $this->assertSame(0.0, $emptyRow['total_debit']);

        $activeOnly = $this->reports->trialBalance('2026-05-01', '2026-05-31', false);
        $this->assertCount(2, $activeOnly['rows']); // Kas + Pendapatan lain-lain
    }

    public function test_trial_balance_is_consistent_with_ledger(): void
    {
        $this->postPair('1-1100', '4-1300', 125_000, '2026-05-01');

        $trial = $this->reports->trialBalance('2026-05-01', '2026-05-31');
        $ledger = $this->journals->ledger($this->account('1-1100')->id, '2026-05-01', '2026-05-31');

        $trialKas = collect($trial['rows'])->firstWhere('code', '1-1100');
        $this->assertSame($ledger['totals']['debit'], $trialKas['total_debit']);
        $this->assertSame($ledger['totals']['credit'], $trialKas['total_credit']);
        $this->assertSame($ledger['totals']['balance'], $trialKas['balance_debit']);
    }

    // =====================================================================
    // NERACA
    // =====================================================================

    public function test_balance_sheet_classifies_and_totals_categories(): void
    {
        // Kas 600k (aset); simpanan sukarela 450k (liabilitas); hibah 150k (ekuitas).
        $this->postPair('1-1100', '2-1300', 450_000, '2026-05-01');
        $this->postPair('1-1100', '3-1100', 150_000, '2026-05-02');

        $result = $this->reports->balanceSheet('2026-05-31');

        $this->assertSame(600_000.0, $result['summary']['total_assets']);
        $this->assertSame(450_000.0, $result['summary']['total_liabilities']);
        $this->assertSame(150_000.0, $result['summary']['total_equity']);
        $this->assertTrue($result['balanced']);

        // Surplus 0 (belum ada pendapatan/beban) → seimbang penuh.
        $this->assertSame(0.0, $result['summary']['current_period_result']);
        $this->assertTrue($result['summary']['balanced_with_result']);
    }

    public function test_balance_sheet_parent_accounts_without_lines_are_not_double_counted(): void
    {
        // Seeder membuat kelompok induk 1-0000 tanpa baris jurnal; anak 1-1100 dengan baris.
        $this->postPair('1-1100', '2-1300', 100_000, '2026-05-01');

        $result = $this->reports->balanceSheet('2026-05-31');

        // Total aset = hanya akun dengan baris aktual (Kas 100k), bukan 2× (induk+anak).
        $this->assertSame(100_000.0, $result['summary']['total_assets']);

        $codes = array_column($result['sections']['asset']['accounts'], 'code');
        $this->assertNotContains('1-0000', $codes);
        $this->assertContains('1-1100', $codes);
    }

    public function test_balance_sheet_reports_imbalance_when_result_not_posted_to_equity(): void
    {
        // Pendapatan 300k tanpa penutupan buku → ekuitas tidak menangkap surplus.
        $this->postPair('1-1100', '4-1300', 300_000, '2026-05-01');

        $result = $this->reports->balanceSheet('2026-05-31');

        // Aset 300k vs Liabilitas 0 + Ekuitas 0 → selisih 300k.
        $this->assertFalse($result['balanced']);
        $this->assertSame(300_000.0, $result['summary']['difference']);
        // Surplus periode teridentifikasi terpisah (penutupan buku belum ada).
        $this->assertSame(300_000.0, $result['summary']['current_period_result']);
        $this->assertTrue($result['summary']['balanced_with_result']);
    }

    public function test_balance_sheet_as_of_date_is_inclusive(): void
    {
        $this->postPair('1-1100', '2-1300', 100_000, '2026-05-10');
        $this->postPair('1-1100', '2-1300', 50_000, '2026-05-20');

        $atMid = $this->reports->balanceSheet('2026-05-15');
        $this->assertSame(100_000.0, $atMid['summary']['total_assets']);

        $atEnd = $this->reports->balanceSheet('2026-05-31');
        $this->assertSame(150_000.0, $atEnd['summary']['total_assets']);
    }

    // =====================================================================
    // LABA RUGI
    // =====================================================================

    public function test_income_statement_computes_surplus(): void
    {
        $this->postPair('1-1100', '4-1300', 400_000, '2026-05-01'); // pendapatan
        $this->postPair('5-1100', '1-1100', 150_000, '2026-05-02'); // beban

        $result = $this->reports->incomeStatement('2026-05-01', '2026-05-31');

        $this->assertSame(400_000.0, $result['summary']['total_revenue']);
        $this->assertSame(150_000.0, $result['summary']['total_expense']);
        $this->assertSame(250_000.0, $result['summary']['surplus_deficit']);
    }

    public function test_income_statement_deficit_is_negative(): void
    {
        $this->postPair('5-1100', '1-1100', 120_000, '2026-05-01'); // beban tanpa pendapatan

        $result = $this->reports->incomeStatement('2026-05-01', '2026-05-31');

        $this->assertSame(0.0, $result['summary']['total_revenue']);
        $this->assertSame(120_000.0, $result['summary']['total_expense']);
        $this->assertSame(-120_000.0, $result['summary']['surplus_deficit']);
    }

    public function test_income_statement_respects_period(): void
    {
        $this->postPair('1-1100', '4-1300', 200_000, '2026-05-01');
        $this->postPair('1-1100', '4-1300', 90_000, '2026-06-01');

        $may = $this->reports->incomeStatement('2026-05-01', '2026-05-31');
        $this->assertSame(200_000.0, $may['summary']['total_revenue']);

        $june = $this->reports->incomeStatement('2026-06-01', '2026-06-30');
        $this->assertSame(90_000.0, $june['summary']['total_revenue']);
    }

    public function test_income_statement_is_consistent_with_ledger(): void
    {
        $this->postPair('5-1100', '1-1100', 75_000, '2026-05-01');

        $statement = $this->reports->incomeStatement('2026-05-01', '2026-05-31');
        $ledger = $this->journals->ledger($this->account('5-1100')->id, '2026-05-01', '2026-05-31');

        $expenseRow = collect($statement['sections']['expense']['accounts'])->firstWhere('code', '5-1100');
        $this->assertSame($ledger['totals']['balance'], $expenseRow['balance']);
    }

    // =====================================================================
    // ARUS KAS — AUDIT KESIAPAN
    // =====================================================================

    public function test_cash_flow_statement_is_marked_unavailable_with_operational_summary(): void
    {
        $session = CashierSession::factory()->withOpeningBalance(1_000_000)->create([
            'opened_by' => $this->superadmin->id,
        ]);

        app(CashierService::class)->recordIncome($session, [
            'amount' => 250_000,
            'category' => 'Pendapatan Lain',
            'transaction_date' => '2026-05-10',
            'description' => null,
            'idempotency_key' => null,
        ], $this->superadmin->id);

        $result = $this->reports->cashFlowReadiness('2026-05-01', '2026-05-31');

        // Laporan arus kas akuntansi TIDAK dihasilkan.
        $this->assertFalse($result['available']);
        $this->assertNotEmpty($result['prerequisites']);

        // Ringkasan kas operasional tetap tersedia dari sumber terverifikasi.
        $this->assertSame(250_000.0, $result['operational_summary']['total_income']);
        $this->assertSame(250_000.0, $result['operational_summary']['net_cash']);
    }

    public function test_cash_flow_readiness_reflects_missing_business_journals(): void
    {
        $result = $this->reports->cashFlowReadiness(null, null);

        $this->assertFalse($result['business_journals_posted'], 'Tanpa jurnal apa pun, flag harus false.');
    }

    // =====================================================================
    // OTORISASI & VALIDASI FILTER
    // =====================================================================

    public function test_report_routes_require_permission(): void
    {
        $plain = User::factory()->create();

        foreach ([
            'akuntansi.laporan-keuangan.neraca-saldo',
            'akuntansi.laporan-keuangan.neraca',
            'akuntansi.laporan-keuangan.laba-rugi',
            'akuntansi.laporan-keuangan.arus-kas',
        ] as $routeName) {
            $response = $this->actingAs($plain)->get(route($routeName));
            $this->assertSame(403, $response->status(), "Route {$routeName} harus 403 tanpa permission.");
        }
    }

    public function test_permissioned_user_can_view_reports(): void
    {
        $viewer = User::factory()->create();
        $role = Role::query()->create([
            'name' => 'report_viewer_'.uniqid(),
            'display_name' => 'Viewer Laporan',
            'is_system' => false,
        ]);
        $role->permissions()->attach(
            Permission::query()->whereIn('name', [
                'dashboard.view', 'accounting.reports.view',
            ])->pluck('id')
        );
        $viewer->roles()->attach($role->id);

        $this->actingAs($viewer)->get(route('akuntansi.laporan-keuangan.neraca-saldo'))->assertOk();
        $this->actingAs($viewer)->get(route('akuntansi.laporan-keuangan.neraca'))->assertOk();
        $this->actingAs($viewer)->get(route('akuntansi.laporan-keuangan.laba-rugi'))->assertOk();
        $this->actingAs($viewer)->get(route('akuntansi.laporan-keuangan.arus-kas'))->assertOk();
    }

    public function test_invalid_period_is_rejected_with_error(): void
    {
        $this->actingAs($this->superadmin)
            ->get(route('akuntansi.laporan-keuangan.neraca-saldo', [
                'start_date' => '2026-06-30',
                'end_date' => '2026-06-01', // akhir < awal
            ]))
            ->assertSessionHasErrors('start_date');

        $this->actingAs($this->superadmin)
            ->get(route('akuntansi.laporan-keuangan.laba-rugi', [
                'start_date' => 'bukan-tanggal',
            ]))
            ->assertSessionHasErrors('start_date');
    }

    public function test_reports_never_modify_journal_data(): void
    {
        $this->postPair('1-1100', '4-1300', 500_000, '2026-05-01');

        $before = JournalEntry::with('lines')->get()->toJson();

        $this->reports->trialBalance('2026-05-01', '2026-05-31');
        $this->reports->balanceSheet('2026-05-31');
        $this->reports->incomeStatement('2026-05-01', '2026-05-31');
        $this->reports->cashFlowReadiness('2026-05-01', '2026-05-31');

        $this->assertSame($before, JournalEntry::with('lines')->get()->toJson(), 'Laporan tidak boleh mengubah jurnal.');
    }
}
