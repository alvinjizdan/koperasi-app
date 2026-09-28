<?php

namespace Tests\Unit;

use App\Models\InstallmentSchedule;
use App\Services\Penalty\PenaltyCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ticket 15 — unit test perhitungan denda (data dummy).
 */
class PenaltyCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private PenaltyCalculator $calc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calc = new PenaltyCalculator;
    }

    private function schedule(array $overrides = []): InstallmentSchedule
    {
        return InstallmentSchedule::factory()->create(array_merge([
            'due_date' => '2026-09-01',
            'principal_amount' => 1_000_000,
            'interest_amount' => 0,
            'total_amount' => 1_000_000,
            'paid_amount' => 0,
            'remaining_amount' => 1_000_000,
            'status' => 'OVERDUE',
        ], $overrides));
    }

    public function test_none_method_accrues_nothing_by_default(): void
    {
        // Metode default 'none': denda TIDAK diterapkan otomatis (D.6/D.7).
        $result = $this->calc->accrueForSchedule($this->schedule(), '2026-10-15');

        $this->assertSame(0.0, $result['accrued']);
        $this->assertSame('none', $result['method']);
    }

    public function test_fixed_penalty_counts_overdue_periods(): void
    {
        config(['business-rules.penalty.method' => 'fixed']);
        config(['business-rules.penalty.fixed_amount' => 5000]);
        config(['business-rules.penalty.period_unit' => 'month']);
        config(['business-rules.penalty.grace_days' => 0]);

        // Jatuh tempo 1 Sep; referensi 15 Okt → periode Sep + Okt = 2.
        $result = $this->calc->accrueForSchedule($this->schedule(), '2026-10-15');

        $this->assertSame(10_000.0, $result['accrued']);
    }

    public function test_grace_period_defers_penalty_start(): void
    {
        config(['business-rules.penalty.method' => 'per_day']);
        config(['business-rules.penalty.amount_per_day' => 1000]);
        config(['business-rules.penalty.grace_days' => 5]);

        // Jatuh tempo 1 Sep + grace 5 hari → hitung mulai 6 Sep (5 hari di belakang).
        $result = $this->calc->accrueForSchedule($this->schedule(), '2026-09-10');

        // 6..10 Sep = 5 hari × 1000.
        $this->assertSame(5000.0, $result['accrued']);
    }

    public function test_per_day_penalty_only_counts_new_days_double_count_protection(): void
    {
        config(['business-rules.penalty.method' => 'per_day']);
        config(['business-rules.penalty.amount_per_day' => 1000]);
        config(['business-rules.penalty.grace_days' => 0]);

        $schedule = $this->schedule(['penalty_accrued_through' => '2026-09-10', 'penalty_accrued' => 10_000]);

        // Sudah diakui s.d. 10 Sep; 11..15 Sep hanya 5 hari baru × 1000.
        $result = $this->calc->accrueForSchedule($schedule, '2026-09-15');

        $this->assertSame(5000.0, $result['accrued']);
    }

    public function test_percent_penalty_uses_installment_base(): void
    {
        config(['business-rules.penalty.method' => 'percent']);
        config(['business-rules.penalty.percent_per_period' => 1.0]);
        config(['business-rules.penalty.period_unit' => 'month']);
        config(['business-rules.penalty.grace_days' => 0]);

        // 1% × 1.000.000 × 1 periode.
        $result = $this->calc->accrueForSchedule($this->schedule(), '2026-09-20');

        $this->assertSame(10_000.0, $result['accrued']);
    }

    public function test_paid_schedule_accrues_nothing(): void
    {
        config(['business-rules.penalty.method' => 'per_day']);
        config(['business-rules.penalty.amount_per_day' => 1000]);

        $result = $this->calc->accrueForSchedule($this->schedule(['remaining_amount' => 0]), '2026-10-01');

        $this->assertSame(0.0, $result['accrued']);
    }

    public function test_max_penalty_cap_applies(): void
    {
        config(['business-rules.penalty.method' => 'per_day']);
        config(['business-rules.penalty.amount_per_day' => 10_000]);
        config(['business-rules.penalty.max_amount_per_installment' => 50_000]);

        // 40 hari × 10.000 = 400.000 → dibatasi 50.000.
        $result = $this->calc->accrueForSchedule($this->schedule(), '2026-10-11');

        $this->assertSame(50_000.0, $result['accrued']);
    }

    public function test_before_due_date_accrues_nothing(): void
    {
        config(['business-rules.penalty.method' => 'per_day']);
        config(['business-rules.penalty.amount_per_day' => 1000]);

        $result = $this->calc->accrueForSchedule($this->schedule(), '2026-08-20');

        $this->assertSame(0.0, $result['accrued']);
    }
}
