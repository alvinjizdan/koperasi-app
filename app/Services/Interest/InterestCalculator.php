<?php

namespace App\Services\Interest;

use Illuminate\Validation\ValidationException;

/**
 * Layanan perhitungan bunga terpusat (Ticket 15 bagian C.1).
 *
 * Semua metode menghasilkan daftar komponen bunga per angsuran, dinormalisasi
 * sehingga total bunga = jumlah komponen (selisih pembulatan diserap angsuran
 * terakhir). Tidak ada metode bunga yang dihitung di Controller.
 *
 * Aturan bisnis berasal dari config/business-rules.php (PROVISIONAL DEFAULT).
 */
class InterestCalculator
{
    public const METHOD_NONE = 'none';

    public const METHOD_FLAT = 'flat';

    public const METHOD_EFFECTIVE = 'effective';

    public const METHOD_ANNUITY = 'annuity';

    /**
     * @return array{method: string, annual_rate: float, schedule: float[], total_interest: float}
     */
    public function buildSchedule(string $method, float $annualRate, float $principal, int $tenorMonths): array
    {
        $decimals = (int) config('business-rules.loan.rounding_decimals', 2);
        $tenorMonths = max(1, $tenorMonths);

        $schedule = match ($method) {
            self::METHOD_FLAT => $this->flat($principal, $annualRate, $tenorMonths),
            self::METHOD_EFFECTIVE => $this->effective($principal, $annualRate, $tenorMonths),
            self::METHOD_ANNUITY => $this->annuity($principal, $annualRate, $tenorMonths, $decimals),
            self::METHOD_NONE => array_fill(0, $tenorMonths, 0.0),
            default => throw new \InvalidArgumentException("Metode bunga tidak dikenal: {$method}"),
        };

        // Normalisasi pembulatan: selisih diserap angsuran terakhir.
        $rounded = array_map(fn ($v) => round($v, $decimals), $schedule);
        $rounded[$tenorMonths - 1] = round($rounded[$tenorMonths - 1] + (array_sum($schedule) - array_sum($rounded)), $decimals);

        return [
            'method' => $method,
            'annual_rate' => round($annualRate, 4),
            'schedule' => $rounded,
            'total_interest' => round(array_sum($rounded), $decimals),
        ];
    }

    /** Bunga flat: pokok awal × rate × tenor, dibagi rata. */
    private function flat(float $principal, float $annualRate, int $tenor): array
    {
        $total = $principal * ($annualRate / 100) * ($tenor / (int) config('business-rules.loan.months_per_year', 12));

        return array_fill(0, $tenor, $total / $tenor);
    }

    /** Bunga efektif: sisa pokok awal bulan × rate bulanan. */
    private function effective(float $principal, float $annualRate, int $tenor): array
    {
        $monthlyRate = $annualRate / 100 / (int) config('business-rules.loan.months_per_year', 12);
        $principalPerMonth = $principal / $tenor;
        $schedule = [];

        for ($i = 0; $i < $tenor; $i++) {
            $outstanding = $principal - ($principalPerMonth * $i);
            $schedule[] = $outstanding * $monthlyRate;
        }

        return $schedule;
    }

    /** Anuitas: angsuran (pokok+bunga) tetap; bunga bulan = sisa pokok × rate. */
    private function annuity(float $principal, float $annualRate, int $tenor, int $decimals): array
    {
        $monthlyRate = $annualRate / 100 / (int) config('business-rules.loan.months_per_year', 12);

        if ($monthlyRate <= 0.0) {
            return array_fill(0, $tenor, $principal / $tenor);
        }

        $factor = $monthlyRate * pow(1 + $monthlyRate, $tenor) / (pow(1 + $monthlyRate, $tenor) - 1);
        $installment = round($principal * $factor, $decimals);

        $schedule = [];
        $remaining = $principal;

        for ($i = 0; $i < $tenor; $i++) {
            $interest = $remaining * $monthlyRate;
            $principalPart = $installment - $interest;

            if ($i === $tenor - 1) {
                // Angsuran terakhir menyerap selisih agar pokok habis tepat.
                $principalPart = $remaining;
                $interest = $installment - $principalPart;
            }

            $schedule[] = $interest;
            $remaining = round($remaining - $principalPart, $decimals);
        }

        return $schedule;
    }

    /** Validasi konfigurasi bunga sebelum pencairan (C.5). */
    public function validateConfiguration(string $method, float $annualRate): void
    {
        $known = array_keys((array) config('business-rules.loan.interest_methods', []));

        if (! in_array($method, $known, true)) {
            throw ValidationException::withMessages([
                'interest_method' => ['Konfigurasi metode bunga tidak dikenal: '.$method.'. Lengkapi config/business-rules.php sebelum pencairan.'],
            ]);
        }

        if ($annualRate < 0) {
            throw ValidationException::withMessages([
                'interest_rate' => ['Suku bunga tidak boleh negatif. Perbaiki konfigurasi sebelum pencairan.'],
            ]);
        }
    }
}
