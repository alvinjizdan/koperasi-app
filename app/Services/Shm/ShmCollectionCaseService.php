<?php

namespace App\Services\Shm;

use App\Models\ShmCollectionCase;
use App\Models\ShmLoan;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Service pembuatan & lifecycle Collection Case SHM (Ticket 92).
 *
 * Satu-satunya jalur pembuatan/mutasi Collection Case (domain layer — bukan
 * dari frontend). Aturan mengikuti contract T91
 * (`config/shm-business-rule-contract.php` → `collection_workflow`) dan
 * baseline T92:
 * - Kelayakan = predikat T90 yang sama persis lewat
 *   `ShmCollectionQueryService::isCandidate()` (single source; tanpa grace,
 *   penalty, aging threshold, maupun collector rule baru).
 * - Initial owner = Marketing dari pengajuan asal pinjaman
 *   (`shm_loan_applications.marketing_id`) — TANPA fallback user pertama,
 *   marketing acak, ataupun auto-create user; loan tanpa pengajuan asal
 *   DITOLAK dengan validasi domain yang jelas.
 * - Idempotensi: SATU active case per pinjaman (definisi active = status
 *   selain RESOLVED) — request berikutnya mengembalikan case yang sama, bukan
 *   duplikat (guard row lock + partial unique index di database). Case
 *   RESOLVED permanen; pinjaman yang kembali eligible membuka case BARU.
 * - Lifecycle: 5 status contract T91 dengan tabel transisi
 *   `ShmCollectionCase::ALLOWED_TRANSITIONS`; RESOLVED tidak dapat dibuka
 *   kembali; tanpa automatic resolution di T92; eskalasi otomatis HANYA
 *   dari scheduler PTP (T99 — 3 PTP MISSED dalam periode anchored yang
 *   sama) melalui `escalateFromScheduler()` dengan graph guard yang sama.
 * - Guard finansial resolusi (Ticket 95): transisi → RESOLVED hanya bila
 *   sisa tunggakan overdue pinjaman = 0 (agregat server-side dari kolom
 *   persisted — contract T91 Rule 10 `resolution_condition`). Pembayaran,
 *   alokasi, dan aktivitas TIDAK PERNAH mengubah status case otomatis;
 *   resolusi selalu aksi eksplisit pemilik.
 * - Otorisasi mutation manual = Marketing PEMILIK case/pinjaman secara
 *   eksplisit — tanpa fallback admin maupun `shm.view_all` (otorisasi
 *   teknis BM/PD = PENDING BUSINESS CONFIRMATION, lihat laporan T92).
 *   Pengecualian T99: `escalateFromScheduler()` adalah jalur SYSTEM (tanpa
 *   session user, audit actor_id null + trigger 'scheduler') — bukan
 *   bypass otorisasi pengguna karena trigger-nya aturan bisnis, bukan aksi.
 * - Audit transactional via AuditLogger existing (contract T91 Rule 14):
 *   `shm.collection.case.created` dan `shm.collection.case.status_changed`
 *   (mengikuti konvensi penamaan audit `shm.*` yang sudah dipakai modul SHM).
 *
 * YANG SENGAJA TIDAK ADA (bukan kelalaian): automatic resolution berdasarkan
 * outstanding/pembayaran/settlement (Ticket 95 hanya menambahkan guard
 * sebaliknya — resolusi MANUSIA DITOLAK selama outstanding > 0; readiness
 * bukan auto-resolve), assignment algorithm, role Collector, approval
 * Authority Promise-to-Pay, penalty, notification, automation collection
 * selain sweep PTP T99 — semuanya ticket terpisah atau PENDING BUSINESS
 * CONFIRMATION.
 */
class ShmCollectionCaseService
{
    public function __construct(private readonly ShmCollectionQueryService $collections) {}

