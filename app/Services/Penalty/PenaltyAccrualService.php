<?php

namespace App\Services\Penalty;

use App\Models\InstallmentSchedule;
use App\Models\Loan;
use App\Services\AuditLogger;
use App\Support\DbTransaction;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * Integrasi akumulasi denda keterlambatan ke alur aplikasi (Ticket 23, LN-09/D-02).
 *
 * Mekanisme (bukan kebijakan): metode, tarif, grace period, dan batas tetap
 * berasal dari config/business-rules.php. Default `method=none` TIDAK diubah —
 * tanpa keputusan koperasi (D-02), tidak ada denda yang pernah diakrual, dan
 * tidak ada scheduler otomatis; eksekusi hanya via pemanggilan service yang sah
 * (mis. alur pembacaan jadwal / proses batch operator di masa depan).
 *
 * Invariant yang dijaga:
 * - Row lock jadwal (SELECT ... FOR UPDATE) sebelum baca-hitung-tulis → dua
 *   proses paralel untuk periode sama tidak menggandakan denda.
 * - `penalty_accrued_through` mencegah periode yang sama diakumulasi ulang.
 * - Jadwal LUNAS tidak memperoleh denda baru.
 * - Konfigurasi diubah TIDAK menulis ulang histori denda terakrual.
 * - Atomik: kegagalan tidak meninggalkan akumulasi parsial.
 */
class PenaltyAccrualService
{
    public function __construct(private PenaltyCalculator $calculator) {}

    /**
     * Akrual denda untuk satu jadwal pada tanggal referensi.
     *
     * @return array{accrued: float, through: ?string, method: string, schedule_id: int}
     *
     * @throws ValidationException bila jadwal sudah lunas (tidak boleh dikenai denda baru)
     */
    public function accrueForSchedule(InstallmentSchedule $schedule, ?string $referenceDate = null): array
    {
        return DbTransaction::run(function () use ($schedule, $referenceDate) {
            $locked = InstallmentSchedule::lockForUpdate()->findOrFail($schedule->id);

            if ($locked->status === 'PAID' || $locked->remaining_amount <= 0) {
                throw ValidationException::withMessages([
                    'schedule' => ['Angsuran sudah lunas; denda baru tidak dapat diakrual.'],
                ]);
            }

            $result = $this->calculator->accrueForSchedule($locked, $referenceDate);

            if ($result['accrued'] > 0.0) {
                $before = (float) $locked->penalty_accrued;
                $locked->penalty_accrued = (string) round($before + $result['accrued'], 2);
                $locked->penalty_accrued_through = $result['through'];
                $locked->save();

                AuditLogger::log(
                    'loan.penalty.accrued',
                    InstallmentSchedule::class,
                    $locked->id,
                    sprintf('Angsuran #%d pinjaman #%d', $locked->installment_number, $locked->loan_id),
                    sprintf(
                        'Denda diakrual Rp %s (total Rp %s, periode s.d. %s, metode %s).',
                        number_format($result['accrued'], 2, ',', '.'),
                        number_format((float) $locked->penalty_accrued, 2, ',', '.'),
                        $result['through'] ?? '-',
                        $result['method']
                    ),
                    [
                        'loan_id' => $locked->loan_id,
                        'installment_number' => $locked->installment_number,
                        'accrued_amount' => $result['accrued'],
                        'penalty_accrued_total' => (float) $locked->penalty_accrued,
                        'accrued_through' => $result['through'],
                        'method' => $result['method'],
                    ],
                );
            }

            return [
                'accrued' => $result['accrued'],
                'through' => $result['through'],
                'method' => $result['method'],
                'schedule_id' => $locked->id,
            ];
        });
    }

    /**
     * Akrual untuk seluruh jadwal menunggak pinjaman.
     *
     * Ticket 24 (C.5): kegagalan pada SATU jadwal tidak menelan jejak dan tidak
     * menggagalkan jadwal lain — setiap jadwal diisolasi; hasil membedakan
     * sukses (accrued>0), no-op (accrued=0), dan gagal (failed[] berisi ID + pesan).
     *
     * Idempoten: jadwal lunas dilewati, periode yang sama tidak dihitung ulang
     * (proteksi `penalty_accrued_through` di calculator + service).
     *
     * @return array{loan_id: int, total_accrued: float, schedules_processed: int, failed: array<int, array{schedule_id: int, reason: string}>}
     */
    public function accrueForLoan(Loan $loan, ?string $referenceDate = null): array
    {
        $today = $referenceDate ?? now()->format('Y-m-d');
        $totalAccrued = 0.0;
        $processed = 0;
        $failed = [];

        $schedules = $loan->schedules()
            ->whereIn('status', ['DUE', 'OVERDUE', 'PARTIALLY_PAID'])
            ->get();

        foreach ($schedules as $schedule) {
            try {
                $result = $this->accrueForSchedule($schedule->fresh(), $today);
                $totalAccrued = round($totalAccrued + $result['accrued'], 2);
                $processed++;
            } catch (ValidationException $e) {
                // Isolasi kegagalan: jejak tersimpan untuk observabilitas,
                // jadwal lain tetap diproses, tidak ada perubahan parsial
                // (setiap jadwal memiliki transaksi database sendiri).
                $failed[] = [
                    'schedule_id' => $schedule->id,
                    'reason' => $e->getMessage() !== '' ? implode('; ', Arr::flatten($e->errors())) : 'unknown',
                ];
            }
        }

        if ($failed !== []) {
            AuditLogger::log(
                'loan.penalty.accrual_failures',
                Loan::class,
                $loan->id,
                $loan->loan_number,
                sprintf('%d jadwal gagal diakrual pada pinjaman %s.', count($failed), $loan->loan_number),
                ['failed' => $failed, 'reference_date' => $today],
            );
        }

        return [
            'loan_id' => $loan->id,
            'total_accrued' => $totalAccrued,
            'schedules_processed' => $processed,
            'failed' => $failed,
        ];
    }

    /**
     * Akrual untuk semua pinjaman aktif — jalur batch EKSPLISIT (operator via
     * command), BUKAN scheduler otomatis. Idempoten dan aman dijalankan ulang.
     * Hasil membedakan total sukses, no-op, dan gagal per pinjaman.
     *
     * @return array{loans_processed: int, total_accrued: float, loans_with_failures: int, failed_schedule_count: int}
     */
    public function accrueAll(?string $referenceDate = null): array
    {
        $totalAccrued = 0.0;
        $loansProcessed = 0;
        $loansWithFailures = 0;
        $failedScheduleCount = 0;

        Loan::query()
            ->where('status', 'ACTIVE')
            ->orderBy('id')
            ->chunkById(100, function ($loans) use (&$totalAccrued, &$loansProcessed, &$loansWithFailures, &$failedScheduleCount, $referenceDate) {
                foreach ($loans as $loan) {
                    $result = $this->accrueForLoan($loan->fresh(), $referenceDate);
                    $totalAccrued = round($totalAccrued + $result['total_accrued'], 2);
                    $loansProcessed++;

                    if ($result['failed'] !== []) {
                        $loansWithFailures++;
                        $failedScheduleCount += count($result['failed']);
                    }
                }
            });

        return [
            'loans_processed' => $loansProcessed,
            'total_accrued' => $totalAccrued,
            'loans_with_failures' => $loansWithFailures,
            'failed_schedule_count' => $failedScheduleCount,
        ];
    }
}
