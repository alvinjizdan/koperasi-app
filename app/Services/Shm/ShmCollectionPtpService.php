<?php

namespace App\Services\Shm;

use App\Models\ShmCollectionCase;
use App\Models\ShmCollectionPtp;
use App\Services\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Service record Promise to Pay (PTP) SHM (Ticket 97; aturan T99).
 *
 * Satu-satunya jalur pembuatan & mutasi status PTP (domain layer — bukan
 * dari frontend). Aturan mengikuti contract T91 Rule 8 yang dikonkretkan
 * T99 (`config/shm-business-rule-contract.php` → `promise_to_pay`) dan
 * baseline aman T97:
 *
 * - Record WAJIB melekat pada Collection Case eksisting, dan case WAJIB
 *   berstatus PROMISE_TO_PAY — pencatatan PTP tidak pernah mengubah status
 *   case (transisi IN_PROGRESS → PROMISE_TO_PAY tetap melalui
 *   `ShmCollectionCaseService::transition()`; jalur pembuatan tidak
 *   menyentuh lifecycle case).
 * - Otorisasi mutation manual = Marketing PEMILIK case secara eksplisit
 *   (pola T92/T93; tanpa fallback admin/`shm.view_all` — actor selalu user
 *   session yang diteruskan controller, tidak pernah dari payload).
 * - Kelayakan T99: loan masih eligible Collection (isCandidate) DAN
 *   overdue minimal 7 hari yang dihitung dari due_date angsuran NYATA
 *   (hasOverdueAtLeast) — bukan created_at/umur case/tanggal frontend.
 * - Kuota T99: maksimal 3 PTP per periode bulanan berlabuh (anchor =
 *   tanggal PTP pertama pada case); SEMUA status ikut terhitung; validasi
 *   gagal tidak menghitung. quotaState() = single source untuk guard,
 *   payload UI, dan evaluasi scheduler.
 * - SATU PTP OPEN per case: guard row lock + partial unique index di
 *   database. PTP terminal lama TIDAK memblokir record baru selama kuota
 *   periode masih tersedia (riwayat multiple PTP per attempt terjaga).
 * - Status: OPEN → FULFILLED | MISSED | CANCELLED (ketiganya terminal,
 *   tanpa reopen). Jalur manual tetap aksi eksplisit pemilik; MISSED juga
 *   dihasilkan scheduler `collection:ptp-missed` (T99) ketika promised_date
 *   terlewat — idempoten, actor system (audit actor_id null + trigger
 *   'scheduler', tanpa user palsu). Pembayaran TIDAK PERNAH membuat
 *   FULFILLED (payment-to-PTP matching tetap PENDING BUSINESS
 *   CONFIRMATION).
 * - Eskalasi T99: HANYA dipicu dari sweep scheduler setelah PTP berpindah
 *   ke MISSED, dan HANYA bila 3 PTP MISSED berada dalam periode anchored
 *   yang sama — melalui `ShmCollectionCaseService::escalateFromScheduler()`
 *   (graph guard + idempotensi tetap berlaku). Pencatatan/transisi manual
 *   tidak pernah mengeskalasi case.
 * - Tanpa efek samping finansial pada pembuatan: tidak pernah membuat
 *   payment/alokasi/settlement, tidak menyentuh saldo atau status pinjaman,
 *   tidak mengubah status case saat record dibuat.
 * - Audit transactional via AuditLogger existing: `shm.collection.ptp.created`
 *   dan `shm.collection.ptp.status_changed`; kegagalan audit MEMBATALKAN
 *   mutasi (satu transaksi).
 *
 * YANG SENGAJA TIDAK DIIMPLEMENTASIKAN (PENDING BUSINESS CONFIRMATION):
 * grace period, automatic FULFILLED/payment-to-PTP matching, penalty,
 * reminder/notifikasi, timing eskalasi di luar aturan 3 MISSED, collector.
 */
class ShmCollectionPtpService
{
    public function __construct(
        private readonly ShmCollectionQueryService $collections,
        private readonly ShmCollectionCaseService $cases,
    ) {}

