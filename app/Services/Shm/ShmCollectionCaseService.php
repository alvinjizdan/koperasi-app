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
 *   kembali; tanpa automatic resolution/escalation di T92.
 * - Otorisasi mutation = Marketing PEMILIK case/pinjaman secara eksplisit —
 *   tanpa fallback admin maupun `shm.view_all` (otorisasi teknis BM/PD =
 *   PENDING BUSINESS CONFIRMATION, lihat laporan T92).
 * - Audit transactional via AuditLogger existing (contract T91 Rule 14):
 *   `shm.collection.case.created` dan `shm.collection.case.status_changed`
 *   (mengikuti konvensi penamaan audit `shm.*` yang sudah dipakai modul SHM).
 *
 * YANG SENGAJA TIDAK ADA (bukan kelalaian): automatic resolution berdasarkan
 * outstanding/pembayaran/settlement, automatic escalation, assignment
 * algorithm, role Collector, detail Promise-to-Pay, penalty, notification,
 * scheduler/job — semuanya ticket terpisah atau PENDING BUSINESS CONFIRMATION.
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
     * Transisi status lifecycle case — guard transisi + timestamp + audit
     * dalam satu transaksi.
     *
     * Transisi hanya diizinkan mengikuti `ShmCollectionCase::ALLOWED_TRANSITIONS`
     * (baseline T92). Transisi keluar dari RESOLVED selalu ditolak (tanpa
     * reopening di T92). `reason` TIDAK menjadi kolom — dicatat pada audit
     * changes agar konteks perubahan terlacak.
     *
     * @throws ValidationException bila actor bukan pemilik case, status target
     *                             tidak dikenal, atau transisi tidak diizinkan
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
     * Tutup case (→ RESOLVED) melalui invokasi eksplisit.
     *
     * T92 TIDAK membuat automatic resolution berdasarkan outstanding = 0,
     * pembayaran, maupun penyelesaian settlement — koneksi tersebut menjadi
     * ticket pembayaran/Collection berikutnya.
     *
     * @throws ValidationException
     */
    public function resolve(ShmCollectionCase $case, int $actorId, ?string $reason = null): ShmCollectionCase
    {
        return $this->transition($case, ShmCollectionCase::STATUS_RESOLVED, $actorId, $reason);
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
