<?php

namespace Tests\Feature;

use App\Models\CashTransaction;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\LoanBalanceSnapshot;
use App\Models\User;
use App\Services\LoanService;
use App\Services\Snapshots\LoanBalanceSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Ticket 25 — snapshot posisi saldo pinjaman.
 *
 * Fokus: sumber data benar, idempotensi, integritas historis (tanggal posisi
 * vs waktu pengambilan), immutability, batas akuntansi, dan laporan posisi
 * yang jujur terhadap ketersediaan data.
 */
class LoanSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private LoanBalanceSnapshotService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->superadmin()->create();
        $this->service = app(LoanBalanceSnapshotService::class);
    }

    private function activeLoan(float $amount = 2_000_000, int $tenor = 2, ?float $partialPay = null): Loan
    {
        $loan = Loan::factory()->approved()->create(['amount' => $amount, 'tenor_months' => $tenor]);
        app(LoanService::class)->disburse($loan, [
            'disbursement_date' => now()->subMonths(2)->format('Y-m-d'),
            'first_due_date' => now()->addMonth()->format('Y-m-d'),
            'method' => 'TRANSFER',
        ], $this->user->id);

        if ($partialPay !== null) {
            $this->actingAs($this->user)->post(route('angsuran.pay.store', $loan->id), [
                'amount' => $partialPay,
                'payment_date' => now()->format('Y-m-d'),
            ]);
        }

        return $loan->fresh();
    }

    // =====================================================================
    // 1. Capture — sumber data & tanpa efek samping
    // =====================================================================

    public function test_snapshot_captures_components_from_authoritative_schedule_data(): void
    {
        $loan = $this->activeLoan(partialPay: 500_000);

        $result = $this->service->capture($loan, now()->format('Y-m-d'), $this->user->id);

        $this->assertTrue($result['created']);
        $snapshot = $result['snapshot'];

        // Komponen konsisten dengan total kewajiban model.
        $this->assertSame($loan->total_owed, (float) $snapshot->total_owed);
        $this->assertSame(
            round((float) $snapshot->outstanding_principal + (float) $snapshot->outstanding_interest + (float) $snapshot->outstanding_penalty, 2),
            (float) $snapshot->total_owed
        );
        $this->assertSame($loan->status, $snapshot->status);
        $this->assertSame(2, $snapshot->schedule_count);
        $this->assertNotNull($snapshot->total_paid);
        $this->assertSame(500_000.0, (float) $snapshot->total_paid);
    }

    public function test_snapshot_does_not_mutate_financial_data_or_trigger_accrual(): void
    {
        // Penalti AKTIF di config — snapshot tetap tidak boleh mengakrual.
        config(['business-rules.penalty.method' => 'per_day', 'business-rules.penalty.amount_per_day' => 1_000]);

        $loan = $this->activeLoan();
        $schedulesBefore = $loan->schedules()->get()->map(fn ($s) => $s->only(['id', 'penalty_accrued', 'paid_amount', 'remaining_amount', 'status']))->all();
        $cashBefore = CashTransaction::count();
        $journalsBefore = JournalEntry::count();

        $this->service->capture($loan, now()->format('Y-m-d'), $this->user->id);

        $this->assertSame($schedulesBefore, $loan->schedules()->get()->map(fn ($s) => $s->only(['id', 'penalty_accrued', 'paid_amount', 'remaining_amount', 'status']))->all());
        $this->assertSame($cashBefore, CashTransaction::count());
        $this->assertSame($journalsBefore, JournalEntry::count());
        $this->assertSame($loan->fresh()->total_owed, $loan->total_owed);
    }

    // =====================================================================
    // 2. Idempotensi & batch
    // =====================================================================

    public function test_duplicate_snapshot_for_same_context_is_idempotent(): void
    {
        $loan = $this->activeLoan();
        $date = now()->format('Y-m-d');

        $first = $this->service->capture($loan, $date, $this->user->id);
        $second = $this->service->capture($loan, $date, $this->user->id);

        $this->assertTrue($first['created']);
        $this->assertFalse($second['created']);
        $this->assertSame($first['snapshot']->id, $second['snapshot']->id);
        $this->assertSame(1, LoanBalanceSnapshot::where('loan_id', $loan->id)->count());
    }

    public function test_batch_captures_all_active_loans_and_re_runs_safely(): void
    {
        $loanA = $this->activeLoan();
        $loanB = $this->activeLoan(amount: 3_000_000);
        $date = now()->format('Y-m-d');

        $summary = $this->service->captureAll($date, $this->user->id);
        $this->assertSame(2, $summary['created']);
        $this->assertSame(0, $summary['failed']);

        $again = $this->service->captureAll($date, $this->user->id);
        $this->assertSame(0, $again['created']);
        $this->assertSame(2, $again['skipped']);
        $this->assertSame(2, LoanBalanceSnapshot::whereDate('as_of_date', $date)->count());
    }

    public function test_batch_skips_non_active_loans(): void
    {
        $this->activeLoan();
        $pending = Loan::factory()->create(['status' => 'PENDING']);

        $summary = $this->service->captureAll(now()->format('Y-m-d'), $this->user->id);

        $this->assertSame(1, $summary['created']);
        $this->assertSame(0, LoanBalanceSnapshot::where('loan_id', $pending->id)->count());
    }

    public function test_batch_failure_isolation(): void
    {
        $loanA = $this->activeLoan();
        $loanB = $this->activeLoan();

        // Rusak pinjaman A: pokok jadwal negatif → komponen (dibatasi 0 oleh
        // service) tidak konsisten dengan total_owed → akrual dibatalkan.
        DB::table('installment_schedules')->where('loan_id', $loanA->id)->update(['principal_amount' => -500_000]);

        $summary = $this->service->captureAll(now()->format('Y-m-d'), $this->user->id);

        $this->assertSame(1, $summary['failed']);
        $this->assertSame(1, $summary['created']); // pinjaman B tetap terproses
        $this->assertSame($loanA->id, $summary['failures'][0]['loan_id']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'loan.snapshot.failures']);
        $this->assertSame(1, LoanBalanceSnapshot::count());
    }

    // =====================================================================
    // 3. Integritas historis
    // =====================================================================

    public function test_backdated_snapshot_is_flagged_not_verified_historical(): void
    {
        $loan = $this->activeLoan();

        // Snapshot untuk tanggal lampau: dibuat HARI INI → bukan histori terverifikasi.
        $past = now()->subDays(10)->format('Y-m-d');
        $result = $this->service->capture($loan, $past, $this->user->id);

        $position = $this->service->positionFor($loan, $past);

        $this->assertTrue($position['available']);
        $this->assertTrue($position['captured_after_as_of']);
        $this->assertFalse($position['verified_historical']);
        $this->assertSame($past, $position['as_of_date']);
        $this->assertNotNull($position['captured_at']);
    }

    public function test_today_snapshot_is_verified(): void
    {
        $loan = $this->activeLoan();
        $today = now()->format('Y-m-d');

        $this->service->capture($loan, $today, $this->user->id);

        $position = $this->service->positionFor($loan, $today);
        $this->assertTrue($position['available']);
        $this->assertFalse($position['captured_after_as_of']);
        $this->assertTrue($position['verified_historical']);
    }

    public function test_missing_snapshot_reports_unavailable_without_fabrication(): void
    {
        $loan = $this->activeLoan();

        // Belum ada snapshot sama sekali.
        $position = $this->service->positionFor($loan, now()->format('Y-m-d'));
        $this->assertFalse($position['available']);
        $this->assertNull($position['nearest_as_of_date']);

        // Snapshot di tanggal lain → tanggal diminta tetap TIDAK tersedia;
        // laporan hanya menunjuk snapshot terdekat, TIDAK mencampur nilai.
        $this->service->capture($loan, now()->format('Y-m-d'), $this->user->id);
        $position = $this->service->positionFor($loan, now()->subDays(7)->format('Y-m-d'));
        $this->assertFalse($position['available']);
        $this->assertNotNull($position['nearest_as_of_date']);
        $this->assertArrayNotHasKey('total_owed', $position);
    }

    public function test_config_change_does_not_affect_stored_snapshots(): void
    {
        $loan = $this->activeLoan();
        $date = now()->format('Y-m-d');
        $this->service->capture($loan, $date, $this->user->id);
        $before = LoanBalanceSnapshot::where('loan_id', $loan->id)->first()->only(['total_owed', 'outstanding_principal', 'outstanding_interest']);

        // Konfigurasi penalti diubah setelah snapshot — snapshot tersimpan tidak berubah.
        config(['business-rules.penalty.method' => 'per_day', 'business-rules.penalty.amount_per_day' => 50_000]);

        $this->assertSame($before, LoanBalanceSnapshot::where('loan_id', $loan->id)->first()->only(['total_owed', 'outstanding_principal', 'outstanding_interest']));
    }

    // =====================================================================
    // 4. Immutability
    // =====================================================================

    public function test_snapshot_is_immutable_via_application_path(): void
    {
        $loan = $this->activeLoan();
        $snapshot = $this->service->capture($loan, now()->format('Y-m-d'), $this->user->id)['snapshot'];

        $this->expectException(HttpException::class);
        $snapshot->update(['total_owed' => 1.0]);
    }

    public function test_snapshot_deletion_is_blocked(): void
    {
        $loan = $this->activeLoan();
        $snapshot = $this->service->capture($loan, now()->format('Y-m-d'), $this->user->id)['snapshot'];

        $this->expectException(HttpException::class);
        $snapshot->delete();
    }

    // =====================================================================
    // 5. Command
    // =====================================================================

    public function test_command_captures_and_is_idempotent(): void
    {
        $loan = $this->activeLoan();

        $this->artisan('loan-snapshots:capture')->assertSuccessful();
        $this->assertSame(1, LoanBalanceSnapshot::count());

        $this->artisan('loan-snapshots:capture')->assertSuccessful();
        $this->assertSame(1, LoanBalanceSnapshot::count()); // idempoten

        $this->assertDatabaseHas('audit_logs', ['action' => 'loan.snapshot.captured']);
    }

    public function test_command_single_loan_and_dry_run(): void
    {
        $loanA = $this->activeLoan();
        $loanB = $this->activeLoan(amount: 3_000_000);

        // Dry-run: tidak menulis apa pun.
        $this->artisan('loan-snapshots:capture', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, LoanBalanceSnapshot::count());

        // Pinjaman tunggal.
        $this->artisan('loan-snapshots:capture', ['--loan' => $loanA->id])->assertSuccessful();
        $this->assertSame(1, LoanBalanceSnapshot::count());
        $this->assertSame(0, LoanBalanceSnapshot::where('loan_id', $loanB->id)->count());
    }

    // =====================================================================
    // 6. Batas akuntansi & autorisasi
    // =====================================================================

    public function test_snapshot_creates_no_journal_or_cash_entries_and_no_http_endpoint(): void
    {
        $loan = $this->activeLoan();
        $this->service->capture($loan, now()->format('Y-m-d'), $this->user->id);

        $this->assertSame(0, JournalEntry::count());
        $this->assertSame(0, CashTransaction::count());

        // Tidak ada route HTTP untuk snapshot — hanya CLI/service.
        $routes = collect(Route::getRoutes())->map(fn ($r) => $r->getName() ?? '');
        $this->assertSame([], $routes->filter(fn ($name) => str_contains($name, 'snapshot'))->values()->all());
    }
}
