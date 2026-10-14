<?php

namespace App\Services\Shm;

use App\Models\ShmCollectionActivity;
use App\Models\ShmCollectionCase;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Service pencatatan Collection Activity SHM (Ticket 93).
 *
 * Satu-satunya jalur pembuatan Collection Activity (domain layer). Aturan
 * mengikuti contract T91 (`config/shm-business-rule-contract.php` →
 * `collection_workflow` Rule 6/7) dan baseline T92:
 * - Aktivitas WAJIB melekat pada Collection Case eksisting (relasi induk);
 *   T93 tidak pernah membuat case otomatis — pembuatan case tetap T92.
 * - Otorisasi mutation = Marketing PEMILIK case secara eksplisit — tanpa
 *   fallback admin, `shm.view_all`, maupun permission lain (otorisasi
 *   teknis BM/PD = PENDING BUSINESS CONFIRMATION dari T91).
 * - Tipe & hasil divalidasi terhadap nilai kontrak T91 yang dieja persis
 *   (`ShmCollectionActivity::TYPES` / `RESULTS`); tanpa nilai tambahan.
 * - `activity_at` disimpan apa adanya sebagai WAKTU AKTIVITAS SEBENARNYA —
 *   TIDAK dipaksa sama dengan `created_at`, dan TIDAK ada batas tanggal
 *   (backdate/masa depan) karena contract tidak mendefinisikannya.
 * - Tanpa row lock: aktivitas append-only tanpa constraint unik — tidak ada
 *   invariant yang perlu diserialisasi (lock hanya pada kasus yang butuh).
 * - Audit transactional via AuditLogger existing: `shm.collection.activity.created`
 *   (mengikuti konvensi penamaan audit `shm.*` modul SHM); kegagalan audit
 *   MEMBATALKAN aktivitas (satu transaksi).
 * - TANPA efek samping lifecycle: status case tidak pernah berubah, dan
 *   result PROMISE_TO_PAY/PAID/UNABLE_TO_PAY TIDAK membuat PTP record,
 *   pembayaran, penyelesaian case, maupun eskalasi — masing-masing domain
 *   berada di ticket terpisah.
 */
class ShmCollectionActivityService
{
    /**
     * Catat satu aktivitas pada case eksisting.
     *
     * Urutan guard: case eksisting → otorisasi pemilik → tipe valid → hasil
     * valid → persist → audit. Seluruhnya dalam SATU transaksi.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws ValidationException bila case tidak ada, actor bukan pemilik,
     *                             tipe, maupun hasil di luar nilai kontrak
     */
    public function create(ShmCollectionCase $case, int $actorId, array $payload): ShmCollectionActivity
    {
        return DB::transaction(function () use ($case, $actorId, $payload): ShmCollectionActivity {
            // Case WAJIB masih ada (row bisa terhapus antar-waktu; tanpa ini
            // pesan galat menjadi QueryException FK yang tidak jelas).
            $current = ShmCollectionCase::query()->findOrFail($case->getKey());

            $this->guardOwner((int) $current->marketing_id, $actorId, sprintf(
                'Hanya Marketing pemilik Collection Case #%d yang boleh mencatat aktivitas pada case ini.',
                $current->getKey(),
            ));

            $activityType = (string) ($payload['activity_type'] ?? '');

            if (! in_array($activityType, ShmCollectionActivity::TYPES, true)) {
                throw ValidationException::withMessages([
                    'collection.aktivitas.tipe' => sprintf('Tipe aktivitas Collection "%s" tidak dikenal.', $activityType),
                ]);
            }

            $result = (string) ($payload['result'] ?? '');

            if (! in_array($result, ShmCollectionActivity::RESULTS, true)) {
                throw ValidationException::withMessages([
                    'collection.aktivitas.hasil' => sprintf('Hasil aktivitas Collection "%s" tidak dikenal.', $result),
                ]);
            }

            $activity = ShmCollectionActivity::query()->create([
                'shm_collection_case_id' => $current->getKey(),
                'user_id' => $actorId,
                'activity_type' => $activityType,
                'result' => $result,
                'activity_at' => $payload['activity_at'],
                'notes' => $payload['notes'] ?? null,
                'next_action' => $payload['next_action'] ?? null,
            ]);

            $loanNumber = $current->loan()->value('loan_number');

            AuditLogger::log(
                'shm.collection.activity.created',
                ShmCollectionActivity::class,
                $activity->id,
                $loanNumber !== null ? sprintf('%s / aktivitas #%d', $loanNumber, $activity->id) : null,
                sprintf(
                    'Collection Activity %s (%s) dicatat untuk Collection Case #%d pinjaman %s.',
                    $activityType,
                    $result,
                    $current->getKey(),
                    $loanNumber ?? ('#'.$current->shm_loan_id),
                ),
                [
                    'collection_activity_id' => $activity->id,
                    'shm_collection_case_id' => $current->getKey(),
                    'shm_loan_id' => $current->shm_loan_id,
                    'actor_id' => $actorId,
                    'activity_type' => $activityType,
                    'result' => $result,
                    'activity_at' => $activity->activity_at->toDateTimeString(),
                ],
            );

            return $activity;
        });
    }

    /**
     * Otorisasi mutation yang eksplisit: actor HARUS pemilik case — tanpa
     * fallback admin, `shm.view_all`, maupun role lain yang tidak di-lock
     * contract T91 (pola T92).
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
