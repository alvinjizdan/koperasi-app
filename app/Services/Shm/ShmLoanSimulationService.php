<?php

namespace App\Services\Shm;

use App\Models\ShmLoan;
use App\Services\Interest\InterestCalculator;
use InvalidArgumentException;

/**
 * Ticket 64 — Simulasi Pinjaman SHM.
 *
 * Layanan ini TIDAK menentukan formula bisnis. Ia hanya:
 * 1. menerima input eksplisit pengguna (plafon, tenor, rate default legacy 2%,
 *    METODE, dan PERIODE rate — dua terakhir wajib dipilih eksplisit; simulasi
 *    adalah sandbox bebas dan sistem tidak pernah menebak metode/periode);
 * 2. memanggil `InterestCalculator` yang SUDAH ada sebagai satu-satunya
 *    mesin perhitungan bunga di aplikasi (rate koperasi tidak berubah);
 * 3. menyusun komponen pokok/jasa dari keluaran calculator menjadi tabel
 *    angsuran (No, Pokok, Jasa/Bunga, Total, Saldo).
 *
 * Konversi periode rate: calculator bekerja dengan rate TAHUNAN dan membagi
 * dengan `business-rules.loan.months_per_year`. Karena itu:
 * - periode 'yearly'  -> annual_rate = rate
 * - periode 'monthly' -> annual_rate = rate × months_per_year
 * Konversi satuan ini bukan formula bunga baru; ia hanya menempatkan rate
 * default legacy 2% pada periode yang dipilih pengguna.
 *
 * Biaya (administrasi, provisi, asuransi, blokir dana) dihitung dari input
 * manual pengguna, BUKAN dari matriks biaya resmi pencairan (T85) — simulasi
 * tetap sandbox bebas.
 */
class ShmLoanSimulationService
{
    public function __construct(private readonly InterestCalculator $interest) {}

    /**
     * Metode angsuran yang didukung mesin perhitungan existing.
     *
     * 'none' tidak ditawarkan pada simulasi karena simulasi selalu
     * menghitung jasa; metode tanpa bunga tetap tersedia di mesin calculator.
     *
     * @return array<string, string>
     */
    public static function supportedMethods(): array
    {
        return array_intersect_key(
            (array) config('business-rules.loan.interest_methods', []),
            array_flip(['flat', 'effective', 'annuity']),
        );
    }

    /**
     * Periode rate yang tersedia untuk dipilih pengguna.
     *
     * @return array<string, string>
     */
    public static function supportedPeriods(): array
    {
        return [
            'yearly' => 'Per tahun',
            'monthly' => 'Per bulan',
        ];
    }

    /**
     * Bangun hasil simulasi.
     *
     * @param  array{
     *     principal: float|int|string,
     *     tenor_months: int,
     *     rate_percent: float|int|string,
     *     method: string,
     *     rate_period: string,
     *     fees?: array{administration?: float, provision?: float, insurance?: float, other?: float, block_funds?: float},
     * }  $input
     * @return array{
     *     principal: float,
     *     tenor_months: int,
     *     rate_percent: float,
     *     rate_period: string,
     *     method: string,
     *     annual_rate: float,
     *     interest_schedule: array<int, float>,
     *     rows: array<int, array{period: int, principal: float, interest: float, total: float, balance: float}>,
     *     total_interest: float,
     *     total_obligation: float,
     *     installment_per_period: float,
     *     fees: array<string, float>,
     *     total_fees: float,
     *     block_funds: float,
     *     net_disbursement: float,
     * }
     */
    public function simulate(array $input): array
    {
        $method = (string) ($input['method'] ?? '');
        $period = (string) ($input['rate_period'] ?? '');

        if (! array_key_exists($method, self::supportedMethods())) {
            throw new InvalidArgumentException('Metode angsuran tidak dikenal: '.$method);
        }

        if (! array_key_exists($period, self::supportedPeriods())) {
            throw new InvalidArgumentException('Periode rate tidak dikenal: '.$period);
        }

        $principal = (float) $input['principal'];
        $tenor = max(1, (int) $input['tenor_months']);
        $rate = (float) ($input['rate_percent'] ?? self::officialRate());

        $monthsPerYear = max(1, (int) config('business-rules.loan.months_per_year', 12));
        $annualRate = $period === 'monthly' ? $rate * $monthsPerYear : $rate;

        $schedule = $this->interest->buildSchedule($method, $annualRate, $principal, $tenor);
        $interestSchedule = $schedule['schedule'];
        $totalInterest = $schedule['total_interest'];

        $rows = $this->buildRows($method, $principal, $tenor, $interestSchedule, $totalInterest);

        $fees = $this->normalizeFees($input['fees'] ?? []);
        $totalFees = $fees['administration'] + $fees['provision'] + $fees['insurance'] + $fees['other'];

        return [
            'principal' => round($principal, 2),
            'tenor_months' => $tenor,
            'rate_percent' => round($rate, 4),
            'rate_period' => $period,
            'method' => $method,
            'annual_rate' => (float) $schedule['annual_rate'],
            'interest_schedule' => $interestSchedule,
            'rows' => $rows,
            'total_interest' => round($totalInterest, 2),
            'total_obligation' => round($principal + $totalInterest, 2),
            'installment_per_period' => $rows === [] ? 0.0 : (float) $rows[0]['total'],
            'fees' => $fees,
            'total_fees' => round($totalFees, 2),
            'block_funds' => $fees['block_funds'],
            // Blokir dana BUKAN biaya: tidak dipotong dari pencairan karena
            // perlakukannya (penguncian/pengembalian) masih PENDING.
            'net_disbursement' => round($principal - $totalFees, 2),
        ];
    }

