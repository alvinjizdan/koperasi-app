<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CashTransaction;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\User;
use App\Services\LoanService;
use App\Services\Penalty\PenaltyAccrualService;
use App\Services\Penalty\PenaltyCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Ticket 24 — pemisahan pembacaan data dari mutasi finansial + jalur akrual
 * eksplisit (command) dengan dry-run, isolasi kegagalan, dan batas akuntansi.
 */
class AccrualExecutionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->superadmin()->create();
    }

    private function enablePerDay(float $perDay = 1_000): void
    {
        config([
            'business-rules.penalty.method' => 'per_day',
            'business-rules.penalty.amount_per_day' => $perDay,
        ]);
    }

    private function overdueLoan(int $daysLate = 9): Loan
    {
        $loan = Loan::factory()->approved()->create(['amount' => 2_000_000, 'tenor_months' => 2]);
        app(LoanService::class)->disburse($loan, [
            'disbursement_date' => now()->subMonths(2)->format('Y-m-d'),
            'first_due_date' => now()->subDays($daysLate)->format('Y-m-d'),
            'method' => 'TRANSFER',
        ], $this->user->id);

        $loan->schedules()->first()->update([
            'status' => 'OVERDUE',
            'due_date' => now()->subDays($daysLate)->format('Y-m-d'),
        ]);

        return $loan->fresh();
    }

    // =====================================================================
    // 1. Integritas read-only endpoint GET
    // =====================================================================

    public function test_schedule_page_does_not_accrue_penalty_or_write_data(): void
    {
        $this->enablePerDay(1_000); // denda AKTIF pada config — GET tetap tidak boleh menulis
        $loan = $this->overdueLoan();

        $scheduleBefore = $loan->schedules()->first()->only(['penalty_accrued', 'penalty_accrued_through', 'status', 'remaining_amount']);
        $auditCountBefore = AuditLog::count();

        $this->actingAs($this->user)
            ->get(route('angsuran.schedule', $loan->id))
            ->assertOk();

        $schedule = $loan->schedules()->first()->fresh();
        $this->assertSame((float) $scheduleBefore['penalty_accrued'], (float) $schedule->penalty_accrued);
        $this->assertNull($schedule->penalty_accrued_through);
        $this->assertSame(0, AuditLog::where('action', 'like', 'loan.penalty.%')->count());
        $this->assertSame($auditCountBefore, AuditLog::count());
    }

    public function test_overdue_report_page_does_not_accrue_penalty(): void
    {
        $this->enablePerDay(1_000);
        $loan = $this->overdueLoan();

        $this->actingAs($this->user)
            ->get(route('laporan.tunggakan'))
            ->assertOk();

        $this->assertSame(0.0, (float) $loan->schedules()->first()->fresh()->penalty_accrued);
        $this->assertSame(0, AuditLog::where('action', 'like', 'loan.penalty.%')->count());
    }

    public function test_repeated_page_refresh_does_not_change_obligations(): void
    {
        $loan = $this->overdueLoan();

        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($this->user)->get(route('angsuran.schedule', $loan->id))->assertOk();
        }

        $loan = $loan->fresh();
        $this->assertSame(0.0, round((float) $loan->schedules()->sum('penalty_accrued'), 2));
        $this->assertSame(0, AuditLog::where('action', 'like', 'loan.penalty.%')->count());
    }

    // =====================================================================
    // 2. Eksekusi eksplisit via command
    // =====================================================================

    public function test_command_accrues_penalty_and_is_idempotent(): void
    {
        $this->enablePerDay(1_000);
        $loan = $this->overdueLoan();

        $this->artisan('penalties:accrue')->assertSuccessful();
        $firstTotal = (float) $loan->schedules()->first()->fresh()->penalty_accrued;
        $this->assertSame(10_000.0, $firstTotal);

        // Eksekusi ulang: tidak ada akrual tambahan.
        $this->artisan('penalties:accrue')->assertSuccessful();
        $this->assertSame($firstTotal, (float) $loan->schedules()->first()->fresh()->penalty_accrued);

        // Jejak eksekusi tercatat.
        $this->assertDatabaseHas('audit_logs', ['action' => 'loan.penalty.accrual_executed']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'loan.penalty.accrued']);
    }

    public function test_command_with_none_method_is_noop(): void
    {
        // Default config: method=none — command tetap berjalan tanpa efek.
        $loan = $this->overdueLoan();

        $this->artisan('penalties:accrue')->assertSuccessful();

        $this->assertSame(0.0, (float) $loan->schedules()->first()->fresh()->penalty_accrued);
        $this->assertDatabaseHas('audit_logs', ['action' => 'loan.penalty.accrual_executed']);
    }

    public function test_command_dry_run_writes_nothing(): void
    {
        $this->enablePerDay(1_000);
        $loan = $this->overdueLoan();

        $this->artisan('penalties:accrue', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(0.0, (float) $loan->schedules()->first()->fresh()->penalty_accrued);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'loan.penalty.accrued']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'loan.penalty.accrual_executed']);
    }

    public function test_command_single_loan_option_only_processes_that_loan(): void
    {
        $this->enablePerDay(1_000);
        $loanA = $this->overdueLoan();
        $loanB = $this->overdueLoan();

        $this->artisan('penalties:accrue', ['--loan' => $loanA->id])->assertSuccessful();

        $this->assertSame(10_000.0, (float) $loanA->schedules()->first()->fresh()->penalty_accrued);
        $this->assertSame(0.0, (float) $loanB->schedules()->first()->fresh()->penalty_accrued);
    }

    // =====================================================================
    // 3. Batch & isolasi kegagalan
    // =====================================================================

    public function test_batch_processes_eligible_and_re_runs_safely(): void
    {
        $this->enablePerDay(1_000);
        $loan = $this->overdueLoan();
        // Jadwal kedua UPCOMING — tidak memenuhi syarat.
        $loan->schedules()->skip(1)->first()->update(['status' => 'UPCOMING']);

        $service = app(PenaltyAccrualService::class);
        $result = $service->accrueAll();
        $this->assertSame(1, $result['loans_processed']);
        $this->assertSame(10_000.0, $result['total_accrued']);
        $this->assertSame(0, $result['failed_schedule_count']);

        $again = $service->accrueAll();
        $this->assertSame(0.0, $again['total_accrued']); // idempoten
        $this->assertSame(10_000.0, (float) $loan->schedules()->first()->fresh()->penalty_accrued);
    }

    public function test_single_schedule_failure_does_not_block_other_schedules(): void
    {
        $this->enablePerDay(1_000);
        $loan = $this->overdueLoan();

        // Jadwal kedua dibuat menunggak; jadwal pertama dibuat GAGAL via mock:
        // accrueForSchedule pada jadwal pertama melempar ValidationException,
        // jadwal kedua tetap harus terproses (isolasi kegagalan per jadwal).
        $schedules = $loan->schedules()->orderBy('installment_number')->get();
        $schedules[1]->update(['status' => 'OVERDUE', 'due_date' => now()->subDays(5)->format('Y-m-d')]);

        $real = app(PenaltyAccrualService::class);
        $calculator = app(PenaltyCalculator::class);

        $mock = \Mockery::mock(PenaltyAccrualService::class, [$calculator])->makePartial();
        $mock->shouldReceive('accrueForSchedule')
            ->once()
            ->with(\Mockery::on(fn ($s) => $s->id === $schedules[0]->id), \Mockery::any())
            ->andThrow(ValidationException::withMessages(['schedule' => ['Simulasi kegagalan akrual.']]));
        $mock->shouldNotReceive('accrueForSchedule')->with(\Mockery::on(fn ($s) => $s->id === $schedules[1]->id));

        $this->instance(PenaltyAccrualService::class, $mock);

        $result = $mock->accrueForLoan($loan->fresh());

        $this->assertCount(1, $result['failed']);
        $this->assertSame($schedules[0]->id, $result['failed'][0]['schedule_id']);
        $this->assertGreaterThan(0.0, $result['total_accrued']); // jadwal kedua sukses
        $this->assertGreaterThan(0.0, (float) $schedules[1]->fresh()->penalty_accrued);
        // Kegagalan tercatat untuk observabilitas.
        $this->assertDatabaseHas('audit_logs', ['action' => 'loan.penalty.accrual_failures']);
    }
    // =====================================================================
    // 4. Batas akuntansi & integrasi sumber kas
    // =====================================================================

    public function test_accrual_never_creates_journal_entries_or_cash_transactions(): void
    {
        $this->enablePerDay(1_000);
        $loan = $this->overdueLoan();

        app(PenaltyAccrualService::class)->accrueAll();

        $this->assertSame(0, JournalEntry::count());
        $this->assertSame(0, CashTransaction::count());
        $this->assertGreaterThanOrEqual(10_000.0, (float) $loan->schedules()->first()->fresh()->penalty_accrued);
    }

    public function test_unauthorized_user_cannot_trigger_accrual_via_web(): void
    {
        // Tidak ada route web untuk akrual: satu-satunya jalur adalah command
        // CLI (operator server). Verifikasi tidak ada endpoint HTTP akrual.
        $routes = collect(Route::getRoutes())->map(fn ($r) => $r->getName() ?? '');
        $accrualRoutes = $routes->filter(fn ($name) => str_contains($name, 'accrual') || str_contains($name, 'penalty'));
        $this->assertSame([], $accrualRoutes->values()->all());
    }

    public function test_penalty_payment_flow_unchanged_after_command_accrual(): void
    {
        $this->enablePerDay(1_000);
        $loan = $this->overdueLoan();

        $this->artisan('penalties:accrue')->assertSuccessful();

        // Pembayaran menutup denda + angsuran pertama (urutan penalty→interest→principal).
        $schedule = $loan->schedules()->first()->fresh();
        $owed = $loan->fresh()->total_owed;
        $this->actingAs($this->user)->post(route('angsuran.pay.store', $loan->id), [
            'amount' => $owed,
            'payment_date' => now()->format('Y-m-d'),
        ]);

        $loan = $loan->fresh();
        $this->assertSame('PAID_OFF', $loan->status);
        $this->assertSame(round($schedule->penalty_accrued, 2), round((float) $loan->schedules()->first()->penalty_paid, 2));
    }
}