    /**
     * Buat Collection Case baru ATAU kembalikan active case yang sudah ada
     * (idempotent) untuk satu pinjaman.
     *
     * Urutan guard mengikuti baseline T92: lock pinjaman → kelayakan T90 →
     * resolusi owner Marketing → otorisasi pemilik → active case eksisting
     * (reuse) → buat case OPEN → audit creation. Semuanya dalam SATU
     * transaksi: kegagalan audit ikut membatalkan pembuatan case.
     *
     * @throws ValidationException bila pinjaman tidak layak, tanpa Marketing
     *                             owner yang valid, atau actor bukan pemilik
     */
    public function createOrGetForLoan(ShmLoan $loan, int $actorId): ShmCollectionCase
    {
        return DB::transaction(function () use ($loan, $actorId): ShmCollectionCase {
            $locked = ShmLoan::query()->lockForUpdate()->findOrFail($loan->getKey());

            // Kelayakan T90 (single source): active + sudah cair + memiliki
            // angsuran overdue dengan outstanding aktual > 0.
            if (! $this->collections->isCandidate($locked)) {
                throw ValidationException::withMessages([
                    'collection.kelayakan' => sprintf(
                        'Pinjaman %s tidak memenuhi kelayakan Collection (active, sudah dicairkan, dan memiliki tunggakan outstanding > 0).',
                        $locked->loan_number,
                    ),
                ]);
            }

            // Initial owner = Marketing pengajuan asal pencairan. Pinjaman
            // tanpa pengajuan asal tidak memiliki owner — DILARANG memilih
            // user secara random/fallback ataupun membuat user baru.
            $marketingId = $locked->application?->marketing_id;
            if ($marketingId === null) {
                throw ValidationException::withMessages([
                    'collection.pemilik' => sprintf(
                        'Pinjaman %s tidak memiliki Marketing owner dari pengajuan asal; pembuatan Collection Case tanpa fallback user tidak diizinkan.',
                        $locked->loan_number,
                    ),
                ]);
            }

            $this->guardOwner((int) $marketingId, $actorId, sprintf(
                'Hanya Marketing pemilik pinjaman %s yang boleh membuat Collection Case untuk pinjaman ini.',
                $locked->loan_number,
            ));

            // Idempotensi: kembalikan active case bila sudah ada. Row lock pada
            // pinjaman + case + partial unique index mencegah duplikat pada
            // request paralel.
            $existing = ShmCollectionCase::query()
                ->where('shm_loan_id', $locked->getKey())
                ->active()
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $case = ShmCollectionCase::query()->create([
                'shm_loan_id' => $locked->getKey(),
                'marketing_id' => (int) $marketingId,
                'status' => ShmCollectionCase::STATUS_OPEN,
                'opened_at' => now(),
                'resolved_at' => null,
                'created_by' => $actorId,
            ]);

            AuditLogger::log(
                'shm.collection.case.created',
                ShmCollectionCase::class,
                $case->id,
                $locked->loan_number,
                sprintf(
                    'Collection Case dibuat untuk pinjaman %s — status OPEN, pemilik Marketing #%d.',
                    $locked->loan_number,
                    $marketingId,
                ),
                [
                    'shm_loan_id' => $locked->getKey(),
                    'marketing_id' => (int) $marketingId,
                    'status' => ShmCollectionCase::STATUS_OPEN,
                    'opened_at' => $case->opened_at->toDateTimeString(),
                    'actor_id' => $actorId,
                ],
            );

            return $case;
        });
    }

