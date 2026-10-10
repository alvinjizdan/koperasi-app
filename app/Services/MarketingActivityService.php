<?php

namespace App\Services;

use App\Models\MarketingActivity;
use App\Models\ShmCustomer;
use App\Support\DbTransaction;

/**
 * Pencatatan aktivitas kunjungan Marketing.
 *
 * Semua operasi penting melewati DbTransaction + row lock, mengikuti pola
 * InstallmentPaymentService, agar dua request bersamaan tidak menghasilkan data
 * ganda atau tidak konsisten.
 */
class MarketingActivityService
{
    /**
     * Catat check-in kunjungan.
     *
     * marketingId SELALU berasal dari pengguna yang sedang login dan
     * checkedInAt SELALU dari waktu sistem; keduanya tidak pernah diambil dari
     * input pengguna.
     *
     * Idempotensi mengikuti pola installment_payments: bila idempotency_key
     * sudah terdaftar, transaksi sebelumnya dikembalikan tanpa dampak ganda,
     * termasuk ketika request yang sama tiba bersamaan.
     *
     * @param  array{shm_customer_id: int, latitude: numeric, longitude: numeric, result?: ?string, notes?: ?string, idempotency_key?: ?string}  $data
     */
    public function checkIn(array $data, int $marketingId): MarketingActivity
    {
        $idempotencyKey = $data['idempotency_key'] ?? null;

        if (is_string($idempotencyKey) && $idempotencyKey !== '') {
            $existing = MarketingActivity::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        return DbTransaction::run(function () use ($data, $marketingId, $idempotencyKey) {
            // Re-check idempotensi setelah lock: request ganda yang datang
            // bersamaan tetap mengembalikan aktivitas pertama.
            if (is_string($idempotencyKey) && $idempotencyKey !== '') {
                $existing = MarketingActivity::query()->where('idempotency_key', $idempotencyKey)->first();

                if ($existing !== null) {
                    return $existing;
                }
            }

            // Tujuan kunjungan SELALU nasabah SHM; findOrFail menolak id yang
            // tidak ada tanpa fallback apa pun ke anggota koperasi.
            $customer = ShmCustomer::query()->findOrFail($data['shm_customer_id']);

            $activity = MarketingActivity::create([
                'marketing_id' => $marketingId,
                'shm_customer_id' => $customer->id,
                // Waktu sistem, bukan input pengguna.
                'checked_in_at' => now(),
                'latitude' => $data['latitude'],
                'longitude' => $data['longitude'],
                'result' => $data['result'] ?? null,
                'notes' => $data['notes'] ?? null,
                'idempotency_key' => $idempotencyKey,
            ]);

            AuditLogger::log(
                'marketing.activity.checked_in',
                MarketingActivity::class,
                $activity->id,
                $customer->name,
                sprintf('Check-in kunjungan Marketing pada %s.', $activity->checked_in_at->format('Y-m-d H:i:s')),
                [
                    'marketing_id' => $marketingId,
                    'shm_customer_id' => $customer->id,
                    'customer_number' => $customer->customer_number,
                    'checked_in_at' => $activity->checked_in_at->format('Y-m-d H:i:s'),
                    // Koordinat dicatat pada audit karena merupakan jejak
                    // lokasi yang dapat ditelusuri.
                    'latitude' => (float) $activity->latitude,
                    'longitude' => (float) $activity->longitude,
                ],
            );

            return $activity;
        });
    }

    /**
     * Perbarui hasil dan catatan kunjungan.
     *
     * Hasil/kataan boleh kosong. Daftar hasil resmi belum ditetapkan KSP,
     * sehingga isian bersifat bebas dan mudah diganti di masa depan.
     *
     * @param  array{result?: ?string, notes?: ?string}  $data
     */
    public function updateResult(MarketingActivity $activity, array $data, int $actorId): MarketingActivity
    {
        return DbTransaction::run(function () use ($activity, $data, $actorId) {
            $locked = MarketingActivity::query()->lockForUpdate()->findOrFail($activity->id);

            $before = [
                'result' => $locked->result,
                'notes' => $locked->notes,
            ];

            $locked->fill([
                'result' => $data['result'] ?? null,
                'notes' => $data['notes'] ?? null,
            ])->save();

            // Satu aksi pengguna = satu baris audit. Nilai lama/baru ditulis
            // eksplisit karena kolom yang dilacak (result, notes) nullable.
            AuditLogger::log(
                'marketing.activity.result_updated',
                MarketingActivity::class,
                $locked->id,
                $locked->customer?->name,
                'Hasil dan catatan kunjungan diperbarui.',
                [
                    'marketing_id' => $locked->marketing_id,
                    'updated_by' => $actorId,
                    'result' => ['old' => $before['result'], 'new' => $locked->result],
                    'notes' => ['old' => $before['notes'], 'new' => $locked->notes],
                ],
            );

            return $locked;
        });
    }
}
