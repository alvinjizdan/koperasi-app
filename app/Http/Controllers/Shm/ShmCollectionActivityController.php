<?php

namespace App\Http\Controllers\Shm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shm\StoreShmCollectionActivityRequest;
use App\Models\ShmCollectionCase;
use App\Services\Shm\ShmCollectionActivityService;
use Illuminate\Http\RedirectResponse;

/**
 * Ticket 93 — pencatatan Collection Activity pada case eksisting.
 *
 * Controller sengaja minimal: SATU method POST. Otorisasi mutation mutlak
 * ada di domain service (Marketing pemilik case — tanpa fallback admin/
 * shm.view_all); route middleware `shm.view` = gate modul Collection mengikuti
 * pola `marketing.activities.store` (permission view existing untuk jalur
 * tulis yang otorisasinya ditentukan di domain), sehingga T93 tidak membuat
 * permission baru selama matrix BM/PD masih PENDING BUSINESS CONFIRMATION.
 *
 * Riwayat aktivitas TIDAK memerlukan endpoint GET terpisah: dibaca lewat
 * payload halaman detail Collection (`/collection/{loan}`) yang sudah menjadi
 * permukaan case detail — tanpa jalur baca ganda dan tanpa N+1 (eager load).
 *
 * Tidak ada efek samping lifecycle: pencatatan aktivitas tidak pernah
 * mengubah status case, membuat PTP/pembayaran/settlement, maupun menyentuh
 * status pinjaman.
 */
class ShmCollectionActivityController extends Controller
{
    public function __construct(private readonly ShmCollectionActivityService $activities) {}

    /**
     * Catat satu aktivitas untuk Collection Case yang sudah ada.
     */
    public function store(StoreShmCollectionActivityRequest $request, ShmCollectionCase $case): RedirectResponse
    {
        $this->activities->create($case, (int) $request->user()->id, $request->validated());

        return redirect()
            ->route('shm.collection.show', $case->shm_loan_id)
            ->with('success', 'Aktivitas Collection berhasil dicatat.');
    }
}