    /**
     * Transisi status lifecycle case — guard transisi + guard finansial
     * resolusi + timestamp + audit dalam satu transaksi.
     *
     * Transisi hanya diizinkan mengikuti `ShmCollectionCase::ALLOWED_TRANSITIONS`
     * (baseline T92). Transisi keluar dari RESOLVED selalu ditolak (tanpa
     * reopening). Sejak Ticket 95 target RESOLVED dijaga guard finansial
     * server-side: sisa tunggakan overdue harus 0 (guardResolutionCleared).
     * `reason` TIDAK menjadi kolom — dicatat pada audit changes agar konteks
     * perubahan terlacak.
     *
     * @throws ValidationException bila actor bukan pemilik case, status target
     *                             tidak dikenal, transisi tidak diizinkan, atau
     *                             resolusi diminta saat tunggakan masih ada
     */
    public function transition(ShmCollectionCase $case, string $toStatus, int $actorId, ?string $reason = null): ShmCollectionCase
    {
        return DB::transaction(function () use ($case, $toStatus, $actorId, $reason): ShmCollectionCase {
            $locked = ShmCollectionCase::query()->lockForUpdate()->findOrFail($case->getKey());

            $this->guardOwner(
                (int) $locked->marketing_id,
                $actorId,
                'Hanya Marketing pemilik Collection Case yang boleh mengubah status lifecycle.',
            );

            if (! in_array($toStatus, array_keys(ShmCollectionCase::ALLOWED_TRANSITIONS), true)) {
                throw ValidationException::withMessages([
                    'collection.status' => sprintf('Status Collection "%s" tidak dikenal.', $toStatus),
                ]);
            }

            $fromStatus = (string) $locked->status;
            $allowed = ShmCollectionCase::ALLOWED_TRANSITIONS[$fromStatus] ?? [];

            if (! in_array($toStatus, $allowed, true)) {
                throw ValidationException::withMessages([
                    'collection.status' => $fromStatus === ShmCollectionCase::STATUS_RESOLVED
                        ? sprintf(
                            'Collection Case RESOLVED tidak dapat dibuka kembali (transisi %s → %s ditolak).',
                            $fromStatus,
                            $toStatus,
                        )
                        : sprintf('Transisi status Collection tidak diizinkan: %s → %s.', $fromStatus, $toStatus),
                ]);
            }

            if ($toStatus === ShmCollectionCase::STATUS_RESOLVED) {
                // Guard finansial server-side (Ticket 95): resolusi manual
                // hanya bila sisa tunggakan overdue = 0 (contract T91 Rule 10
                // payment_resolution.resolution_condition). Flag readiness
                // dari frontend TIDAK pernah dipercaya — backend menghitung
                // ulang dari kolom persisted.
                $this->guardResolutionCleared($locked);
            }

            $locked->status = $toStatus;

            if ($toStatus === ShmCollectionCase::STATUS_RESOLVED) {
                // Satu-satunya saat resolved_at terisi. Keluar dari RESOLVED
                // tidak mungkin di T92, sehingga nilai ini permanen.
                $locked->resolved_at = now();
            }

            $locked->save();

            $loanNumber = $locked->loan()->value('loan_number');

            AuditLogger::log(
                'shm.collection.case.status_changed',
                ShmCollectionCase::class,
                $locked->id,
                $loanNumber !== null ? (string) $loanNumber : null,
                sprintf(
                    'Status Collection Case pinjaman %s berubah %s → %s.%s',
                    $loanNumber ?? ('#'.$locked->shm_loan_id),
                    $fromStatus,
                    $toStatus,
                    $reason !== null && $reason !== '' ? sprintf(' Alasan: %s.', $reason) : '',
                ),
                [
                    'shm_loan_id' => $locked->shm_loan_id,
                    'from_status' => $fromStatus,
                    'to_status' => $toStatus,
                    'reason' => $reason,
                    'actor_id' => $actorId,
                ],
            );

            return $locked;
        });
    }

    /**
     * Guard finansial resolusi (Ticket 95): sisa tunggakan overdue pinjaman
     * harus tepat NOL sebelum case boleh masuk RESOLVED.
     *
     * Angka berasal dari agregat SQL server-side yang memakai formula &
     * predikat T90 yang sama (overdueObligationProgress) — bukan input
     * frontend, tanpa toleransi arbitrer (toleransi closure = PENDING).
     *
     * @throws ValidationException bila masih ada kewajiban overdue tersisa
     */
    private function guardResolutionCleared(ShmCollectionCase $case): void
    {
        $loan = $case->loan;
        $remaining = $this->collections->overdueObligationProgress($loan)['remaining_overdue'];

        if ((float) $remaining > 0) {
            throw ValidationException::withMessages([
                'collection.resolusi' => sprintf(
                    'Resolusi Collection Case ditolak: masih terdapat kewajiban overdue tersisa Rp %s untuk pinjaman %s. Penyelesaian hanya dapat dilakukan setelah seluruh tunggakan terbayar.',
                    number_format((float) $remaining, 2, ',', '.'),
                    $loan->loan_number,
                ),
            ]);
        }
    }