    /**
     * Catat satu PTP pada case yang berstatus PROMISE_TO_PAY.
     *
     * Urutan guard: case eksisting (row lock) → otorisasi pemilik → status
     * case PROMISE_TO_PAY → kelayakan T99 (eligible + overdue ≥ 7 hari dari
     * due_date nyata) → tanggal terisi → nominal > 0 → TIDAK ada PTP OPEN →
     * kuota periode berlabuh (3) → persist (status OPEN) → audit.
     * Seluruhnya SATU transaksi: validasi yang gagal tidak pernah menulis
     * baris sehingga tidak menghitung kuota.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws ValidationException bila case tidak ada, actor bukan pemilik,
     *                             status case salah, pinjaman tidak layak
     *                             (bukan kandidat / overdue < 7 hari), tanggal
     *                             kosong, nominal tidak positif, PTP OPEN
     *                             sudah ada, atau kuota periode penuh
     */
    public function create(ShmCollectionCase $case, int $actorId, array $payload): ShmCollectionPtp
    {
        return DB::transaction(function () use ($case, $actorId, $payload): ShmCollectionPtp {
            // Case WAJIB masih ada; row lock menyerialisasi guard duplikat
            // OPEN sehingga balapan dua request menjadi ValidationException
            // yang jelas, bukan QueryException dari partial unique index.
            $current = ShmCollectionCase::query()->lockForUpdate()->findOrFail($case->getKey());

            $this->guardOwner((int) $current->marketing_id, $actorId, sprintf(
                'Hanya Marketing pemilik Collection Case #%d yang boleh mencatat PTP pada case ini.',
                $current->getKey(),
            ));

            if ($current->status !== ShmCollectionCase::STATUS_PROMISE_TO_PAY) {
                throw ValidationException::withMessages([
                    'collection.ptp.status' => sprintf(
                        'Case harus berstatus PROMISE_TO_PAY sebelum PTP dapat dicatat (status saat ini: %s). Ubah status lewat workflow Collection terlebih dahulu — pembuatan PTP tidak mengubah status case otomatis.',
                        $current->status,
                    ),
                ]);
            }

            // Kelayakan T99: predikat dari pinjaman NYATA (due_date angsuran
            // + status kandidat Collection) — bukan created_at/umur case/
            // tanggal frontend.
            $eligibility = $this->eligibility($current);

            if (! $eligibility['eligible']) {
                throw ValidationException::withMessages([
                    'collection.ptp.kelayakan' => (string) $eligibility['reason'],
                ]);
            }

            $promisedDate = trim((string) ($payload['promised_date'] ?? ''));

            if ($promisedDate === '') {
                throw ValidationException::withMessages([
                    'collection.ptp.tanggal' => 'Tanggal janji pembayaran wajib diisi.',
                ]);
            }

            $promisedAmount = round((float) ($payload['promised_amount'] ?? 0), 2);

            if ($promisedAmount <= 0) {
                throw ValidationException::withMessages([
                    'collection.ptp.nominal' => 'Nominal janji pembayaran harus lebih dari 0.',
                ]);
            }

            $hasOpen = ShmCollectionPtp::query()
                ->where('shm_collection_case_id', $current->getKey())
                ->where('status', ShmCollectionPtp::STATUS_OPEN)
                ->exists();

            if ($hasOpen) {
                throw ValidationException::withMessages([
                    'collection.ptp.duplikat' => 'Masih ada PTP aktif (OPEN) pada case ini. Tandai PTP tersebut Terpenuhi, Tidak Dipenuhi, atau Batalkan terlebih dahulu sebelum mencatat PTP baru.',
                ]);
            }

            // Kuota T99: maksimal 3 PTP per periode bulanan berlabuh —
            // dihitung dari baris tersimpan (semua status) pada periode yang
            // sama; record gagal validasi tidak pernah sampai ke sini.
            $quota = $this->quotaState($current);

            if (! $quota['available']) {
                throw ValidationException::withMessages([
                    'collection.ptp.kuota' => sprintf(
                        'Kuota PTP periode berlabuh %s s/d %s sudah penuh (%d/%d) — seluruh status PTP ikut terhitung. PTP baru dapat dicatat pada periode berikutnya mulai %s.',
                        (string) $quota['period_start'],
                        (string) $quota['period_end'],
                        $quota['used'],
                        $quota['limit'],
                        $quota['period_end'] !== null
                            ? Carbon::parse((string) $quota['period_end'])->addDay()->toDateString()
                            : '-',
                    ),
                ]);
            }

            $ptp = ShmCollectionPtp::query()->create([
                'shm_collection_case_id' => $current->getKey(),
                'created_by' => $actorId,
                'promised_date' => $promisedDate,
                'promised_amount' => $promisedAmount,
                'status' => ShmCollectionPtp::STATUS_OPEN,
                'notes' => $payload['notes'] ?? null,
            ]);

            $loanNumber = $current->loan()->value('loan_number');

            AuditLogger::log(
                'shm.collection.ptp.created',
                ShmCollectionPtp::class,
                $ptp->id,
                $loanNumber !== null ? sprintf('%s / PTP #%d', $loanNumber, $ptp->id) : null,
                sprintf(
                    'Promise to Pay (janji %s, nominal %s) dicatat untuk Collection Case #%d pinjaman %s.',
                    $ptp->promised_date->toDateString(),
                    number_format($promisedAmount, 2, '.', ''),
                    $current->getKey(),
                    $loanNumber ?? ('#'.$current->shm_loan_id),
                ),
                [
                    'shm_collection_ptp_id' => $ptp->id,
                    'shm_collection_case_id' => $current->getKey(),
                    'shm_loan_id' => $current->shm_loan_id,
                    'actor_id' => $actorId,
                    'promised_date' => $ptp->promised_date->toDateString(),
                    'promised_amount' => $promisedAmount,
                    'status' => ShmCollectionPtp::STATUS_OPEN,
                    'notes' => $ptp->notes,
                ],
            );

            return $ptp;
        });
    }

