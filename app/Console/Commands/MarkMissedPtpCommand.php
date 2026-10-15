<?php

namespace App\Console\Commands;

use App\Services\Shm\ShmCollectionPtpService;
use Illuminate\Console\Command;

/**
 * Ticket 99 — sweep Promise to Pay: OPEN melewati promised_date → MISSED.
 *
 * - Berjalan di konteks CLI/server (dijadwalkan harian di routes/console.php,
 *   bisa juga dipanggil manual operator) — TIDAK bergantung pada user
 *   membuka halaman, tanpa endpoint HTTP baru.
 * - Idempoten: hanya PTP OPEN dengan promised_date < hari ini yang
 *   diproses (row lock + re-check di service); putaran ulang tidak
 *   menggandakan audit/transisi.
 * - Actor = system: audit memakai actor_id null (kolom nullable) + trigger
 *   'scheduler' — TIDAK membuat/memalsukan user.
 * - Setelah memproses MISSED, service mengevaluasi eskalasi case: 3 PTP
 *   MISSED dalam periode anchored yang sama → ESCALATED (graph guard
 *   tetap di service case).
 * - --dry-run: lapor jumlah kandidat tanpa menulis apa pun.
 */
class MarkMissedPtpCommand extends Command
{
    protected $signature = 'collection:ptp-missed
                            {--dry-run : Laporkan kandidat tanpa menulis apa pun}';

    protected $description = 'Tandai PTP OPEN yang melewati promised_date sebagai MISSED dan evaluasi eskalasi Collection Case (idempoten, tanpa user session).';

    public function handle(ShmCollectionPtpService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $summary = $service->markOverduePromisesMissed($dryRun);

        if ($summary['scanned'] === 0) {
            $this->info('Tidak ada PTP OPEN yang melewati promised_date.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info(sprintf(
                '[DRY RUN] Kandidat PTP lewat promised_date: %d — tidak ada yang ditulis.',
                $summary['scanned'],
            ));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'PTP diproses: %d | menjadi MISSED: %d | case naik ESCALATED: %d.',
            $summary['scanned'],
            $summary['missed'],
            $summary['escalated'],
        ));

        return self::SUCCESS;
    }
}
