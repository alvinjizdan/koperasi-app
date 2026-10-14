<?php

namespace App\Services\Shm;

use App\Models\SavingProduct;
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
 * KONTRAK FINANSIAL T82 — RATE = f(PRODUCT, TENOR): bersamaan dengan
 * pembuatan loan (transaksi yang sama), record pinjaman menstempel rate
 * hasil resolusi EXAKT produk + tenor (ShmProductRateService) beserta
 * kontrak baseline T79 yang tetap berlaku:
 *
 *   rate_percent       = tier rate produk untuk tenor pengajuan (EXAKT)
 *   rate_period        = 'monthly'
 *   calculation_method = 'flat'
 *   first_due_date     = tanggal jatuh tempo pertama dari pengajuan
 *                        (DIPILIH MARKETING — bukan hasil hitung otomatis)
 *
 * Universal 2% (ShmLoan::OFFICIAL_RATE_PERCENT) TIDAK dipakai lagi di sini
 * (T82): tenor di luar range eksak produk (termasuk gap) MENOLAK pencairan
 * — tanpa interpolasi, fallback, nearest, maupun default rate. Pembangkitan
 * jadwal tetap menjadi tanggung jawab ShmRepaymentScheduleService (T80),
 * dan field finansial pengajuan TIDAK diubah.
 */
class ShmLoanDisbursementService
{
    /** Percobaan pembuatan nomor pinjaman sebelum dianggap gagal. */
    private const NUMBER_ATTEMPTS = 5;

    public function __construct(
        private readonly ShmProductRateService $productRates,
        private readonly ShmDisbursementCostService $costs,
        private readonly ShmSavingsIntegrationService $savingsIntegration,
    ) {}

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

            // 5b. T82 — first_due_date WAJIB diisi Marketing sebelum
            //     pencairan; TIDAK pernah dihitung otomatis dari tanggal
            //     pencairan (bukan disbursement + 1 bulan).
            if ($locked->first_due_date === null) {
                throw ValidationException::withMessages([
                    'application' => sprintf(
                        'Pengajuan %s belum memiliki tanggal jatuh tempo pertama (first due date). Isi pada form pengajuan — ditentukan Marketing, bukan dihitung otomatis.',
                        $locked->application_number,
                    ),
                ]);
            }

            // 5c. T82 — resolusi rate EXAKT product + tenor. Tenor di luar
            //     range/gap MENOLAK pencairan (tanpa fallback/interpolasi).
            $quote = $this->productRates->resolve(
                $locked->product,
                (int) $locked->tenor_months,
            );

            // 5d. T85 — kalkulasi biaya pencairan (admin/provisi/asuransi/
            //     notaris/simpanan) berbasis principal ASLI pengajuan.
            //     Backend authoritative: total_deduction & net_disbursement
            //     TIDAK PERNAH datang dari frontend.
            $costBreakdown = $this->costs->calculate(
                (float) $locked->principal,
                (int) $locked->tenor_months,
                (float) ($locked->notary_fee ?? 0),
            );

            // 5e. T85 — net_disbursement negatif DITOLAK: tidak ada pinjaman
            //     aktif dengan dana bersih di bawah 0.
            if ($costBreakdown['net_disbursement'] < 0) {
                throw ValidationException::withMessages([
                    'application' => sprintf(
                        'Pencairan ditolak: total biaya potongan (%s) melebihi plafon (%s) sehingga dana bersih menjadi negatif.',
                        number_format($costBreakdown['total_deduction'], 0, ',', '.'),
                        number_format((float) $locked->principal, 0, ',', '.'),
                    ),
                ]);
            }

            // 5f. T89C — Alokasi potongan Simpanan Pokok dan Simpanan Wajib ke rekening anggota sumber
            $customer = $locked->customer;
            $member = $customer?->member;

            if ($member && $this->savingsIntegration) {
                // Simpanan Pokok: Rp100.000 (fixed)
                if ((float) $costBreakdown['principal_savings'] > 0) {
                    $this->savingsIntegration->deposit(
                        $member,
                        SavingProduct::CATEGORY_PRINCIPAL,
                        (float) $costBreakdown['principal_savings'],
                        sprintf('shm-disbursement-principal:%d', $locked->id),
                        sprintf('Setoran Simpanan Pokok dari pencairan pinjaman SHM %s', $locked->application_number),
                        $actorId,
                    );
                }

                // Simpanan Wajib: Rp25.000 × tenor
                if ((float) $costBreakdown['mandatory_savings'] > 0) {
                    $this->savingsIntegration->deposit(
                        $member,
                        SavingProduct::CATEGORY_MANDATORY,
                        (float) $costBreakdown['mandatory_savings'],
                        sprintf('shm-disbursement-mandatory:%d', $locked->id),
                        sprintf('Setoran Simpanan Wajib dari pencairan pinjaman SHM %s', $locked->application_number),
                        $actorId,
                    );
                }
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
                // Kontrak finansial distempel DI DALAM transaksi yang sama
                // dengan pembuatan loan (atomik): rate = hasil resolusi
                // EXAKT produk+tenor (T82) — bukan universal 2%, dan tidak
                // mungkin ada loan aktif dengan konfigurasi finansial
                // setengah terisi.
                'rate_percent' => $quote->ratePercent,
                'rate_period' => $quote->ratePeriod,
                'calculation_method' => $quote->calculationMethod,
                // T85 — snapshot biaya pencairan (immutable historical):
                // disimpan SEKALI saat pencairan, tidak pernah dihitung ulang.
                'admin_fee' => $costBreakdown['admin_fee'],
                'provision_fee' => $costBreakdown['provision_fee'],
                'insurance_fee' => $costBreakdown['insurance_fee'],
                'notary_fee' => $costBreakdown['notary_fee'],
                'principal_savings' => $costBreakdown['principal_savings'],
                'mandatory_savings' => $costBreakdown['mandatory_savings'],
                'total_deduction' => $costBreakdown['total_deduction'],
                'net_disbursement' => $costBreakdown['net_disbursement'],
                // Jatuh tempo pertama dari pengajuan (Marketing-pilih).
                'first_due_date' => $locked->first_due_date->toDateString(),
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
                    // T85 — hasil finansial pencairan pada event YANG SAMA
                    // (bukan audit event baru).
                    'total_deduction' => (string) $loan->total_deduction,
                    'net_disbursement' => (string) $loan->net_disbursement,
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
