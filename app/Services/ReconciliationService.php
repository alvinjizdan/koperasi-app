<?php

namespace App\Services;

use App\Models\ReconciliationFinding;
use App\Models\ReconciliationFollowUp;
use App\Models\ReconciliationRun;
use App\Services\Reconciliation\CashReconciliationChecker;
use App\Services\Reconciliation\LoanReconciliationChecker;
use App\Services\Reconciliation\SavingsReconciliationChecker;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Orkestrator proses rekonsiliasi.
 *
 * Proses hanya membaca data sumber (read-only) dan menyimpan temuan ke tabel
 * rekonsiliasi. Checkers dijalankan di luar transaksi DB (baca snapshot data
 * saat pemeriksaan), sedangkan persistensi run + temuan dilakukan dalam satu
 * transaksi agar tidak ada run tanpa temuan yang konsisten. Kegagalan checker
 * menandai status FAILED sehingga tidak ada status RUNNING tertinggal.
 */
class ReconciliationService
{
    public function __construct(
        private SavingsReconciliationChecker $savingsChecker,
        private LoanReconciliationChecker $loanChecker,
        private CashReconciliationChecker $cashChecker,
    ) {}

    /**
     * Jalankan proses rekonsiliasi untuk periode dan modul tertentu.
     *
     * @param  array<int, string>  $modules
     *
     * @throws ValidationException bila tidak ada modul valid yang dipilih
     */
    public function run(string $periodStart, string $periodEnd, array $modules, int $userId): ReconciliationRun
    {
        $validModules = array_values(array_intersect($modules, ReconciliationRun::MODULES));

        if ($validModules === []) {
            throw ValidationException::withMessages([
                'modules' => ['Pilih minimal satu modul yang valid untuk direkonsiliasi.'],
            ]);
        }

        /** @var ReconciliationRun $run */
        $run = ReconciliationRun::query()->create([
            'run_number' => ReconciliationRun::generateRunNumber(),
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'modules' => $validModules,
            'run_by' => $userId,
            'status' => ReconciliationRun::STATUS_RUNNING,
            'started_at' => now(),
        ]);

        try {
            $allFindings = [];
            $checkedRows = 0;
            $summaries = [];

            foreach ($validModules as $module) {
                $result = match ($module) {
                    ReconciliationRun::MODULE_SAVINGS => $this->savingsChecker->check($periodStart, $periodEnd),
                    ReconciliationRun::MODULE_LOANS => $this->loanChecker->check($periodStart, $periodEnd),
                    ReconciliationRun::MODULE_CASH => $this->cashChecker->check($periodStart, $periodEnd),
                    default => throw new \InvalidArgumentException("Modul tidak dikenal: {$module}"),
                };

                $allFindings = [...$allFindings, ...$result['findings']];
                $checkedRows += $result['checked_rows'];
                $summaries[] = ['module' => $module, ...$result['summary']];
            }

            $this->persistResults($run, $allFindings, $checkedRows, $summaries);

            AuditLogger::log(
                'reconciliation.run.completed',
                ReconciliationRun::class,
                $run->id,
                $run->run_number,
                sprintf(
                    'Rekonsiliasi %s selesai: %d baris diperiksa, %d temuan.',
                    implode(', ', $validModules),
                    $checkedRows,
                    count($allFindings)
                ),
                [
                    'run_number' => $run->run_number,
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'modules' => $validModules,
                    'checked_rows' => $checkedRows,
                    'findings_count' => count($allFindings),
                    'run_by' => $userId,
                ],
            );

            return $run->fresh();
        } catch (Throwable $e) {
            // Tandai gagal agar tidak ada status RUNNING yang tertinggal.
            $run->forceFill([
                'status' => ReconciliationRun::STATUS_FAILED,
                'error_message' => mb_substr($e->getMessage(), 0, 2000),
                'finished_at' => now(),
            ])->save();

            throw $e;
        }
    }

