<?php

namespace App\Services\Shm;

use App\Models\ShmLoan;
use App\Models\ShmLoanApplication;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Ticket 69 — Pencairan Pengajuan Pinjaman SHM menjadi running loan.
 *
 * Source of truth pencairan. Satu transaksi database berisi: lock baris
 * pengajuan (lockForUpdate), guard ownership + approval, pembuatan tepat
 * SATU ShmLoan (status active, disbursed_at terisi), dan tepat SATU baris
 * audit. Bila salah satu bagian gagal, tidak ada satu pun bagian yang
 * tertulis (rollback penuh — tanpa loan/audit setengah matang).
 *
 * IDEMPOTENCY wajib: satu pengajuan → tepat satu pinjaman. Ditegakkan
 * berlapis: (1) lockForUpdate membaca state di dalam transaksi, (2) cek
 * tautan pengajuan→loan, (3) constraint UNIQUE `shm_loans.
 * shm_loan_application_id` di database untuk request paralel. Percobaan
 * kedua ditolak dengan business error — tidak ada loan kedua dan tidak ada
 * audit kedua.
 *
 * APPROVAL GUARD: hanya pengajuan yang keputusan ShmApproval existing-nya
 * 'approved' yang boleh dicairkan; belum diajukan/pending/rejected ditolak.
 * Guard ada di service (server), bukan hanya persembunyian tombol UI.
 *
 * TIDAK ada formula bunga/cicilan/jadwal di ticket ini: rate_percent hanya
 * disalin dari konstanta resmi (2%), rate_period tetap null (periode PENDING
 * BUSINESS CONFIRMATION), dan field finansial pengajuan TIDAK diubah.
 */
class ShmLoanDisbursementService
{
    /** Percobaan pembuatan nomor pinjaman sebelum dianggap gagal. */
    private const NUMBER_ATTEMPTS = 5;

    /**
     * Cairkan pengajuan yang approval-nya sudah 'approved' menjadi satu
     * ShmLoan aktif.
     *
     * @throws ValidationException bila guard approval/idempotency menolak
     * @throws \Throwable bila pembuatan loan/audit gagal (rollback penuh)
     */
    public function disburse(ShmLoanApplication $application, int $actorId): ShmLoan
    {
        return DB::transaction(function () use ($application, $actorId): ShmLoan {
            // 1. Lock baris pengajuan — state dibaca di dalam transaksi,
            //    bukan dari state UI yang mungkin sudah basi.
            $locked = ShmLoanApplication::query()->lockForUpdate()->findOrFail($application->id);

            // 2. Ownership (aturan identik AuthorizesShmOwnership) — ditegakkan
            //    di server, bukan hanya tombol frontend.
            abort_if(
                ! Gate::allows('shm.view_all') && $locked->marketing_id !== $actorId,
                403,
                'Pengajuan ini bukan milik Anda.'
            );

            // 3. Idempotency: pengajuan yang sudah dicairkan menolak pembuatan
            //    loan kedua (dan karenanya juga audit kedua).
            if ($locked->loan()->exists()) {
                throw ValidationException::withMessages([
                    'application' => sprintf(
                        'Pengajuan %s sudah pernah dicairkan (pinjaman %s).',
                        $locked->application_number,
                        (string) $locked->loan()->value('loan_number'),
                    ),
                ]);
            }

            // 4. Approval guard: keputusan hidup di baris ShmApproval existing
            //    (T68 §6) — bukan di kolom status pengajuan.
            $approval = $locked->approval();

            if ($approval === null) {
                throw ValidationException::withMessages([
                    'application' => 'Pengajuan belum diajukan approval sehingga tidak dapat dicairkan.',
                ]);
            }

            if ($approval->status !== 'approved') {
                throw ValidationException::withMessages([
                    'application' => sprintf(
                        'Hanya pengajuan dengan approval DISETUJUI yang dapat dicairkan. Status approval saat ini: %s.',
                        $approval->status,
                    ),
                ]);
            }

            // 5. Field wajib ShmLoan yang tidak tersedia di pengajuan tidak
            //    pernah dikarang — ditolak dengan pesan eksplisit.
            if ($locked->shm_loan_product_id === null) {
                throw ValidationException::withMessages([
                    'application' => 'Pengajuan tidak memiliki produk pinjaman sehingga pinjaman tidak dapat dibuat. Lengkapi produk sebelum pencairan (PENDING BUSINESS CONFIRMATION).',
                ]);
            }

            // 6. Buat tepat SATU running loan. Pemetaan hanya dari field yang
            //    sudah ada pada pengajuan; nomor dibuat server (bukan payload).
            $loan = ShmLoan::create([
                'loan_number' => $this->generateNumber(),
                'shm_customer_id' => $locked->shm_customer_id,
                'shm_loan_product_id' => $locked->shm_loan_product_id,
                'shm_loan_application_id' => $locked->id,
                'principal' => $locked->principal,
                'tenor_months' => $locked->tenor_months,
                // Rate resmi dari konstanta tunggal; periode TIDAK ditebak.
                'rate_percent' => ShmLoan::OFFICIAL_RATE_PERCENT,
                'rate_period' => null,
                // Snapshot pilihan eksplisit pengguna pada pengajuan (bukan
                // formula sistem); tanpa rate_period tidak ada jadwal yang
                // dapat dibangun (lihat ShmLoan::hasExplicitCalculation).
                'calculation_method' => $locked->calculation_method,
                'purpose' => $locked->purpose,
                'status' => ShmLoan::STATUS_ACTIVE,
                'disbursed_at' => now()->toDateString(),
            ]);

            // 7. Tepat SATU baris audit pencairan, di dalam transaksi yang
            //    sama (rollback bila pencairan gagal). Actor = pengguna
            //    authenticated yang melakukan pencairan.
            AuditLogger::log(
                'shm.loan_application.disbursed',
                ShmLoanApplication::class,
                $locked->id,
                $locked->application_number,
                sprintf(
                    'Pengajuan pinjaman SHM %s dicairkan menjadi pinjaman %s.',
                    $locked->application_number,
                    $loan->loan_number,
                ),
                [
                    'shm_loan_id' => $loan->id,
                    'loan_number' => $loan->loan_number,
                    'shm_customer_id' => $locked->shm_customer_id,
                    'shm_loan_product_id' => $locked->shm_loan_product_id,
                    'principal' => (string) $loan->principal,
                    'tenor_months' => $loan->tenor_months,
                    'status' => $loan->status,
                    'disbursed_at' => $loan->disbursed_at?->toDateString(),
                ],
            );

            return $loan;
        });
    }

    /**
     * Nomor pinjaman baru, dibuat di server.
     *
     * Format `SHM-PIN-YYYYMMDD-XXXXXXXX` mengikuti pola nomor pengajuan SHM
     * (T67): komponen acak dari random_bytes sehingga tidak dapat ditebak
     * dan tidak bentrok antar request paralel.
     *
     * @throws RuntimeException bila setelah beberapa percobaan tetap bentrok
     */
    private function generateNumber(): string
    {
        for ($attempt = 0; $attempt < self::NUMBER_ATTEMPTS; $attempt++) {
            $candidate = sprintf(
                'SHM-PIN-%s-%s',
                now()->format('Ymd'),
                strtoupper(bin2hex(random_bytes(4))),
            );

            if (! ShmLoan::query()->where('loan_number', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new RuntimeException(
            'Nomor pinjaman SHM tidak dapat dibuat setelah beberapa percobaan.'
        );
    }
}
