<?php

namespace Tests\Feature;

use App\Models\CashierSession;
use App\Models\CashTransaction;
use App\Models\InstallmentPayment;
use App\Models\InstallmentSchedule;
use App\Models\Loan;
use App\Models\Member;
use App\Models\SavingProduct;
use App\Models\SavingsAccount;
use App\Models\SavingsTransaction;
use App\Models\User;
use App\Services\Reports\ReportPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        // Superadmin untuk melewati permission middleware pada route laporan.
        $this->user = User::factory()->superadmin()->create();
    }

    private function today(): string
    {
        return ReportPeriod::todayDate();
    }

    private function pastDate(int $daysAgo): string
    {
        return ReportPeriod::today()->subDays($daysAgo)->toDateString();
    }

    private function account(Member $member, float $balance = 0): SavingsAccount
    {
        return SavingsAccount::factory()->create([
            'member_id' => $member->id,
            'saving_product_id' => SavingProduct::factory()->create(['is_active' => true])->id,
            'balance' => $balance,
        ]);
    }

    /**
     * Pinjaman yang sudah dicairkan melalui alur resmi modul Pinjaman.
     */
    private function disbursedLoan(Member $member, float $amount, int $tenor = 12, string $method = 'TRANSFER'): Loan
    {
        $loan = Loan::factory()->approved()->create([
            'member_id' => $member->id,
            'amount' => $amount,
            'tenor_months' => $tenor,
        ]);

        $this->actingAs($this->user)->post(route('pinjaman.disburse.store', $loan->id), [
            'disbursement_date' => $this->today(),
            'first_due_date' => ReportPeriod::today()->addMonth()->toDateString(),
            'method' => $method,
        ]);

        return $loan->fresh();
    }

    /** 1. Dashboard menampilkan ringkasan sesuai data yang tersedia */
    public function test_dashboard_shows_metrics_from_real_data(): void
    {
        $active = Member::factory()->create(['status' => 'active']);
        Member::factory()->inactive()->create();

        $this->account($active, 250000);

        $loan = $this->disbursedLoan($active, 1200000, 12);
        InstallmentPayment::factory()->create([
            'loan_id' => $loan->id,
            'amount' => 500000,
            'payment_date' => $this->today(),
            'status' => 'COMPLETED',
        ]);

        $response = $this->actingAs($this->user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('metrics.members.total', fn ($value) => (int) $value === 2)
            ->where('metrics.members.active', fn ($value) => (int) $value === 1)
            ->where('metrics.members.inactive', fn ($value) => (int) $value === 1)
            ->where('metrics.savings.total_balance', fn ($value) => (float) $value === 250000.0)
            ->where('metrics.savings.has_accounts', true)
            ->where('metrics.loans.disbursed_amount', fn ($value) => (float) $value === 1200000.0)
            ->where('metrics.loans.paid_total', fn ($value) => (float) $value === 500000.0)
            ->where('metrics.loans.outstanding_amount', fn ($value) => (float) $value === 700000.0)
            ->where('metrics.loans.by_status', fn ($rows) => collect($rows)->firstWhere('status', 'APPROVED')['count'] === 0)
        );
    }

    /** 2. Filter tanggal dashboard menghasilkan cakupan data yang benar */
    public function test_dashboard_period_filter_scopes_transaction_metrics(): void
    {
        Member::factory()->create(['joined_at' => $this->today()]);
        Member::factory()->create(['joined_at' => $this->pastDate(45)]);

        $account = $this->account(Member::factory()->create(), 0);

        SavingsTransaction::factory()->create([
            'savings_account_id' => $account->id,
            'type' => 'deposit',
            'amount' => 100000,
            'transaction_date' => $this->today(),
        ]);
        SavingsTransaction::factory()->create([
            'savings_account_id' => $account->id,
            'type' => 'withdrawal',
            'amount' => 25000,
            'transaction_date' => $this->pastDate(45),
        ]);

        $todayResponse = $this->actingAs($this->user)->get(route('dashboard', ['period' => 'today']));
        $todayResponse->assertOk();
        $todayResponse->assertInertia(fn (Assert $page) => $page
            ->where('period.preset', 'today')
            ->where('metrics.members.new_in_period', fn ($value) => (int) $value === 1)
            ->where('metrics.savings.period_deposits', fn ($value) => (float) $value === 100000.0)
            ->where('metrics.savings.period_withdrawals', fn ($value) => (float) $value === 0.0)
        );

        $oldRangeResponse = $this->actingAs($this->user)->get(route('dashboard', [
            'period' => 'custom',
            'date_from' => $this->pastDate(60),
            'date_to' => $this->pastDate(30),
        ]));
        $oldRangeResponse->assertOk();
        $oldRangeResponse->assertInertia(fn (Assert $page) => $page
            ->where('period.preset', 'custom')
            ->where('metrics.members.new_in_period', fn ($value) => (int) $value === 1)
            ->where('metrics.savings.period_deposits', fn ($value) => (float) $value === 0.0)
            ->where('metrics.savings.period_withdrawals', fn ($value) => (float) $value === 25000.0)
        );
    }

    /** 10. Filter tanggal tidak mengubah saldo saat ini */
    public function test_period_filter_does_not_change_current_balances(): void
    {
        $account = $this->account(Member::factory()->create(), 400000);

        SavingsTransaction::factory()->create([
            'savings_account_id' => $account->id,
            'type' => 'deposit',
            'amount' => 400000,
            'transaction_date' => $this->pastDate(90),
        ]);

        $balanceFor = function (array $filters): float {
            $response = $this->actingAs($this->user)->get(route('laporan.simpanan', $filters));

            return (float) $response->viewData('page')['props']['summary']['total_balance'];
        };

        $thisMonth = $balanceFor(['period' => 'this_month']);
        $oldRange = $balanceFor([
            'period' => 'custom',
            'date_from' => $this->pastDate(120),
            'date_to' => $this->pastDate(60),
        ]);

        $this->assertSame(400000.0, $monthlyBalance = $thisMonth);
        $this->assertSame($thisMonth, $oldRange, 'Saldo saat ini tidak boleh berubah saat filter tanggal diubah.');
        $this->assertSame(400000.0, $monthlyBalance);
    }

    /** 3. Laporan anggota menampilkan hasil sesuai filter */
    public function test_member_report_applies_filters_and_search(): void
    {
        $active = Member::factory()->create(['status' => 'active', 'joined_at' => $this->today()]);
        Member::factory()->inactive()->create(['joined_at' => $this->pastDate(200)]);
        $this->account($active, 0);

        $all = $this->actingAs($this->user)->get(route('laporan.anggota'));
        $all->assertOk();
        $all->assertInertia(fn (Assert $page) => $page
            ->component('Laporan/Anggota')
            ->where('summary.total', fn ($value) => (int) $value === 2)
            ->where('rows.total', fn ($value) => (int) $value === 2)
        );

        $activeOnly = $this->actingAs($this->user)->get(route('laporan.anggota', ['status' => 'active']));
        $activeOnly->assertInertia(fn (Assert $page) => $page
            ->where('summary.total', fn ($value) => (int) $value === 1)
            ->where('summary.active', fn ($value) => (int) $value === 1)
            ->where('summary.with_savings_account', fn ($value) => (int) $value === 1)
        );

        $byNumber = $this->actingAs($this->user)->get(route('laporan.anggota', ['search' => $active->member_number]));
        $byNumber->assertInertia(fn (Assert $page) => $page
            ->where('rows.total', fn ($value) => (int) $value === 1)
            ->where('rows.data', fn ($rows) => $rows[0]['member_number'] === $active->member_number)
        );

        $byJoinDate = $this->actingAs($this->user)->get(route('laporan.anggota', [
            'date_from' => $this->pastDate(30),
            'date_to' => $this->today(),
        ]));
        $byJoinDate->assertInertia(fn (Assert $page) => $page
            ->where('summary.total', fn ($value) => (int) $value === 1)
        );
    }

    /** Data pribadi anggota tidak ditampilkan utuh */
    public function test_member_report_masks_personal_data(): void
    {
        $member = Member::factory()->create([
            'identity_number' => '3501234567890123',
            'phone' => '081234567890',
        ]);

        $response = $this->actingAs($this->user)->get(route('laporan.anggota'));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('rows.data', function ($rows) use ($member) {
                $row = collect($rows)->firstWhere('id', $member->id);

                return $row !== null
                    && $row['identity_number_masked'] !== '3501234567890123'
                    && str_ends_with((string) $row['identity_number_masked'], '0123')
                    && str_ends_with((string) $row['phone_masked'], '7890');
            })
        );
    }

    /** 4. Laporan simpanan sesuai dengan transaksi sah dan saldo rekening */
    public function test_savings_report_uses_official_balance_and_valid_transactions_only(): void
    {
        $account = $this->account(Member::factory()->create(), 500000);

        SavingsTransaction::factory()->create([
            'savings_account_id' => $account->id,
            'type' => 'deposit',
            'amount' => 150000,
            'transaction_date' => $this->today(),
            'status' => 'completed',
        ]);
        SavingsTransaction::factory()->create([
            'savings_account_id' => $account->id,
            'type' => 'deposit',
            'amount' => 9999999,
            'transaction_date' => $this->today(),
            'status' => 'reversed',
        ]);

        $response = $this->actingAs($this->user)->get(route('laporan.simpanan'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Laporan/Simpanan')
            ->where('summary.total_balance', fn ($value) => (float) $value === 500000.0)
            ->where('summary.period_deposits', fn ($value) => (float) $value === 150000.0)
            ->where('summary.accounts', fn ($value) => (int) $value === 1)
            ->where('rows.data', function ($rows) {
                $row = $rows[0];

                return (float) $row['balance'] === 500000.0
                    && (float) $row['period_deposits'] === 150000.0
                    && $row['member_name'] !== null;
            })
        );
    }

    /** 5. Laporan pinjaman membedakan pengajuan, persetujuan, dan pencairan */
    public function test_loan_report_separates_statuses(): void
    {
        $member = Member::factory()->create();

        Loan::factory()->create(['member_id' => $member->id, 'amount' => 1000000, 'status' => 'PENDING']);
        Loan::factory()->approved()->create(['member_id' => $member->id, 'amount' => 2000000]);
        $disbursed = $this->disbursedLoan($member, 3000000, 12);

        $response = $this->actingAs($this->user)->get(route('laporan.pinjaman'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Laporan/Pinjaman')
            ->where('summary.pending_count', fn ($value) => (int) $value === 1)
            ->where('summary.approved_count', fn ($value) => (int) $value === 1)
            ->where('summary.disbursed_count', fn ($value) => (int) $value === 1)
            ->where('summary.disbursed_amount', fn ($value) => (float) $value === 3000000.0)
            ->where('summary.submitted_amount', fn ($value) => (float) $value === 6000000.0)
            ->where('summary.outstanding_amount', fn ($value) => (float) $value === 3000000.0)
            ->where('rows.data', function ($rows) use ($disbursed) {
                $disbursedRow = collect($rows)->firstWhere('id', $disbursed->id);
                $pendingRow = collect($rows)->firstWhere('status', 'PENDING');

                // Pinjaman yang belum dicairkan tidak memiliki angka sisa kewajiban.
                return $disbursedRow !== null
                    && (float) $disbursedRow['remaining_balance'] === 3000000.0
                    && $pendingRow['remaining_balance'] === null
                    && $pendingRow['disbursed_at'] === null;
            })
        );

        $pendingOnly = $this->actingAs($this->user)->get(route('laporan.pinjaman', ['status' => 'PENDING']));
        $pendingOnly->assertInertia(fn (Assert $page) => $page
            ->where('rows.total', fn ($value) => (int) $value === 1)
            ->where('summary.disbursed_amount', fn ($value) => (float) $value === 0.0)
        );
    }

    /** 6. Laporan pembayaran hanya menghitung transaksi berhasil */
    public function test_installment_report_counts_only_successful_payments(): void
    {
        $loan = Loan::factory()->create();

        InstallmentPayment::factory()->create([
            'loan_id' => $loan->id,
            'amount' => 300000,
            'payment_date' => $this->today(),
            'status' => 'COMPLETED',
        ]);
        InstallmentPayment::factory()->create([
            'loan_id' => $loan->id,
            'amount' => 200000,
            'payment_date' => $this->today(),
            'status' => 'REVERSED',
        ]);

        $response = $this->actingAs($this->user)->get(route('laporan.angsuran'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Laporan/Angsuran')
            ->where('summary.completed_total', fn ($value) => (float) $value === 300000.0)
            ->where('summary.completed_count', fn ($value) => (int) $value === 1)
            ->where('summary.reversed_total', fn ($value) => (float) $value === 200000.0)
            ->where('summary.payments_count', fn ($value) => (int) $value === 2)
        );

        $completedOnly = $this->actingAs($this->user)->get(route('laporan.angsuran', ['status' => 'COMPLETED']));
        $completedOnly->assertInertia(fn (Assert $page) => $page
            ->where('rows.total', fn ($value) => (int) $value === 1)
            ->where('summary.completed_total', fn ($value) => (float) $value === 300000.0)
        );
    }

    /** 7. Laporan tunggakan hanya menampilkan angsuran yang memenuhi kriteria */
    public function test_overdue_report_only_lists_past_due_schedules_with_remaining(): void
    {
        $member = Member::factory()->create();
        $loan = Loan::factory()->create(['member_id' => $member->id]);

        $overdue = InstallmentSchedule::factory()->create([
            'loan_id' => $loan->id,
            'installment_number' => 1,
            'due_date' => $this->pastDate(10),
            'total_amount' => 500000,
            'paid_amount' => 400000,
            'remaining_amount' => 100000,
            'status' => 'PARTIALLY_PAID',
        ]);

        // Belum jatuh tempo.
        InstallmentSchedule::factory()->create([
            'loan_id' => $loan->id,
            'installment_number' => 2,
            'due_date' => ReportPeriod::today()->addDays(10)->toDateString(),
            'total_amount' => 500000,
            'paid_amount' => 0,
            'remaining_amount' => 500000,
            'status' => 'UPCOMING',
        ]);

        // Sudah jatuh tempo tetapi tidak memiliki sisa tagihan.
        InstallmentSchedule::factory()->create([
            'loan_id' => $loan->id,
            'installment_number' => 3,
            'due_date' => $this->pastDate(5),
            'total_amount' => 500000,
            'paid_amount' => 500000,
            'remaining_amount' => 0,
            'status' => 'PAID',
        ]);

        $response = $this->actingAs($this->user)->get(route('laporan.tunggakan'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Laporan/Tunggakan')
            ->where('summary.schedules_count', fn ($value) => (int) $value === 1)
            ->where('summary.loans_count', fn ($value) => (int) $value === 1)
            ->where('summary.total_remaining', fn ($value) => (float) $value === 100000.0)
            ->where('summary.total_paid', fn ($value) => (float) $value === 400000.0)
            ->where('rows.data', function ($rows) use ($overdue) {
                return count($rows) === 1
                    && $rows[0]['id'] === $overdue->id
                    && (int) $rows[0]['days_late'] === 10;
            })
        );

        $filtered = $this->actingAs($this->user)->get(route('laporan.tunggakan', ['minimum_days_late' => 30]));
        $filtered->assertInertia(fn (Assert $page) => $page
            ->where('summary.schedules_count', fn ($value) => (int) $value === 0)
        );
    }

    /** 8. Laporan transaksi kas tidak menghitung transaksi ganda */
    public function test_cash_transaction_report_separates_types_and_excludes_reversed(): void
    {
        $this->actingAs($this->user)->post(route('kasir.sesi.store'), [
            'operational_date' => $this->today(),
            'opening_balance' => 100000,
        ]);

        $this->actingAs($this->user)->post(route('kasir.penerimaan.store'), [
            'amount' => 100000,
            'category' => 'Pendapatan Lain-lain',
            'transaction_date' => $this->today(),
        ]);
        $this->actingAs($this->user)->post(route('kasir.pengeluaran.store'), [
            'amount' => 40000,
            'category' => 'Biaya Operasional',
            'transaction_date' => $this->today(),
        ]);
        $this->actingAs($this->user)->post(route('kasir.setoran.store'), [
            'amount' => 30000,
            'transaction_date' => $this->today(),
            'description' => 'Setoran kas pusat',
        ]);

        // Transaksi yang dibatalkan tidak boleh masuk total.
        CashTransaction::factory()->income(500000)->create([
            'cashier_session_id' => CashierSession::first()->id,
            'transaction_date' => $this->today(),
            'status' => CashTransaction::STATUS_REVERSED,
        ]);

        $response = $this->actingAs($this->user)->get(route('laporan.transaksi-kas'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Laporan/TransaksiKas')
            ->where('summary.income_total', fn ($value) => (float) $value === 100000.0)
            ->where('summary.expense_total', fn ($value) => (float) $value === 40000.0)
            ->where('summary.deposit_total', fn ($value) => (float) $value === 30000.0)
            ->where('summary.drawer_movement', fn ($value) => (float) $value === 30000.0)
            ->where('summary.posted_count', fn ($value) => (int) $value === 3)
            ->where('summary.reversed_count', fn ($value) => (int) $value === 1)
            ->where('rows.total', fn ($value) => (int) $value === 4)
        );
    }

    /** 9. Laporan kas harian konsisten dengan saldo sesi kasir */
    public function test_cash_daily_report_matches_session_balance(): void
    {
        $this->actingAs($this->user)->post(route('kasir.sesi.store'), [
            'operational_date' => $this->today(),
            'opening_balance' => 100000,
        ]);

        $session = CashierSession::firstOrFail();

        $this->actingAs($this->user)->post(route('kasir.penerimaan.store'), [
            'amount' => 50000,
            'category' => 'Pendapatan Lain-lain',
            'transaction_date' => $this->today(),
        ]);
        $this->actingAs($this->user)->post(route('kasir.pengeluaran.store'), [
            'amount' => 20000,
            'category' => 'Biaya Operasional',
            'transaction_date' => $this->today(),
        ]);

        // Sebelum ditutup: kas berjalan masih berada pada sesi terbuka.
        $open = $this->actingAs($this->user)->get(route('laporan.kas-harian'));
        $open->assertInertia(fn (Assert $page) => $page
            ->where('summary.sessions_count', fn ($value) => (int) $value === 1)
            ->where('summary.open_sessions_count', fn ($value) => (int) $value === 1)
            ->where('summary.cash_on_hand_open_sessions', fn ($value) => (float) $value === 130000.0)
            ->where('rows.data', fn ($rows) => (float) $rows[0]['system_balance'] === 130000.0
                && $rows[0]['difference'] === null)
        );

        $this->actingAs($this->user)->post(route('kasir.sesi.close.store', $session->id), [
            'physical_closing_balance' => 130000,
        ]);

        $closed = $this->actingAs($this->user)->get(route('laporan.kas-harian'));
        $closed->assertInertia(fn (Assert $page) => $page
            ->where('summary.closed_sessions_count', fn ($value) => (int) $value === 1)
            ->where('summary.difference_total', fn ($value) => (float) $value === 0.0)
            ->where('summary.cash_on_hand_open_sessions', fn ($value) => (float) $value === 0.0)
            ->where('rows.data', fn ($rows) => (float) $rows[0]['system_balance'] === 130000.0
                && (float) $rows[0]['physical_closing_balance'] === 130000.0
                && (float) $rows[0]['difference'] === 0.0
                && $rows[0]['status'] === 'CLOSED')
        );
    }

    /** 11. Pagination dan pencarian berfungsi */
    public function test_reports_support_pagination_and_search(): void
    {
        Member::factory()->count(25)->create(['status' => 'active', 'joined_at' => $this->today()]);

        $firstPage = $this->actingAs($this->user)->get(route('laporan.anggota'));
        $firstPage->assertInertia(fn (Assert $page) => $page
            ->where('rows.total', fn ($value) => (int) $value === 25)
            ->where('rows.per_page', fn ($value) => (int) $value === 20)
            ->where('rows.last_page', fn ($value) => (int) $value === 2)
            ->where('rows.data', fn ($rows) => count($rows) === 20)
        );

        $secondPage = $this->actingAs($this->user)->get(route('laporan.anggota', ['page' => 2]));
        $secondPage->assertInertia(fn (Assert $page) => $page
            ->where('rows.current_page', fn ($value) => (int) $value === 2)
            ->where('rows.data', fn ($rows) => count($rows) === 5)
        );

        $target = Member::query()->orderBy('id')->first();
        $search = $this->actingAs($this->user)->get(route('laporan.anggota', ['search' => $target->full_name]));
        $search->assertInertia(fn (Assert $page) => $page
            ->where('rows.total', fn ($value) => (int) $value === 1)
        );
    }

    /** 12. Ekspor mengikuti filter aktif dan tidak memuat data pribadi */
    public function test_member_export_follows_filters_and_omits_personal_data(): void
    {
        $target = Member::factory()->create([
            'member_number' => 'ANG-9001',
            'full_name' => 'Anggota Target',
            'identity_number' => '3501234567890123',
            'phone' => '081234567890',
        ]);
        Member::factory()->create(['member_number' => 'ANG-9002', 'full_name' => 'Anggota Lain']);

        $response = $this->actingAs($this->user)->get(route('laporan.export', [
            'report' => 'anggota',
            'search' => 'ANG-9001',
        ]));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));

        $content = $response->streamedContent();

        $this->assertStringContainsString($target->member_number, $content);
        $this->assertStringNotContainsString('ANG-9002', $content);
        $this->assertStringNotContainsString('3501234567890123', $content);
        $this->assertStringNotContainsString('081234567890', $content);
    }

    /** Ekspor menetralkan nilai yang berpotensi menjadi formula spreadsheet */
    public function test_export_neutralises_formula_injection(): void
    {
        Member::factory()->create(['full_name' => '=HYPERLINK("http://evil.test")']);

        $response = $this->actingAs($this->user)->get(route('laporan.export', ['report' => 'anggota']));

        $content = $response->streamedContent();

        $this->assertStringContainsString('\'=HYPERLINK', $content);
    }

    /** 12/13. Ekspor dan laporan terbatas tidak dapat diakses tanpa autentikasi */
    public function test_reports_and_exports_require_authentication(): void
    {
        $this->get(route('laporan.index'))->assertRedirect(route('login'));
        $this->get(route('laporan.anggota'))->assertRedirect(route('login'));
        $this->get(route('laporan.simpanan'))->assertRedirect(route('login'));
        $this->get(route('laporan.pinjaman'))->assertRedirect(route('login'));
        $this->get(route('laporan.angsuran'))->assertRedirect(route('login'));
        $this->get(route('laporan.tunggakan'))->assertRedirect(route('login'));
        $this->get(route('laporan.transaksi-kas'))->assertRedirect(route('login'));
        $this->get(route('laporan.kas-harian'))->assertRedirect(route('login'));
        $this->get(route('laporan.export', ['report' => 'anggota']))->assertRedirect(route('login'));
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    /** Slug laporan yang tidak dikenal tidak dapat diekspor */
    public function test_unknown_report_export_returns_not_found(): void
    {
        $this->actingAs($this->user)->get('/laporan/tidak-dikenal/export')->assertNotFound();
    }

    /** 14. Halaman dashboard dan laporan tidak menampilkan data dummy */
    public function test_pages_render_empty_states_without_data(): void
    {
        $dashboard = $this->actingAs($this->user)->get(route('dashboard'));
        $dashboard->assertOk();
        $dashboard->assertInertia(fn (Assert $page) => $page
            ->where('metrics.members.total', fn ($value) => (int) $value === 0)
            ->where('metrics.savings.has_accounts', false)
            ->where('metrics.loans.has_disbursements', false)
            ->where('metrics.cash.has_sessions', false)
            ->where('metrics.activities.has_activity', false)
        );

        foreach ([
            'laporan.anggota' => 'Laporan/Anggota',
            'laporan.simpanan' => 'Laporan/Simpanan',
            'laporan.pinjaman' => 'Laporan/Pinjaman',
            'laporan.angsuran' => 'Laporan/Angsuran',
            'laporan.tunggakan' => 'Laporan/Tunggakan',
            'laporan.transaksi-kas' => 'Laporan/TransaksiKas',
            'laporan.kas-harian' => 'Laporan/KasHarian',
        ] as $route => $component) {
            $response = $this->actingAs($this->user)->get(route($route));

            $response->assertOk();
            $response->assertInertia(fn (Assert $page) => $page
                ->component($component)
                ->where('rows.total', fn ($value) => (int) $value === 0)
                // AssertableJson membungkus nilai array menjadi Collection.
                ->where('rows.data', fn ($rows) => count($rows) === 0)
            );
        }
    }

    /** 15. Ringkasan laporan konsisten dengan data modul sumber */
    public function test_loan_summary_matches_official_loan_balance(): void
    {
        $member = Member::factory()->create();
        $loan = $this->disbursedLoan($member, 1200000, 12);

        $this->actingAs($this->user)->post(route('angsuran.pay.store', $loan->id), [
            'amount' => 300000,
            'payment_date' => $this->today(),
        ]);

        $loan->refresh();

        $response = $this->actingAs($this->user)->get(route('laporan.pinjaman'));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('summary.paid_total', fn ($value) => (float) $value === (float) $loan->total_paid)
            ->where('summary.outstanding_amount', fn ($value) => (float) $value === (float) $loan->remaining_balance)
            ->where('rows.data', function ($rows) use ($loan) {
                return (float) $rows[0]['paid_total'] === (float) $loan->total_paid
                    && (float) $rows[0]['remaining_balance'] === (float) $loan->remaining_balance;
            })
        );

        $this->assertSame(900000.0, (float) $loan->remaining_balance);
    }

    /** Validasi rentang tanggal di backend */
    public function test_invalid_date_range_is_rejected(): void
    {
        $response = $this->actingAs($this->user)->get(route('laporan.simpanan', [
            'period' => 'custom',
            'date_from' => $this->today(),
            'date_to' => $this->pastDate(10),
        ]));

        $response->assertSessionHasErrors(['date_to']);
    }
}