    /**
     * Ubah status PTP (mutasi manual eksplisit oleh pemilik case).
     *
     * Urutan guard: PTP eksisting (row lock) → otorisasi pemilik case induk
     * → graph `ALLOWED_TRANSITIONS` (hanya OPEN → terminal) → persist
     * → audit. Seluruhnya SATU transaksi.
     *
     * TIDAK ada pembacaan tanggal maupun pembayaran pada jalur ini: MISSED
     * manual = pernyataan eksplisit pemilik; deteksi otomatis `promised_date`
     * terlewat ada di scheduler `collection:ptp-missed` (T99). Transisi manual
     * TIDAK dapat membypass kuota/kelayakan (keduanya dijaga saat pembuatan
     * record) dan TIDAK pernah mengeskalasi case.
     *
     * @throws ValidationException bila actor bukan pemilik case induk,
     *                             target di luar graph, atau PTP terminal
     */
    public function transition(ShmCollectionPtp $ptp, string $toStatus, int $actorId): ShmCollectionPtp
    {
        return DB::transaction(function () use ($ptp, $toStatus, $actorId): ShmCollectionPtp {
            $current = ShmCollectionPtp::query()->lockForUpdate()->findOrFail($ptp->getKey());
            $case = ShmCollectionCase::query()->findOrFail($current->shm_collection_case_id);

            $this->guardOwner((int) $case->marketing_id, $actorId, sprintf(
                'Hanya Marketing pemilik Collection Case #%d yang boleh mengubah status PTP ini.',
                $case->getKey(),
            ));

            $allowed = ShmCollectionPtp::ALLOWED_TRANSITIONS[$current->status] ?? [];

            if (! in_array($toStatus, $allowed, true)) {
                throw ValidationException::withMessages([
                    'collection.ptp.status' => in_array($current->status, ShmCollectionPtp::TERMINAL_STATUSES, true)
                        ? sprintf('PTP #%d sudah berstatus %s (terminal) dan tidak dapat diubah lagi.', $current->getKey(), $current->status)
                        : sprintf(
                            'Transisi status PTP "%s" → "%s" tidak diizinkan. Transisi yang diizinkan dari OPEN: %s.',
                            $current->status,
                            $toStatus,
                            implode(', ', $allowed),
                        ),
                ]);
            }

            $previousStatus = $current->status;
            $current->status = $toStatus;
            $current->save();

            $loanNumber = $case->loan()->value('loan_number');

            AuditLogger::log(
                'shm.collection.ptp.status_changed',
                ShmCollectionPtp::class,
                $current->getKey(),
                $loanNumber !== null ? sprintf('%s / PTP #%d', $loanNumber, $current->getKey()) : null,
                sprintf(
                    'Status PTP #%d Collection Case #%d diubah dari %s menjadi %s (manual).',
                    $current->getKey(),
                    $case->getKey(),
                    $previousStatus,
                    $toStatus,
                ),
                [
                    'shm_collection_ptp_id' => $current->getKey(),
                    'shm_collection_case_id' => $case->getKey(),
                    'shm_loan_id' => $case->shm_loan_id,
                    'actor_id' => $actorId,
                    'previous_status' => $previousStatus,
                    'status' => $toStatus,
                    'promised_date' => $current->promised_date->toDateString(),
                    'promised_amount' => round((float) $current->promised_amount, 2),
                ],
            );

            return $current;
        });
    }

