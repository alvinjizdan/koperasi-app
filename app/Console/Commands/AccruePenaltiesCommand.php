<?php

namespace App\Console\Commands;

use App\Models\Loan;
use App\Services\AuditLogger;
use App\Services\Penalty\PenaltyAccrualService;
use App\Services\Penalty\PenaltyCalculator;
use Illuminate\Console\Command;

/**
 * Ticket 24 — jalur eksekusi akrual denda yang EKSPLISIT (LN-09/D-02).
 *
 * - Tidak ada scheduler/cron: eksekusi hanya via pemanggilan manual operator
 *   yang sah (server CLI), memenuhi C.3/C.4 tanpa endpoint publik baru.
 * - Aman terhadap method=none: no-op penuh; tanpa keputusan D-02 tidak ada
 *   denda yang pernah diakrual.
 * - --dry-run: hitung tanpa menulis — untuk verifikasi operator sebelum
 *   eksekusi nyata; hasil dilaporkan, tidak ada perubahan database.
 * - Idempoten: eksekusi ulang tidak menggandakan akrual (proteksi
 *   penalty_accrued_through + row lock di service).
 * - Kegagalan per jadwal terisolasi dan teraudit; ringkasan membedakan
 *   sukses / no-op / gagal.
 */
class AccruePenaltiesCommand extends Command
{
    protected $signature = 'penalties:accrue
                            {--loan= : ID pinjaman tertentu (opsional; kosong = semua pinjaman ACTIVE)}
                            {--date= : Tanggal referensi akrual Y-m-d (default: hari ini)}
                            {--dry-run : Hitung tanpa menulis ke database}';

    protected $description = 'Akrual denda keterlambatan angsuran (eksekusi eksplisit, tanpa scheduler). Tanpa keputusan D-02, konfigurasi none membuat perintah ini no-op.';

    public function handle(PenaltyAccrualService $service): int
    {
        $date = $this->option('date');
        $referenceDate = is_string($date) && $date !== '' ? $date : now()->format('Y-m-d');

        if ($this->option('dry-run')) {
            return $this->dryRun($service, $referenceDate);
        }

        $loanId = $this->option('loan');

        if (is_numeric($loanId)) {
            $result = $service->accrueForLoan(Loan::findOrFail((int) $loanId), $referenceDate);
            $this->renderLoanResult($result);
            $summary = [
                'loans_processed' => 1,
                'total_accrued' => $result['total_accrued'],
                'loans_with_failures' => $result['failed'] !== [] ? 1 : 0,
                'failed_schedule_count' => count($result['failed']),
            ];
        } else {
            $summary = $service->accrueAll($referenceDate);
            $this->info(sprintf(
                'Pinjaman diproses: %d | Total denda diakrual: Rp %s | Pinjaman dengan kegagalan: %d | Jadwal gagal: %d',
                $summary['loans_processed'],
                number_format($summary['total_accrued'], 2, ',', '.'),
                $summary['loans_with_failures'],
                $summary['failed_schedule_count'],
            ));
        }

        AuditLogger::log(
            'loan.penalty.accrual_executed',
            Loan::class,
            null,
            'penalties:accrue',
            sprintf(
                'Akrual denda dieksekusi (tanggal referensi %s): total Rp %s, %d jadwal gagal.',
                $referenceDate,
                number_format($summary['total_accrued'], 2, ',', '.'),
                $summary['failed_schedule_count'],
            ),
            $summary + ['reference_date' => $referenceDate, 'dry_run' => false],
        );

        // Kegagalan terisolasi dilaporkan; eksekusi tetap dianggap selesai,
        // namun exit code memberi sinyal untuk perhatian operator.
        return $summary['failed_schedule_count'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** Pratinjau tanpa menulis: tampilkan jadwal yang memenuhi syarat & estimasi. */
    private function dryRun(PenaltyAccrualService $service, string $referenceDate): int
    {
        $calculator = app(PenaltyCalculator::class);

        $loans = Loan::query()->where('status', 'ACTIVE')->orderBy('id')->get();
        $total = 0.0;
        $eligible = 0;
        $rows = [];

        foreach ($loans as $loan) {
            $loan->load(['schedules' => fn ($q) => $q->whereIn('status', ['DUE', 'OVERDUE', 'PARTIALLY_PAID'])]);

            foreach ($loan->schedules as $schedule) {
                $estimate = $calculator->accrueForSchedule($schedule, $referenceDate);

                if ($estimate['accrued'] > 0.0) {
                    $eligible++;
                    $total = round($total + $estimate['accrued'], 2);
                    $rows[] = [$loan->loan_number, $schedule->installment_number, $schedule->due_date->toDateString(), $estimate['method'], number_format($estimate['accrued'], 2, ',', '.')];
                }
            }
        }

        if ($rows === []) {
            $this->info('Tidak ada jadwal yang memenuhi syarat untuk akrual (atau method=none).');

            return self::SUCCESS;
        }

        $this->table(['Pinjaman', 'Angsuran', 'Jatuh Tempo', 'Metode', 'Estimasi Denda'], $rows);
        $this->info(sprintf('DRY RUN — tidak ada data ditulis. Jadwal: %d | Total estimasi: Rp %s', $eligible, number_format($total, 2, ',', '.')));

        return self::SUCCESS;
    }

    /**
     * @param  array{loan_id: int, total_accrued: float, schedules_processed: int, failed: array<int, array{schedule_id: int, reason: string}>}  $result
     */
    private function renderLoanResult(array $result): void
    {
        $this->info(sprintf(
            'Pinjaman #%d: total diakrual Rp %s dari %d jadwal.',
            $result['loan_id'],
            number_format($result['total_accrued'], 2, ',', '.'),
            $result['schedules_processed'],
        ));

        foreach ($result['failed'] as $failure) {
            $this->warn(sprintf('Gagal jadwal #%d: %s', $failure['schedule_id'], $failure['reason']));
        }
    }
}