    /**
     * Tutup case (→ RESOLVED) melalui invokasi eksplisit.
     *
     * Resolusi TIDAK PERNAH otomatis (pembayaran, alokasi, maupun aktivitas
     * PAID tidak pernah memanggil ini). Sejak Ticket 95 invokasi eksplisit
     * ini juga dijaga guard finansial: transisi → RESOLVED ditolak bila sisa
     * tunggakan overdue > 0 (lihat guardResolutionCleared).
     *
     * @throws ValidationException
     */
    public function resolve(ShmCollectionCase $case, int $actorId, ?string $reason = null): ShmCollectionCase
    {
        return $this->transition($case, ShmCollectionCase::STATUS_RESOLVED, $actorId, $reason);
    }

    /**
     * Transisi → ESCALATED oleh scheduler T99 (system transition, tanpa
     * session user) — dipanggil ShmCollectionPtpService setelah sweep
     * menemukan 3 PTP MISSED dalam satu periode anchored.
     *
     * Beda dengan transition() (owner-guarded, actor user): method ini jalur
     * resmi untuk proses sistem — TIDAK ada guardOwner karena trigger =
     * aturan bisnis, bukan aksi pengguna; audit tetap ditulis dengan
     * actor_id null (kolom nullable; Auth::id() null pada konteks CLI) +
     * trigger 'scheduler'. Guard yang TETAP berlaku: graph
     * `ALLOWED_TRANSITIONS` (RESOLVED tidak pernah punya transisi keluar)
     * dan idempotensi (sudah ESCALATED → no-op tanpa audit ganda; diserahkan
     * ke caller untuk menilai apakah transisi benar-benar terjadi).
     *
     * @param  string  $reason  Alasan aturan (dicatat pada audit changes).
     * @return ShmCollectionCase case dengan status terkini — ESCALATED bila
     *                           transisi terjadi, tanpa perubahan bila graph
     *                           menolak/sudah ESCALATED
     */
    public function escalateFromScheduler(ShmCollectionCase $case, string $reason): ShmCollectionCase
    {
        return DB::transaction(function () use ($case, $reason): ShmCollectionCase {
            $locked = ShmCollectionCase::query()->lockForUpdate()->findOrFail($case->getKey());

            if ($locked->status === ShmCollectionCase::STATUS_ESCALATED) {
                return $locked;
            }

            $allowed = ShmCollectionCase::ALLOWED_TRANSITIONS[$locked->status] ?? [];

            if (! in_array(ShmCollectionCase::STATUS_ESCALATED, $allowed, true)) {
                return $locked;
            }

            $fromStatus = (string) $locked->status;
            $locked->status = ShmCollectionCase::STATUS_ESCALATED;
            $locked->save();

            $loanNumber = $locked->loan()->value('loan_number');

            AuditLogger::log(
                'shm.collection.case.status_changed',
                ShmCollectionCase::class,
                $locked->id,
                $loanNumber !== null ? (string) $loanNumber : null,
                sprintf(
                    'Status Collection Case pinjaman %s berubah %s → %s (scheduler). Alasan: %s.',
                    $loanNumber ?? ('#'.$locked->shm_loan_id),
                    $fromStatus,
                    ShmCollectionCase::STATUS_ESCALATED,
                    $reason,
                ),
                [
                    'shm_loan_id' => $locked->shm_loan_id,
                    'from_status' => $fromStatus,
                    'to_status' => ShmCollectionCase::STATUS_ESCALATED,
                    'reason' => $reason,
                    'actor_id' => null,
                    'trigger' => 'scheduler',
                ],
            );

            return $locked;
        });
    }

    /**
     * Otorisasi mutation yang eksplisit: actor HARUS pemilik — tanpa fallback
     * admin, `shm.view_all`, atau role lain yang tidak di-lock contract T91.
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