    /**
     * Kelayakan pencatatan PTP sebuah case (T99 rule 1–3) — SATU sumber
     * untuk guard create() maupun payload detail (alasan identik).
     *
     * @return array{eligible: bool, reason: string|null, overdue_min_days: int}
     */
    public function eligibility(ShmCollectionCase $case): array
    {
        $minDays = $this->overdueMinDays();
        $loan = $case->loan;

        if ($loan === null || ! $this->collections->isCandidate($loan)) {
            return [
                'eligible' => false,
                'reason' => 'Pinjaman tidak lagi memenuhi kelayakan Collection (status, pencairan, atau tunggakannya berubah) — PTP tidak dapat dicatat.',
                'overdue_min_days' => $minDays,
            ];
        }

        if (! $this->collections->hasOverdueAtLeast($loan, $minDays)) {
            return [
                'eligible' => false,
                'reason' => sprintf(
                    'PTP hanya dapat dicatat setelah tunggakan mencapai minimal %d hari (jatuh tempo angsuran nyata paling lambat %s).',
                    $minDays,
                    today()->subDays($minDays)->toDateString(),
                ),
                'overdue_min_days' => $minDays,
            ];
        }

        return ['eligible' => true, 'reason' => null, 'overdue_min_days' => $minDays];
    }

    /**
     * Kuota PTP periode berlabuh sebuah case (T99 rule 5–14) — SATU sumber
     * untuk guard create(), payload UI (x/3 + periode), dan scheduler.
     *
     * Anchor = tanggal pembuatan PTP pertama pada case; `used` = jumlah baris
     * tersimpan dengan created_at dalam periode yang BERISI HARI INI (semua
     * status ikut terhitung). Belum ada PTP → tanpa periode, used 0 (PTP
     * pertama membuka periode berlabuhnya sendiri).
     *
     * @return array{anchor: string|null, period_start: string|null, period_end: string|null, period_index: int|null, used: int, limit: int, remaining: int, available: bool}
     */
    public function quotaState(ShmCollectionCase $case): array
    {
        $limit = $this->quotaLimit();

        $firstCreatedAt = ShmCollectionPtp::query()
            ->where('shm_collection_case_id', $case->getKey())
            ->min('created_at');

        $anchor = $firstCreatedAt !== null ? Carbon::parse((string) $firstCreatedAt)->startOfDay() : null;
        $period = ShmCollectionPtp::quotaPeriodFor($anchor, today());

        if ($period === null) {
            return [
                'anchor' => null,
                'period_start' => null,
                'period_end' => null,
                'period_index' => null,
                'used' => 0,
                'limit' => $limit,
                'remaining' => $limit,
                'available' => $limit > 0,
            ];
        }

        $used = (int) ShmCollectionPtp::query()
            ->where('shm_collection_case_id', $case->getKey())
            ->whereBetween('created_at', [
                $period['start']->copy()->startOfDay(),
                $period['end']->copy()->endOfDay(),
            ])
            ->count();

        return [
            'anchor' => $anchor->toDateString(),
            'period_start' => $period['start']->toDateString(),
            'period_end' => $period['end']->toDateString(),
            'period_index' => $period['index'],
            'used' => $used,
            'limit' => $limit,
            'remaining' => max(0, $limit - $used),
            'available' => $used < $limit,
        ];
    }

