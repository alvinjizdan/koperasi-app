<?php

namespace App\Services\Shm;

use App\Models\ShmCollectionCase;
use App\Models\ShmCollectionPtp;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Service record Promise to Pay (PTP) SHM (Ticket 97).
 *
 * Satu-satunya jalur pembuatan & mutasi status PTP (domain layer — bukan
 * dari frontend). Aturan mengikuti contract T91 Rule 8
 * (`config/shm-business-rule-contract.php` → `promise_to_pay`) dan batas
 * aman T97:
 *
 * - Record WAJIB melekat pada Collection Case eksisting, dan case WAJIB
 *   berstatus PROMISE_TO_PAY — PTP tidak pernah mengubah status case
 *   (transisi IN_PROGRESS → PROMISE_TO_PAY tetap melalui
 *   `ShmCollectionCaseService::transition()`; service ini tidak menyentuh
 *   lifecycle case sama sekali).
 * - Otorisasi mutation = Marketing PEMILIK case secara eksplisit (pola
 *   T92/T93; tanpa fallback admin/`shm.view_all` — actor selalu user
 *   session yang diteruskan controller, tidak pernah dari payload).
 * - SATU PTP OPEN per case: guard row lock + partial unique index di
 *   database. PTP terminal lama TIDAK memblokir record baru (riwayat
 *   multiple PTP per attempt terjaga).
 * - Status manual-only: OPEN → FULFILLED | MISSED | CANCELLED (ketiganya
 *   terminal, tanpa reopen). TIDAK PERNAH dihitung otomatis: `promised_date`
 *   lewat hari ini TIDAK membuat MISSED, dan pembayaran TIDAK membuat
 *   FULFILLED (grace period, broken-PTP detection, dan payment-to-PTP
 *   matching = PENDING BUSINESS CONFIRMATION — contract `cannot_guess`).
 * - Tanpa efek samping finansial/lifecycle: pembuatan & mutasi PTP tidak
 *   pernah membuat payment/alokasi/settlement, tidak menyentuh saldo atau
 *   status pinjaman, tidak mengubah status case, tidak mengeskalasi kasus,
 *   dan tidak membuat audit `shm.collection.case.status_changed`.
 * - Audit transactional via AuditLogger existing: `shm.collection.ptp.created`
 *   dan `shm.collection.ptp.status_changed`; kegagalan audit MEMBATALKAN
 *   mutasi (satu transaksi).
 *
 * YANG SENGAJA TIDAK DIIMPLEMENTASIKAN (PENDING BUSINESS CONFIRMATION):
 * grace period, automatic expiry/MISSED/fulfilled, jumlah percobaan PTP
 * maksimum, penalty, reminder/notifikasi, eskalasi otomatis, collector.
 */
class ShmCollectionPtpService
{
    /**
     * Catat satu PTP pada case yang berstatus PROMISE_TO_PAY.
     *
     * Urutan guard: case eksisting (row lock) ↁEotorisasi pemilik ↁEstatus
     * case PROMISE_TO_PAY ↁEtanggal terisi ↁEnominal > 0 ↁETIDAK ada PTP
     * OPEN ↁEpersist (status OPEN) ↁEaudit. Seluruhnya SATU transaksi.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws ValidationException bila case tidak ada, actor bukan pemilik,
     *                             status case salah, tanggal kosong, nominal
     *                             tidak positif, atau PTP OPEN sudah ada
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
     * Urutan guard: PTP eksisting (row lock) ↁEotorisasi pemilik case induk
     * ↁEgraph `ALLOWED_TRANSITIONS` (hanya OPEN → terminal) ↁEpersist
     * ↁEaudit. Seluruhnya SATU transaksi.
     *
     * TIDAK ada pembacaan tanggal maupun pembayaran: keputusan FULFILLED/
     * MISSED/cancel semuanya berasal dari aksi manusia, bukan perhitungan.
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
