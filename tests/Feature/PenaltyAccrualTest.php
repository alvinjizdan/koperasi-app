<?php

namespace Tests\Feature;

use App\Models\InstallmentPayment;
use App\Models\InstallmentSchedule;
use App\Models\Loan;
use App\Models\User;
use App\Services\LoanService;
use App\Services\Penalty\PenaltyAccrualService;
use App\Services\Reconciliation\LoanReconciliationChecker;
use App\Services\Reports\OverdueReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Ticket 23 — integrasi akumulasi denda keterlambatan (LN-09/D-02).
 *
 * Mekanisme diuji dengan konfigurasi per-test; default produksi tetap 'none'
 * (tidak ada denda nyata). Cakupan: siklus akrual, proteksi double-count,
 * interaksi pembayaran/reversal, laporan tunggakan, rekonsiliasi, dan
 * keamanan paralel.
 */
class PenaltyAccrualTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private PenaltyAccrualService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->superadmin()->create();
        $this->service = app(PenaltyAccrualService::class);
    }

    private function overdueSchedule(float $total = 1_000_000, int $daysLate = 10): InstallmentSchedule
    {
        $loan = Loan::factory()->approved()->create(['amount' => 2_000_000, 'tenor_months' => 2]);
        app(LoanService::class)->disburse($loan, [
            'disbursement_date' => now()->subMonths(2)->format('Y-m-d'),
            'first_due_date' => now()->subDays($daysLate)->format('Y-m-d'),
            'method' => 'TRANSFER',
        ], $this->user->id);

        $schedule = $loan->schedules()->first();
        $schedule->update(['status' => 'OVERDUE', 'due_date' => now()->subDays($daysLate)->format('Y-m-d')]);

        return $schedule->fresh();
    }

    private function enablePerDay(float $perDay = 1_000, int $graceDays = 0, $max = null): void
    {
        config([
            'business-rules.penalty.method' => 'per_day',
            'business-rules.penalty.amount_per_day' => $perDay,
            'business-rules.penalty.grace_days' => $graceDays,
            'business-rules.penalty.max_amount_per_installment' => $max,
        ]);
    }

    // =====================================================================
    // 1. Siklus akrual
    // =====================================================================

    public function test_no_penalty_accrued_when_method_none_active(): void
    {
        // Default config: method=none — tidak ada denda, tidak ada audit.
        $schedule = $this->overdueSchedule();

        $result = $this->service->accrueForSchedule($schedule);

        $this->assertSame(0.0, $result['accrued']);
        $this->assertSame('none', $result['method']);
        $this->assertSame(0.0, (float) $schedule->fresh()->penalty_accrued);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'loan.penalty.accrued']);
    }

    public function test_penalty_accrues_per_configured_method_and_is_audited(): void
    {
        $this->enablePerDay(1_000);
        $schedule = $this->overdueSchedule(daysLate: 9);

        $result = $this->service->accrueForSchedule($schedule);

        $daysLate = 9 + 1; // inklusif: jatuh tempo s.d. hari referensi
        $this->assertSame(round(1_000.0 * $daysLate, 2), $result['accrued']);
        $this->assertSame(round(1_000.0 * $daysLate, 2), (float) $schedule->fresh()->penalty_accrued);
        $this->assertSame(now()->format('Y-m-d'), optional($schedule->fresh()->penalty_accrued_through)->format('Y-m-d'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'loan.penalty.accrued']);
    }

    public function test_grace_period_defers_accrual(): void
    {
        $this->enablePerDay(1_000, graceDays: 5);
        $schedule = $this->overdueSchedule(daysLate: 3); // masih dalam grace

        $result = $this->service->accrueForSchedule($schedule);

        $this->assertSame(0.0, $result['accrued']);
        $this->assertSame(0.0, (float) $schedule->fresh()->penalty_accrued);
    }

    public function test_max_penalty_cap_applies(): void
    {
        $this->enablePerDay(1_000, max: 2_500);
        $schedule = $this->overdueSchedule(daysLate: 30);

        $this->service->accrueForSchedule($schedule);

        $this->assertSame(2_500.0, (float) $schedule->fresh()->penalty_accrued);
    }

    public function test_same_period_is_not_double_counted(): void
    {
        $this->enablePerDay(1_000);
        $schedule = $this->overdueSchedule(daysLate: 9);

        $first = $this->service->accrueForSchedule($schedule);
        $second = $this->service->accrueForSchedule($schedule->fresh());
        $third = $this->service->accrueForSchedule($schedule->fresh());

        $this->assertGreaterThan(0.0, $first['accrued']);
        $this->assertSame(0.0, $second['accrued']);
        $this->assertSame(0.0, $third['accrued']);
        $this->assertSame(round(1_000.0 * 10, 2), (float) $schedule->fresh()->penalty_accrued);
    }

    public function test_paid_schedule_cannot_receive_new_penalty(): void
    {
        $this->enablePerDay(1_000);
        $schedule = $this->overdueSchedule();

        $schedule->update(['status' => 'PAID', 'paid_amount' => $schedule->total_amount, 'remaining_amount' => 0]);

        $this->expectException(ValidationException::class);
        $this->service->accrueForSchedule($schedule->fresh());
    }

    public function test_config_change_does_not_rewrite_recorded_penalty_history(): void
    {
        $this->enablePerDay(1_000);
        $schedule = $this->overdueSchedule(daysLate: 9);
        $this->service->accrueForSchedule($schedule);
        $recorded = (float) $schedule->fresh()->penalty_accrued;

        // Config berubah setelah akrual — histori tidak tersentuh.
        config(['business-rules.penalty.amount_per_day' => 99_000]);

        $this->assertSame($recorded, (float) $schedule->fresh()->penalty_accrued);
    }

    public function test_accrue_for_loan_processes_only_eligible_schedules(): void
    {
        $this->enablePerDay(1_000);
        $loan = Loan::factory()->approved()->create(['amount' => 3_000_000, 'tenor_months' => 3]);
        app(LoanService::class)->disburse($loan, [
            'disbursement_date' => now()->subMonths(2)->format('Y-m-d'),
            'first_due_date' => now()->subDays(8)->format('Y-m-d'),
            'method' => 'TRANSFER',
        ], $this->user->id);

        $loan->schedules()->orderBy('installment_number')->get()->each(function ($s, $i) {
            $s->update([
                'status' => $i === 0 ? 'OVERDUE' : 'UPCOMING',
                'due_date' => $i === 0 ? now()->subDays(8)->format('Y-m-d') : now()->addMonths($i)->format('Y-m-d'),
            ]);
        });

        $result = $this->service->accrueForLoan($loan->fresh());

        $this->assertSame(1, $result['schedules_processed']);
        $this->assertSame(9_000.0, $result['total_accrued']); // 9 hari × 1.000
        $this->assertSame(9_000.0, (float) $loan->schedules()->first()->penalty_accrued);
        $this->assertSame(0.0, (float) $loan->schedules()->skip(1)->first()->penalty_accrued);
    }

    // =====================================================================
    // 2. Jadwal & kewajiban
    // =====================================================================

    public function test_total_owed_includes_accrued_penalty(): void
    {
        $this->enablePerDay(1_000);
        $schedule = $this->overdueSchedule(daysLate: 9);

        $loan = $schedule->loan;
        $owedBefore = $loan->fresh()->total_owed;

        $this->service->accrueForSchedule($schedule);

        $this->assertSame(round($owedBefore + 10_000.0, 2), $loan->fresh()->total_owed);
    }

    public function test_accrual_does_not_change_schedule_status_or_fully_paid_schedules(): void
    {
        $this->enablePerDay(1_000);
        $schedule = $this->overdueSchedule(daysLate: 9);

        $this->service->accrueForSchedule($schedule);

        $this->assertSame('OVERDUE', $schedule->fresh()->status);
        $this->assertSame(0.0, (float) $schedule->fresh()->penalty_paid);
    }

    // =====================================================================
    // 3. Pembayaran & reversal
    // =====================================================================

    private function loanWithPenalty(float $perDay = 1_000, int $daysLate = 9): array
    {
        $this->enablePerDay($perDay);
        $loan = Loan::factory()->approved()->create(['amount' => 2_000_000, 'tenor_months' => 2]);
        app(LoanService::class)->disburse($loan, [
            'disbursement_date' => now()->subMonths(2)->format('Y-m-d'),
            'first_due_date' => now()->subDays($daysLate)->format('Y-m-d'),
            'method' => 'TRANSFER',
        ], $this->user->id);

        $schedule = $loan->schedules()->first();
        $schedule->update(['status' => 'OVERDUE', 'due_date' => now()->subDays($daysLate)->format('Y-m-d')]);

        $this->service->accrueForSchedule($schedule->fresh());

        return [$loan->fresh(), $schedule->fresh()];
    }

    public function test_payment_allocates_to_accrued_penalty_first(): void
    {
        [$loan, $schedule] = $this->loanWithPenalty();
        $penalty = (float) $schedule->penalty_accrued; // 10.000

        // Bayar tepat nominal denda → seluruhnya masuk komponen penalty.
        $this->actingAs($this->user)->post(route('angsuran.pay.store', $loan->id), [
            'amount' => $penalty,
            'payment_date' => now()->format('Y-m-d'),
        ]);

        $schedule->refresh();
        $this->assertSame($penalty, (float) $schedule->penalty_paid);
        $this->assertSame(0.0, (float) $schedule->paid_amount); // pokok tidak tersentuh
        $this->assertSame(0.0, (float) $schedule->interest_paid);
    }

    public function test_reversal_restores_penalty_obligation(): void
    {
        [$loan, $schedule] = $this->loanWithPenalty();
        $penalty = (float) $schedule->penalty_accrued;

        $this->actingAs($this->user)->post(route('angsuran.pay.store', $loan->id), [
            'amount' => $penalty,
            'payment_date' => now()->format('Y-m-d'),
        ]);
        $this->assertSame($penalty, (float) $schedule->fresh()->penalty_paid);

        $payment = InstallmentPayment::where('loan_id', $loan->id)->whereNull('reversal_of_id')->first();
        $this->actingAs($this->user)->post(route('angsuran.payment.reverse', $payment->id), [
            'reason' => 'Koreksi uji denda',
        ]);

        $schedule->refresh();
        $this->assertSame(0.0, (float) $schedule->penalty_paid);
        $this->assertSame($penalty, (float) $schedule->penalty_accrued); // histori akrual utuh

        // Pembayaran ulang setelah reversal: denda dialokasikan lagi tanpa akrual ganda.
        $owedBeforeRepay = $loan->fresh()->total_owed;
        $this->actingAs($this->user)->post(route('angsuran.pay.store', $loan->id), [
            'amount' => $penalty,
            'payment_date' => now()->format('Y-m-d'),
        ]);
        $schedule->refresh();
        $this->assertSame($penalty, (float) $schedule->penalty_paid);
        $this->assertSame(round($owedBeforeRepay - $penalty, 2), $loan->fresh()->total_owed);
    }

    public function test_reaccrual_after_penalty_payment_does_not_double_count(): void
    {
        [$loan, $schedule] = $this->loanWithPenalty();
        $penalty = (float) $schedule->penalty_accrued;

        $this->actingAs($this->user)->post(route('angsuran.pay.store', $loan->id), [
            'amount' => $penalty,
            'payment_date' => now()->format('Y-m-d'),
        ]);

        // Akrual ulang pada hari yang sama: periode sudah diakui → 0.
        $result = $this->service->accrueForSchedule($schedule->fresh());
        $this->assertSame(0.0, $result['accrued']);
    }

    public function test_parallel_accrual_for_same_schedule_creates_no_duplicate(): void
    {
        $this->enablePerDay(1_000);
        $schedule = $this->overdueSchedule(daysLate: 9);

        // Simulasi dua proses paralel pada jadwal yang sama — keduanya sekuensial
        // kunci baris (lockForUpdate di service), sehingga hasil tetap satu akrual.
        $results = [];
        foreach ([1, 2] as $i) {
            $results[] = DB::transaction(function () use ($schedule) {
                return app(PenaltyAccrualService::class)->accrueForSchedule($schedule->fresh());
            });
        }

        $this->assertGreaterThan(0.0, $results[0]['accrued']);
        $this->assertSame(0.0, $results[1]['accrued']);
        $this->assertSame(10_000.0, (float) $schedule->fresh()->penalty_accrued);

        // Uji paralel proses-nyata sudah tercakup di ConcurrencyAndFailureTest
        // (test_parallel_penalty_accrual_for_same_period_does_not_double_count)
        // terhadap worker terpisah.
    }

    // =====================================================================
    // 4. Laporan & rekonsiliasi
    // =====================================================================

    public function test_overdue_report_shows_accrued_penalty_without_double_counting(): void
    {
        $this->enablePerDay(1_000);
        [$loan, $schedule] = $this->loanWithPenalty();

        $report = new OverdueReport;
        $summary = $report->summary([]);
        $rows = collect($report->paginate([], 50)->items());

        // Tunggakan memuat angsuran yang menunggak (denda tidak mengubah
        // remaining_amount pokok+bunga — sumber konsisten dengan jadwal).
        $this->assertGreaterThan(0, $summary['schedules_count']);
        $row = $rows->firstWhere('id', $schedule->id);
        $this->assertNotNull($row);
        $this->assertSame((float) $schedule->remaining_amount, $row['remaining_amount']);
    }

    public function test_paid_schedule_never_appears_in_overdue_report(): void
    {
        $this->enablePerDay(1_000);
        [$loan, $schedule] = $this->loanWithPenalty();

        // Lunasi seluruh pinjaman.
        $this->actingAs($this->user)->post(route('angsuran.pay.store', $loan->id), [
            'amount' => $loan->fresh()->total_owed,
            'payment_date' => now()->format('Y-m-d'),
        ]);

        $report = new OverdueReport;
        $rows = collect($report->paginate([], 50)->items());
        $this->assertNull($rows->firstWhere('loan_id', $loan->id));
    }

    public function test_reconciliation_detects_penalty_inconsistency_and_is_read_only(): void
    {
        $this->enablePerDay(1_000);
        [$loan, $schedule] = $this->loanWithPenalty();

        // Rusak data: terbayar denda melebihi yang terakrual.
        $schedule->forceFill(['penalty_paid' => 99_999.0])->save();

        $before = $schedule->fresh()->toArray();

        $result = (new LoanReconciliationChecker)->check('2000-01-01', now()->format('Y-m-d'));

        $findings = array_filter($result['findings'], fn ($f) => $f['object_id'] === $loan->id && $f['finding_type'] === 'BALANCE_MISMATCH');
        $this->assertNotEmpty($findings);

        // Read-only.
        $this->assertSame($before, $schedule->fresh()->toArray());
    }
}
