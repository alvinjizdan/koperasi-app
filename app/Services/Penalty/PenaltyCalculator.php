<?php

namespace App\Services\Penalty;

use App\Models\InstallmentSchedule;
use Illuminate\Support\Carbon;

/**
 * Layanan perhitungan denda terpusat (Ticket 15 bagian D).
 *
 * - Metode: none | fixed | percent | per_day (config/business-rules.php).
 * - Denda nol karena metode "none" BUKAN keputusan koperasi (D.7).
 * - Akumulasi dilindungi agar periode yang sama tidak dihitung ganda (D.4):
 *   denda hanya bertambah untuk hari/periode SETELAH penalty_accrued_through.
 */
class PenaltyCalculator
{
    /**
     * Hitung tambahan denda yang harus diakui untuk sebuah jadwal pada
     * tanggal referensi tertentu. Mengembalikan 0.0 bila konfigurasi
     * belum tersedia (metode none) atau belum ada keterlambatan baru.
     *
     * @return array{accrued: float, through: ?string, method: string}
     */
    public function accrueForSchedule(InstallmentSchedule $schedule, ?string $referenceDate = null): array
    {
        $method = (string) config('business-rules.penalty.method', 'none');
        $graceDays = (int) config('business-rules.penalty.grace_days', 0);
        $today = Carbon::parse($referenceDate ?? now()->format('Y-m-d'));

        $lastThrough = $schedule->penalty_accrued_through
            ? Carbon::parse($schedule->penalty_accrued_through)
            : null;

        // Tanggal mulai hitung: jatuh tempo + grace, atau hari setelah
        // periode yang sudah diakui (proteksi double-count).
        $effectiveStart = Carbon::parse($schedule->due_date)->addDays($graceDays);
        if ($lastThrough !== null) {
            $effectiveStart = $lastThrough->copy()->addDay()->max($effectiveStart);
        }

        // Kewajiban belum lunas & sudah lewat masa tenggang?
        $unpaid = (float) $schedule->remaining_amount;
        if ($unpaid <= 0.0 || ! $today->gt($effectiveStart->copy()->subDay())) {
            return ['accrued' => 0.0, 'through' => $lastThrough?->format('Y-m-d'), 'method' => $method];
        }

        // Hitung denda hanya untuk rentang (effectiveStart .. today).
        $base = (float) $schedule->total_amount;

        $penalty = match ($method) {
            'fixed' => $this->fixed($base, $effectiveStart, $today),
            'percent' => $this->percent($base, $effectiveStart, $today),
            'per_day' => $this->perDay($effectiveStart, $today),
            default => 0.0, // none: denda tidak diterapkan otomatis
        };

        $max = config('business-rules.penalty.max_amount_per_installment');
        if ($max !== null) {
            $penalty = min($penalty, (float) $max);
        }

        $decimals = (int) config('business-rules.loan.rounding_decimals', 2);
        $penalty = round($penalty, $decimals);

        $newThrough = $method === 'per_day' || $method === 'percent'
            ? $today->format('Y-m-d')
            : ($penalty > 0 ? $today->format('Y-m-d') : ($lastThrough?->format('Y-m-d')));

        return [
            'accrued' => $penalty,
            'through' => $newThrough,
            'method' => $method,
        ];
    }

    private function fixed(float $base, Carbon $from, Carbon $to): float
    {
        $amount = (float) config('business-rules.penalty.fixed_amount', 0);
        if ($amount <= 0) {
            return 0.0;
        }

        $unit = (string) config('business-rules.penalty.period_unit', 'month');
        $periods = $unit === 'month'
            ? (int) floor($from->diffInMonths($to)) + 1
            : (int) $from->diffInDays($to) + 1;

        return $amount * $periods;
    }

    private function percent(float $base, Carbon $from, Carbon $to): float
    {
        $rate = (float) config('business-rules.penalty.percent_per_period', 0);
        if ($rate <= 0) {
            return 0.0;
        }

        $unit = (string) config('business-rules.penalty.period_unit', 'month');
        $periods = $unit === 'month'
            ? (int) floor($from->diffInMonths($to)) + 1
            : (int) $from->diffInDays($to) + 1;

        return $base * ($rate / 100) * $periods;
    }

    private function perDay(Carbon $from, Carbon $to): float
    {
        $amount = (float) config('business-rules.penalty.amount_per_day', 0);
        if ($amount <= 0) {
            return 0.0;
        }

        $days = $from->diffInDays($to) + 1;

        return $amount * $days;
    }
}
