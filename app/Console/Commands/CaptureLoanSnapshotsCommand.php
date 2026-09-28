<?php

namespace App\Console\Commands;

use App\Models\Loan;
use App\Models\LoanBalanceSnapshot;
use App\Models\User;
use App\Services\Snapshots\LoanBalanceSnapshotService;
use Illuminate\Console\Command;

/**
 * Ticket 25 — jalur eksekusi snapshot posisi pinjaman yang EKSPLISIT.
 *
 * Tidak ada scheduler/cron: eksekusi hanya via pemanggilan manual operator
 * yang sah (server CLI). Snapshot murni pembacaan + penulisan snapshot —
 * tidak pernah mengubah saldo/kewajiban finansial dan tidak memicu akrual.
 */
class CaptureLoanSnapshotsCommand extends Command
{
    protected $signature = 'loan-snapshots:capture
                            {--loan= : ID pinjaman tertentu (opsional; kosong = semua pinjaman ACTIVE)}
                            {--date= : Tanggal posisi snapshot Y-m-d (default: hari ini)}
                            {--dry-run : Tampilkan ringkasan tanpa menulis snapshot}';

    protected $description = 'Ambil snapshot posisi saldo pinjaman per tanggal posisi (eksekusi eksplisit, tanpa scheduler, tanpa efek finansial).';

    public function handle(LoanBalanceSnapshotService $service): int
    {
        $date = $this->option('date');
        $asOfDate = is_string($date) && $date !== '' ? $date : now()->format('Y-m-d');

        $loanId = $this->option('loan');

        if ($this->option('dry-run')) {
            return $this->dryRun($asOfDate, is_numeric($loanId) ? (int) $loanId : null);
        }

        // CLI berjalan tanpa sesi auth; gunakan superadmin pertama sebagai aktor
        // tercatat (FK wajib valid) — eksekusi tetap teraudit.
        $actorId = (int) (auth()->id()
            ?? User::query()->whereHas('roles', fn ($q) => $q->where('roles.name', 'superadmin'))->orderBy('id')->value('id')
            ?? User::query()->orderBy('id')->value('id')
            ?? 0);

        if (is_numeric($loanId)) {
            $result = $service->capture(Loan::findOrFail((int) $loanId), $asOfDate, $actorId);
            $this->info(sprintf(
                'Pinjaman #%d: snapshot %s per %s (total kewajiban Rp %s).',
                $result['snapshot']->loan_id,
                $result['created'] ? 'DIBUAT' : 'SUDAH ADA (idempoten)',
                $result['snapshot']->as_of_date,
                number_format((float) $result['snapshot']->total_owed, 2, ',', '.'),
            ));

            return self::SUCCESS;
        }

        $summary = $service->captureAll($asOfDate, $actorId);

        $this->info(sprintf(
            'Snapshot dibuat: %d | dilewati (sudah ada): %d | gagal: %d | total kewajiban terekam: Rp %s',
            $summary['created'],
            $summary['skipped'],
            $summary['failed'],
            number_format($summary['total_owed'], 2, ',', '.'),
        ));

        foreach ($summary['failures'] as $failure) {
            $this->warn(sprintf('Gagal pinjaman #%d: %s', $failure['loan_id'], $failure['reason']));
        }

        return $summary['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function dryRun(string $asOfDate, ?int $loanId): int
    {
        $query = Loan::query()->where('status', 'ACTIVE')->orderBy('id');

        if ($loanId !== null) {
            $query->where('id', $loanId);
        }

        $rows = [];
        $existing = 0;

        foreach ($query->get() as $loan) {
            $already = LoanBalanceSnapshot::query()
                ->where('loan_id', $loan->id)
                ->where('as_of_date', $asOfDate)
                ->exists();

            if ($already) {
                $existing++;

                continue;
            }

            $rows[] = [$loan->id, $loan->loan_number, $loan->status, number_format((float) $loan->total_owed, 2, ',', '.')];
        }

        if ($rows === []) {
            $this->info('Tidak ada pinjaman ACTIVE baru untuk snapshot (atau semua sudah tersnapshot).');

            return self::SUCCESS;
        }

        $this->table(['ID', 'No. Pinjaman', 'Status', 'Total Kewajiban (estimasi)'], $rows);
        $this->info(sprintf('DRY RUN — tidak ada snapshot ditulis. Kandidat baru: %d | sudah tersnapshot: %d', count($rows), $existing));

        return self::SUCCESS;
    }
}
