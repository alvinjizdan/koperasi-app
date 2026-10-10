<?php

namespace App\Http\Requests\Shm;

/**
 * Ticket 68 — validasi server-side perubahan Pengajuan Pinjaman SHM.
 *
 * Rules = rules create (StoreShmLoanApplicationRequest) karena contract data
 * 11 section identik; ditambah kunci `id` opsional pada array child agar
 * service dapat merekonsiliasi baris lama (update) vs baris baru (create)
 * tanpa delete-all-reinsert.
 *
 * Lock approval TIDAK divalidasi di sini: dilakukan di dalam transaksi
 * service (lockForUpdate) sehingga keputusan diambil dari state database
 * terbaru, bukan dari state UI yang mungkin sudah basi.
 *
 * `marketing_id` TIDAK diterima dari payload (tidak ada di rules) — pemilik
 * diatur server saat create dan tidak pernah berubah saat update, sehingga
 * manipulasi marketing_id dari klien tidak dapat membypass scope.
 */
class UpdateShmLoanApplicationRequest extends StoreShmLoanApplicationRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            // Kunci rekonsiliasi child (opsional; tanpa id = baris baru).
            'jaminan.*.id' => ['nullable', 'integer'],
            'agunan.*.id' => ['nullable', 'integer'],
            'persyaratan.*.id' => ['nullable', 'integer'],
        ]);
    }
}
