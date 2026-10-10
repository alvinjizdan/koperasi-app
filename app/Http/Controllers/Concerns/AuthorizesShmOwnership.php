<?php

namespace App\Http\Controllers\Concerns;

use App\Models\ShmApplication;
use App\Models\ShmLoanApplication;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Aturan kepemilikan pengajuan SHM.
 *
 * Marketing tanpa `shm.view_all` hanya boleh melihat dan mengelola pengajuan
 * miliknya sendiri. Admin/superadmin yang memegang `shm.view_all` boleh
 * memantau seluruh pengajuan. Aturan ini ditegakkan di server, bukan hanya
 * dengan menyembunyikan tombol di UI.
 *
 * Dipakai bersama oleh seluruh controller SHM supaya batas akses tidak
 * ditulis ulang (dan tidak menyimpang) di tiap controller.
 */
trait AuthorizesShmOwnership
{
    /**
     * Apakah pengguna berhak melihat seluruh pengajuan SHM.
     */
    protected function canViewAllShm(): bool
    {
        return Gate::allows('shm.view_all');
    }

    /**
     * Pastikan pengguna berhak membuka pengajuan ini.
     */
    protected function authorizeShmOwnership(Request $request, ShmApplication $application): void
    {
        abort_if(
            ! $this->canViewAllShm() && $application->marketing_id !== $request->user()->id,
            403,
            'Pengajuan ini bukan milik Anda.'
        );
    }

    /**
     * Ticket 68 — pastikan pengguna berhak membuka/mengelola Pengajuan
     * Pinjaman SHM (ShmLoanApplication). Aturan IDENTIK dengan pengajuan
     * legacy: tanpa `shm.view_all`, hanya pengajuan milik sendiri; server-
     * side, bukan menyembunyikan tombol di UI.
     */
    protected function authorizeLoanOwnership(Request $request, ShmLoanApplication $application): void
    {
        abort_if(
            ! $this->canViewAllShm() && $application->marketing_id !== $request->user()->id,
            403,
            'Pengajuan ini bukan milik Anda.'
        );
    }
}