    /**
     * Sweep scheduler T99: PTP OPEN dengan promised_date < hari ini → MISSED,
     * lalu evaluasi eskalasi case terkait (3 MISSED dalam periode anchored
     * yang sama → ESCALATED).
     *
     * Idempoten: transisi hanya terjadi pada baris yang masih OPEN di bawah
     * row lock — putaran berikutnya tidak menemukan kandidat, sehingga audit
     * dan transisi tidak pernah terganda. Actor = system (Auth::id() null di
     * konteks CLI; kolom audit_logs.actor_id nullable) — TIDAK ada user
     * dibuat/dipalsukan. Berjalan sepenuhnya di server (tidak bergantung pada
     * user membuka halaman).
     *
     * @param  bool  $dryRun  Pratinjau: hitung kandidat tanpa menulis apa pun.
     * @return array{scanned: int, missed: int, escalated: int}
     */
    public function markOverduePromisesMissed(bool $dryRun = false): array
    {
        $summary = ['scanned' => 0, 'missed' => 0, 'escalated' => 0];

        ShmCollectionPtp::query()
            ->where('status', ShmCollectionPtp::STATUS_OPEN)
            ->where('promised_date', '<', today()->toDateString())
            ->chunkById(100, function ($ptps) use (&$summary, $dryRun): void {
                foreach ($ptps as $ptp) {
                    $summary['scanned']++;

                    if ($dryRun || ! $this->markMissed($ptp)) {
                        continue;
                    }

                    $summary['missed']++;

                    if ($this->escalateCaseFor($ptp)) {
                        $summary['escalated']++;
                    }
                }
            });

        return $summary;
    }

    /**
     * Transisi satu PTP OPEN → MISSED oleh scheduler (T99 rule 15).
     *
     * @return bool true bila transisi benar-benar terjadi; false bila baris
     *              sudah diproses putaran lain / berubah status (no-op tanpa
     *              audit ganda)
     */
    private function markMissed(ShmCollectionPtp $ptp): bool
    {
        return DB::transaction(function () use ($ptp): bool {
            $locked = ShmCollectionPtp::query()->lockForUpdate()->find($ptp->getKey());

            // Re-check di bawah lock: putaran paralel yang lebih dulu
            // memproses baris sama membuat putaran ini no-op.
            if ($locked === null
                || $locked->status !== ShmCollectionPtp::STATUS_OPEN
                || ! $locked->promised_date->lt(today())) {
                return false;
            }

            $case = ShmCollectionCase::query()->findOrFail($locked->shm_collection_case_id);

            $locked->status = ShmCollectionPtp::STATUS_MISSED;
            $locked->save();

            $loanNumber = $case->loan()->value('loan_number');

            AuditLogger::log(
                'shm.collection.ptp.status_changed',
                ShmCollectionPtp::class,
                $locked->getKey(),
                $loanNumber !== null ? sprintf('%s / PTP #%d', $loanNumber, $locked->getKey()) : null,
                sprintf(
                    'Status PTP #%d Collection Case #%d diubah dari OPEN menjadi MISSED (scheduler — promised_date %s terlewat).',
                    $locked->getKey(),
                    $case->getKey(),
                    $locked->promised_date->toDateString(),
                ),
                [
                    'shm_collection_ptp_id' => $locked->getKey(),
                    'shm_collection_case_id' => $case->getKey(),
                    'shm_loan_id' => $case->shm_loan_id,
                    'actor_id' => null,
                    'trigger' => 'scheduler',
                    'previous_status' => ShmCollectionPtp::STATUS_OPEN,
                    'status' => ShmCollectionPtp::STATUS_MISSED,
                    'promised_date' => $locked->promised_date->toDateString(),
                    'promised_amount' => round((float) $locked->promised_amount, 2),
                ],
            );

            return true;
        });
    }

