<?php

namespace App\Http\Controllers\Shm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shm\StoreShmCollectionPtpRequest;
use App\Http\Requests\Shm\StoreShmCollectionPtpTransitionRequest;
use App\Models\ShmCollectionCase;
use App\Models\ShmCollectionPtp;
use App\Services\Shm\ShmCollectionPtpService;
use Illuminate\Http\RedirectResponse;

/**
 * Ticket 97 — pencatatan & mutasi status Promise to Pay (PTP).
 *
 * Controller sengaja minimal: DUA method POST (buat PTP, ubah status PTP).
 * Otorisasi mutation mutlak ada di domain service (Marketing pemilik case —
 * tanpa fallback admin/`shm.view_all`); route middleware `shm.view` = gate
 * modul Collection mengikuti pola T93/T94 (permission view existing untuk
 * jalur tulis yang otorisasinya ditentukan di domain, tanpa permission baru
 * selama matrix BM/PD masih PENDING BUSINESS CONFIRMATION).
 *
 * Aktor = `$request->user()->id` dari session — frontend tidak pernah
 * memasok actor maupun status case. Riwayat PTP TIDAK memerlukan endpoint
 * GET terpisah: dibaca lewat payload halaman detail Collection
 * (`/collection/{loan}`) tanpa jalur baca ganda.
 *
 * Tidak ada efek samping apa pun di controller: PTP tidak mengubah status
 * case (transisi case tetap endpoint T94), tidak membuat pembayaran/
 * settlement, tidak menyentuh saldo pinjaman, dan tidak mengeskalasi case.
 */
class ShmCollectionPtpController extends Controller
{
    public function __construct(private readonly ShmCollectionPtpService $ptps) {}

    /**
     * Catat satu PTP pada case eksisting (case wajib PROMISE_TO_PAY —
     * guard di service; tidak ada transisi status otomatis di sini).
     */
    public function store(StoreShmCollectionPtpRequest $request, ShmCollectionCase $case): RedirectResponse
    {
        $this->ptps->create($case, (int) $request->user()->id, $request->validated());

        return redirect()
            ->route('shm.collection.show', $case->shm_loan_id)
            ->with('success', 'Promise to Pay berhasil dicatat.');
    }

    /**
     * Ubah status PTP (OPEN → FULFILLED | MISSED | CANCELLED — aksi manual
     * eksplisit pemilik; graph & terminal dijaga di service).
     */
    public function transition(StoreShmCollectionPtpTransitionRequest $request, ShmCollectionPtp $ptp): RedirectResponse
    {
        $toStatus = (string) $request->validated('status');

        $this->ptps->transition($ptp, $toStatus, (int) $request->user()->id);

        return redirect()
            ->route('shm.collection.show', $ptp->case()->value('shm_loan_id'))
            ->with('success', sprintf('Status PTP berhasil diubah ke %s.', $toStatus));
    }
}
