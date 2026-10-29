<?php

namespace App\Services\Shm;

use App\Models\CashTransaction;
use App\Models\SavingProduct;
use App\Models\ShmLoan;
use App\Models\ShmLoanApplication;
use App\Services\AuditLogger;
use App\Services\CashierService;
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
        // T002C F-01 — tanpa default null: layanan kasir WAJIB tersedia
        // sehingga pencairan tidak dapat berjalan tanpa mekanisme sesi kasir.
        private readonly CashierService $cashierService,
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
            $locked = ShmLoanApplication::query()->lockForUpdate()->with(['customer.member', 'product'])->findOrFail($application->id);

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
                    'application' => 'Pengajuan tidak memiliki produk pinjaman sehingga pinjaman tidak dapat dibuat. Lengkapi produk sebelum pencairan.',
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

            // T150 / T002 (BR-01): approved_amount adalah otoritas tunggal gross
            // principal pencairan. Fallback ke requested principal DILARANG
            // (fail-closed): pengajuan tanpa approved_amount ditolak tanpa efek finansial.
            if ($locked->approved_amount === null) {
                throw ValidationException::withMessages([
                    'application' => 'Pengajuan belum memiliki nominal persetujuan (approved amount) sehingga tidak dapat dicairkan.',
                ]);
            }

            $grossPrincipal = (float) $locked->approved_amount;
            if ($grossPrincipal <= 0) {
                throw ValidationException::withMessages([
                    'application' => 'Nominal persetujuan pencairan tidak valid (harus lebih dari 0).',
                ]);
            }

            // 5d. T85 — kalkulasi biaya pencairan (admin/provisi/asuransi/
            //     notaris/simpanan) berbasis gross principal (approved_amount).
            //     Backend authoritative: total_deduction & net_disbursement
            //     TIDAK PERNAH datang dari frontend.
            $costBreakdown = $this->costs->calculate(
                $grossPrincipal,
                (int) $locked->tenor_months,
                (float) ($locked->notary_fee ?? 0),
            );

            // 5e. T85 / T002 (BR-02): net_disbursement non-positif (<= 0) DITOLAK:
            //     tidak ada pinjaman aktif dengan dana bersih nol atau negatif (fail-closed).
            if ($costBreakdown['net_disbursement'] <= 0) {
                throw ValidationException::withMessages([
                    'application' => sprintf(
                        'Pencairan ditolak: total biaya potongan (%s) mencapai atau melebihi plafon (%s) sehingga dana bersih tidak bernilai positif.',
                        number_format($costBreakdown['total_deduction'], 0, ',', '.'),
                        number_format($grossPrincipal, 0, ',', '.'),
                    ),
                ]);
            }

            // 5f. T89C / T150 — Alokasi potongan Simpanan Pokok dan Simpanan
            //     Wajib ke rekening anggota sumber. FAIL CLOSED: relasi anggota
            //     sumber wajib ada dan aktif; tidak ada fallback yang boleh
            //     melewatkan posting simpanan (T002C).
            $customer = $locked->customer;
            $member = $customer?->member;

            if (! $member) {
                throw ValidationException::withMessages([
                    'application' => 'Nasabah SHM tidak memiliki relasi anggota sumber.',
                ]);
            }

            if ($member->status !== 'active') {
                throw ValidationException::withMessages([
                    'application' => 'Anggota sumber tidak aktif.',
                ]);
            }

            // T002C F-01 — SESI KASIR AKTIF MILIK ACTOR WAJIB. Pencairan
            // tidak boleh lanjut tanpa sesi kasir yang terbuka dan dimiliki
            // oleh pelaku (activeSessionFor sudah memfilter opened_by =
            // actor). Tanpa sesi: REJECT — tanpa loan, tanpa simpanan,
            // tanpa kas keluar, tanpa audit sukses. Config bypass DIHAPUS:
            // syarat ini tidak dapat dimatikan untuk kompatibilitas mundur.
            // (Layanan kasir sendiri kini non-optional di constructor.)
            $activeSession = $this->cashierService->activeSessionFor($actorId);

            if ($activeSession === null) {
                throw ValidationException::withMessages([
                    'application' => 'Sesi kasir aktif diperlukan untuk pencairan. Buka sesi kasir terlebih dahulu.',
                ]);
            }

            if ($this->savingsIntegration) {
                // Simpanan Pokok: Rp100.000 (fixed) - tanpa dampak kas
                // (bukan pendapatan kasir). Gagal → rollback seluruh event.
                if ((float) $costBreakdown['principal_savings'] > 0) {
                    $this->savingsIntegration->deposit(
                        $member,
                        SavingProduct::CATEGORY_PRINCIPAL,
                        (float) $costBreakdown['principal_savings'],
                        // Idempotency key deterministik & sudah tersedia saat
                        // posting: application_id (bukan loan_id yang belum
                        // ada pada tahap ini).
                        sprintf('shm-disbursement-principal:%d', $locked->id),
                        sprintf('Setoran Simpanan Pokok dari pencairan pinjaman SHM %s', $locked->application_number),
                        $actorId,
                        false,
                    );
                }

                // Simpanan Wajib: Rp25.000 × tenor - tanpa dampak kas.
                if ((float) $costBreakdown['mandatory_savings'] > 0) {
                    $this->savingsIntegration->deposit(
                        $member,
                        SavingProduct::CATEGORY_MANDATORY,
                        (float) $costBreakdown['mandatory_savings'],
                        sprintf('shm-disbursement-mandatory:%d', $locked->id),
                        sprintf('Setoran Simpanan Wajib dari pencairan pinjaman SHM %s', $locked->application_number),
                        $actorId,
                        false,
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
                'principal' => $grossPrincipal,
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

            // T002C F-02 — KAS KELUAR NET WAJIB dalam transaksi yang sama.
            // Tidak ada config gate dan tidak ada swallow exception: bila
            // kas keluar gagal (sesi ditutup, saldo kas kurang, dst.) seluruh
            // pencairan ROLLBACK — tidak pernah ada state loan + simpanan
            // sukses tanpa kas keluar. Idempotensi dijaga oleh unique source
            // (source_type + source_id) pada CashierService::recordSourceImpact.
            $cashTransaction = $this->cashierService->recordSourceImpact(
                CashTransaction::TYPE_EXPENSE,
                config('cashier.source_categories.loan_disbursement') ?? 'Pencairan Pinjaman',
                (float) $costBreakdown['net_disbursement'],
                now()->toDateString(),
                'Pencairan pinjaman SHM '.$loan->loan_number,
                $loan,
                $actorId,
            );

            if ($cashTransaction === null) {
                throw ValidationException::withMessages([
                    'application' => 'Kas keluar pencairan tidak dapat dicatat (sesi kasir tidak valid) — pencairan dibatalkan.',
                ]);
            }

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
                    'requested_principal' => (string) $locked->principal,
                    'approved_amount' => (string) $grossPrincipal,
                    'gross_plafond' => (string) $grossPrincipal,
                    'principal' => (string) $loan->principal,
                    'tenor_months' => $loan->tenor_months,
                    'status' => $loan->status,
                    'disbursed_at' => $loan->disbursed_at?->toDateString(),
                    // T85 — hasil finansial pencairan pada event YANG SAMA
                    // (bukan audit event baru).
                    'admin_fee' => (string) $loan->admin_fee,
                    'provision_fee' => (string) $loan->provision_fee,
                    'insurance_fee' => (string) $loan->insurance_fee,
                    'notary_fee' => (string) $loan->notary_fee,
                    'principal_savings' => (string) $loan->principal_savings,
                    'mandatory_savings' => (string) $loan->mandatory_savings,
                    'total_deduction' => (string) $loan->total_deduction,
                    'net_disbursement' => (string) $loan->net_disbursement,
                    'actor_id' => $actorId,
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