    /**
     * Evaluasi eskalasi setelah scheduler memproses satu MISSED (T99
     * rule 16–18).
     *
     * ESCALATED hanya bila SEMUA terpenuhi: case belum RESOLVED/ESCALATED,
     * pinjaman masih eligible Collection, dan terdapat >= threshold (3) PTP
     * berstatus MISSED dalam periode anchored YANG SAMA dengan PTP yang baru
     * diproses. Tiga pengajuan saja tanpa MISSED, FULFILLED, maupun
     * CANCELLED tidak pernah mengeskalasi; case RESOLVED/ESCALATED dilewati
     * tanpa audit (idempoten).
     *
     * @return bool true bila case naik ke ESCALATED pada putaran ini
     */
    private function escalateCaseFor(ShmCollectionPtp $processed): bool
    {
        $case = ShmCollectionCase::query()->find($processed->shm_collection_case_id);

        if ($case === null
            || $case->status === ShmCollectionCase::STATUS_RESOLVED
            || $case->status === ShmCollectionCase::STATUS_ESCALATED) {
            return false;
        }

        $anchor = ShmCollectionPtp::query()
            ->where('shm_collection_case_id', $case->getKey())
            ->min('created_at');

        $period = ShmCollectionPtp::quotaPeriodFor(
            $anchor !== null ? Carbon::parse((string) $anchor)->startOfDay() : null,
            $processed->created_at ?? today(),
        );

        if ($period === null) {
            return false;
        }

        $missedInPeriod = (int) ShmCollectionPtp::query()
            ->where('shm_collection_case_id', $case->getKey())
            ->where('status', ShmCollectionPtp::STATUS_MISSED)
            ->whereBetween('created_at', [
                $period['start']->copy()->startOfDay(),
                $period['end']->copy()->endOfDay(),
            ])
            ->count();

        if ($missedInPeriod < $this->missedThreshold()) {
            return false;
        }

        $loan = $case->loan;

        if ($loan === null || ! $this->collections->isCandidate($loan)) {
            return false;
        }

        $fromStatus = $case->status;
        $after = $this->cases->escalateFromScheduler($case, sprintf(
            '%d PTP MISSED dalam periode anchored %s s/d %s.',
            $missedInPeriod,
            $period['start']->toDateString(),
            $period['end']->toDateString(),
        ));

        return $fromStatus !== ShmCollectionCase::STATUS_ESCALATED
            && $after->status === ShmCollectionCase::STATUS_ESCALATED;
    }

    /**
     * Ambang overdue minimal PTP (hari) dari contract T99 — config adalah
     * sumber nilai; test pinning menjamin angkanya (7).
     */
    private function overdueMinDays(): int
    {
        return (int) config('shm-business-rule-contract.collection_workflow.promise_to_pay.eligibility.overdue_min_days');
    }

    /** Batas PTP per periode berlabuh dari contract T99 (test pinning: 3). */
    private function quotaLimit(): int
    {
        return (int) config('shm-business-rule-contract.collection_workflow.promise_to_pay.quota.max_per_period');
    }

    /** Jumlah MISSED per periode yang memicu eskalasi (test pinning: 3). */
    private function missedThreshold(): int
    {
        return (int) config('shm-business-rule-contract.collection_workflow.escalation.missed_ptp_threshold');
    }

    /**
     * Otorisasi mutation yang eksplisit: actor HARUS pemilik case — tanpa
     * fallback admin, `shm.view_all`, maupun role lain yang tidak di-lock
     * contract T91 (pola T92/T93).
     *
     * @throws ValidationException
     */
    private function guardOwner(int $ownerId, int $actorId, string $message): void
    {
        if ($actorId !== $ownerId) {
            throw ValidationException::withMessages(['collection.otorisasi' => $message]);
        }
    }
}
