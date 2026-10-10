<?php

namespace App\Http\Controllers\Concerns;

use App\Models\ShmApplication;
use App\Models\ShmLoan;
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

    /**
     * Ticket 70 — pastikan pengguna berhak membuka record Pinjaman SHM.
     *
     * `shm_loans` TIDAK memiliki kolom `marketing_id`: kepemilikan sebuah
     * pinjaman mengikuti pemilik PENGAJUAN asal pencairannya
     * (`shm_loan_applications.marketing_id`), sehingga aturan yang berlaku
     * identik dengan pengajuan — tanpa `shm.view_all` hanya pinjaman hasil
     * pengajuan milik sendiri yang boleh dibuka.
     *
     * Pinjaman tanpa pengajuan asal (`shm_loan_application_id` NULL) dianggap
     * tidak berada dalam lingkup kepemilikan siapa pun, sehingga hanya
     * pemegang `shm.view_all` yang dapat membukanya. Ditegakkan di server,
     * bukan dengan menyembunyikan tombol di UI.
     */
    protected function authorizeLoanRecordOwnership(Request $request, ShmLoan $loan): void
    {
        if ($this->canViewAllShm()) {
            return;
        }

        abort_if(
            $loan->application === null || $loan->application->marketing_id !== $request->user()->id,
            403,
            'Pinjaman ini bukan milik Anda.'
        );
    }
}