    /**
     * Simpan hasil pemeriksaan beserta temuan dalam satu transaksi.
     *
     * @param  array<int, array<string, mixed>>  $findings
     * @param  array<int, array<string, mixed>>  $summaries
     */
    private function persistResults(ReconciliationRun $run, array $findings, int $checkedRows, array $summaries): void
    {
        DB::transaction(function () use ($run, $findings, $checkedRows, $summaries) {
            $foundAt = now();

            foreach ($findings as $finding) {
                $run->findings()->create([
                    'finding_type' => $finding['finding_type'],
                    'module' => $finding['module'],
                    'object_type' => $finding['object_type'] ?? null,
                    'object_id' => $finding['object_id'] ?? null,
                    'object_label' => $finding['object_label'] ?? null,
                    'expected_value' => $finding['expected_value'],
                    'actual_value' => $finding['actual_value'],
                    'variance' => $finding['variance'],
                    'priority' => $finding['priority'],
                    'status' => ReconciliationFinding::STATUS_OPEN,
                    'found_at' => $foundAt,
                ]);
            }

            $run->update([
                'status' => ReconciliationRun::STATUS_COMPLETED,
                'checked_rows' => $checkedRows,
                'findings_count' => count($findings),
                'summary' => json_encode($summaries),
                'finished_at' => now(),
            ]);
        });
    }

    /**
     * Tindak lanjut temuan: ubah status dengan catatan wajib, catat riwayat,
     * dan audit trail. Temuan RESOLVED/ACCEPTED_WITH_REASON wajib memiliki
     * alasan memadai. Tidak pernah mengubah data sumber.
     *
     * @throws ValidationException bila status/catatan tidak valid
     */
    public function followUp(ReconciliationFinding $finding, string $toStatus, string $note, int $userId): ReconciliationFinding
    {
        $allowed = [
            ReconciliationFinding::STATUS_OPEN,
            ReconciliationFinding::STATUS_UNDER_REVIEW,
            ReconciliationFinding::STATUS_RESOLVED,
            ReconciliationFinding::STATUS_ACCEPTED_WITH_REASON,
        ];

        if (! in_array($toStatus, $allowed, true)) {
            throw ValidationException::withMessages([
                'to_status' => ['Status tindak lanjut tidak valid.'],
            ]);
        }

        $note = trim($note);

        if ($note === '') {
            throw ValidationException::withMessages([
                'note' => ['Catatan tindak lanjut wajib diisi.'],
            ]);
        }

        // Penyelesaian atau penerimaan dengan alasan wajib menjelaskan bukti/ alasannya.
        if (in_array($toStatus, [ReconciliationFinding::STATUS_RESOLVED, ReconciliationFinding::STATUS_ACCEPTED_WITH_REASON], true)
            && mb_strlen($note) < 10) {
            throw ValidationException::withMessages([
                'note' => ['Penyelesaian atau penerimaan temuan wajib disertai alasan/bukti yang memadai.'],
            ]);
        }

        return DB::transaction(function () use ($finding, $toStatus, $note, $userId) {
            $locked = ReconciliationFinding::lockForUpdate()->findOrFail($finding->id);

            if ($locked->status === $toStatus) {
                throw ValidationException::withMessages([
                    'to_status' => ['Temuan sudah berada pada status tersebut.'],
                ]);
            }

            $fromStatus = $locked->status;

            ReconciliationFollowUp::query()->create([
                'reconciliation_finding_id' => $locked->id,
                'user_id' => $userId,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'note' => $note,
            ]);

            $locked->update(['status' => $toStatus]);

            AuditLogger::log(
                'reconciliation.finding.follow_up',
                ReconciliationFinding::class,
                $locked->id,
                $locked->object_label,
                sprintf('Status temuan %s diubah dari %s menjadi %s.', $locked->id, $fromStatus, $toStatus),
                [
                    'finding_type' => $locked->finding_type,
                    'module' => $locked->module,
                    'from_status' => $fromStatus,
                    'to_status' => $toStatus,
                    'note' => $note,
                ]
            );

            return $locked->fresh();
        });
    }
}