    /**
     * Susun tabel angsuran dari keluaran `InterestCalculator`.
     *
     * Calculator menghasilkan JASA per periode. Komponen pokok disusun
     * mengikuti semantik metode tersebut:
     * - flat & efektif: pokok dibagi rata (calculator membagi pokok secara
     *   linier), selisih pembulatan diserap periode terakhir;
     * - anuitas: angsuran total tetap, sehingga pokok = angsuran − jasa,
     *   dan periode terakhir menyerap selisih agar saldo tepat 0.
     *
     * Ini BUKAN formula bunga baru: tidak ada nilai bunga yang dihitung di
     * sini, seluruhnya berasal dari calculator.
     *
     * @param  array<int, float>  $interestSchedule
     * @return array<int, array{period: int, principal: float, interest: float, total: float, balance: float}>
     */
    private function buildRows(string $method, float $principal, int $tenor, array $interestSchedule, float $totalInterest): array
    {
        $decimals = (int) config('business-rules.loan.rounding_decimals', 2);
        $rows = [];
        $remaining = round($principal, $decimals);

        if ($method === 'annuity') {
            $installment = round(($principal + $totalInterest) / $tenor, $decimals);
        } else {
            $installment = null;
        }

        for ($i = 0; $i < $tenor; $i++) {
            $period = $i + 1;
            $interest = round((float) ($interestSchedule[$i] ?? 0.0), $decimals);

            if ($i === $tenor - 1) {
                $principalPart = $remaining;
                $total = $method === 'annuity'
                    ? round($remaining + $interest, $decimals)
                    : round($principalPart + $interest, $decimals);
            } elseif ($installment !== null) {
                $principalPart = round($installment - $interest, $decimals);
                $total = $installment;
            } else {
                $principalPart = round($principal / $tenor, $decimals);
                $total = round($principalPart + $interest, $decimals);
            }

            $remaining = round($remaining - $principalPart, $decimals);

            $rows[] = [
                'period' => $period,
                'principal' => $principalPart,
                'interest' => $interest,
                'total' => $total,
                'balance' => max(0.0, $remaining),
            ];
        }

        return $rows;
    }

    /**
     * Normalisasi input biaya manual (semua default 0).
     *
     * @param  array<string, mixed>  $fees
     * @return array{administration: float, provision: float, insurance: float, other: float, block_funds: float}
     */
    private function normalizeFees(array $fees): array
    {
        $value = fn (string $key): float => max(0.0, (float) ($fees[$key] ?? 0.0));

        return [
            'administration' => round($value('administration'), 2),
            'provision' => round($value('provision'), 2),
            'insurance' => round($value('insurance'), 2),
            'other' => round($value('other'), 2),
            'block_funds' => round($value('block_funds'), 2),
        ];
    }

    /**
     * Rate bunga/jasa resmi SHM (2%) sebagai business input default simulasi.
     */
    public static function officialRate(): float
    {
        return (float) ShmLoan::OFFICIAL_RATE_PERCENT;
    }
}
