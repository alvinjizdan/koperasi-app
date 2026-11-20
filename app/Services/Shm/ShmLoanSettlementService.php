<?php

namespace App\Services\Shm;

use App\Models\ShmCustomer;
use App\Models\ShmLoan;
use App\Models\ShmLoanInstallment;
use App\Models\ShmLoanPayment;
use App\Models\ShmLoanPaymentAllocation;
use App\Models\ShmLoanSettlement;
use App\Models\ShmLoanSettlementUnallocatedUsage;
use App\Services\Accounting\ShmAutomaticJournalService;
use App\Services\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * T89B — Sumber kebenaran Settlement / Pelunasan Pinjaman SHM (Model B).
 *
 * OPERASI (semua mutasi dalam SATU transaksi + lockForUpdate pinjaman &
 * settlement; idempoten & concurrency-safe):
 * - preview()        : READ-ONLY komponen kewajiban + resolusi discount policy.
 *                      Tidak pernah menulis record/payment/audit/penutupan.
 * - request()        : buat settlement PENDING + snapshot policy & komponen.
 * - approve()        : pending → approved (permission technical, role final PENDING).
 * - reject()         : pending → rejected (pengajuan ulang = baris baru).
 * - recordPayment()  : approved → payment_recorded (menautkan Payment T86).
 * - verifyPayment()  : payment_recorded → payment_verified (verifikasi EKSPLISIT;
 *                      status pembayaran `posted` BUKAN verifikasi).
 * - complete()       : payment_verified → completed + tutup pinjaman (active →
 *                      closed) + settlement angsuran + SATU audit completion.
 *
 * SETTLEMENT ≠ PAYMENT: tidak ada tabel/kanal pembayaran baru; pembayaran
 * tetap `shm_loan_payments` (T86–T88) dan TIDAK pernah dialokasikan ulang
 * oleh ticket ini (formula alokasi T87 tidak mengenal discount).
 *
 * BATAS TRANSAKSI (T108 — P1-08): satu pembayaran hanya melayani SATU
 * domain. `recordPayment()` MENOLAK pembayaran yang sudah teralokasi ke
 * angsuran (uang sudah mengurangi kewajiban angsuran — double count bila
 * dipakai sebagai pelunasan); sebaliknya `allocate()` (T87) menolak
 * pembayaran yang sudah ditautkan ke settlement. Discriminator = tautan
 * `shm_loan_payment_id` + baris alokasi (mekanisme existing, tanpa kolom
 * baru). Urutan konseptual TIDAK berubah: pembayaran dicatat → diverifikasi
 * eksplisit → settlement completed → pinjaman ditutup — rekaman pembayaran
 * tidak pernah menutup pinjaman.
 *
 * DISCOUNT (AC1–AC7): data-driven melalui ShmSettlementDiscountPolicy.
 * - Hanya pinjaman dengan plafon awal > Rp50.000.000 (AC3/AC4).
 * - Sisa bulan = jumlah angsuran dengan due_date > settlement date dan
 *   bunga outstanding > 0 (deterministik dari jadwal persisted).
 * - Discount base = bunga MASA DEPAN (remaining interest) — bunga jatuh
 *   tempo TIDAK pernah didiskon (AC5).
 * - Tidak ada policy yang cocok → SettlementContractIncomplete (dilarang
 *   diam-diam memakai 0%). Tidak ada persentase default yang di-seed.
 * - Snapshot pada baris settlement: policy lama tidak menghitung ulang
 *   settlement lama (AC6).
 *
 * PENALTY: custom fillable, diisi manual saat pengajuan; tidak pernah
 * dihitung otomatis dari persentase/hari/principal/bunga (AC8).
 *
 * OVERPAYMENT (T89C/T89E): pembayaran > settlement amount dihitung saat
 * completion sebagai `payment - settlement_amount` lalu diposting ke
 * Simpanan Pokok koperasi existing (SavingProduct::CATEGORY_PRINCIPAL)
 * milik anggota sumber — resolusi SERVER-SIDE settlement → loan → customer
 * → member, TIDAK pernah dari input frontend. Lebih bayar TIDAK mengurangi
 * principal/bunga/angsuran/settlement amount, TIDAK menjadi saldo
 * unallocated permanen, TIDAK membuat SHM Savings. Posting idempoten
 * (idempotency_key deterministik per settlement) dan berada di transaksi
 * yang sama dengan completion + penutupan pinjaman: posting gagal →
 * rollback penuh tanpa commit parsial. Bila hubungan ShmCustomer → Member
 * tidak tersedia / tidak ditemukan / nonaktif, completion DITOLAK eksplisit
 * (AC11) — TIDAK membuat member, TIDAK mengonversi nasabah SHM, TIDAK
 * memilih penerima lain.
 *
 * AMAN FINANSIAL: nominal principal/pembayaran/alokasi historis tidak
 * pernah diubah; komponen settlement berasal dari persisted state; tidak
 * ada jurnal akuntansi baru; tidak ada Collection (T90).
 */
class ShmLoanSettlementService
{
    /** Batas plafon awal untuk kelayakan potongan bunga (T89A). */
    public const DISCOUNT_THRESHOLD = 50_000_000;

    /** Percobaan pembuatan nomor referensi sebelum dianggap gagal. */
    private const REFERENCE_ATTEMPTS = 5;

    public function __construct(
        private readonly ShmSettlementDiscountPolicyService $policies,
        // T006E — Layanan integrasi jurnal akuntansi otomatis SHM
        private readonly ShmAutomaticJournalService $autoJournalService,
    ) {}

    // ==================================================================
    // PREVIEW (read-only)
    // ==================================================================

    /**
     * PREVIEW kewajiban pelunasan — tidak pernah menulis apa pun.
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException bila pinjaman tidak layak / konfigurasi policy bentrok
     */
    public function preview(ShmLoan $loan): array
    {
        $this->guardEligible($loan);

        $components = $this->components($loan, now()->startOfDay());
        $discount = $this->resolveDiscount($loan, $components, now()->startOfDay());

        $settlementAmount = $discount['settlement_amount'] === null
            ? null
            : $this->money(
                (float) $components['outstanding_principal']
                + (float) $components['outstanding_interest_due']
                + (float) $discount['interest_after_discount']
            );

        $grossSettlement = $settlementAmount === null ? null : (float) $settlementAmount;
        $availableUnallocated = (float) $components['unallocated_payment'];
        $appliedUnallocated = $grossSettlement === null ? 0.0 : min($availableUnallocated, $grossSettlement);
        $netCashDue = $grossSettlement === null ? null : max(0.0, $grossSettlement - $appliedUnallocated);
        $remainingUnallocated = max(0.0, $availableUnallocated - $appliedUnallocated);

        return array_merge($components, $discount, [
            'as_of' => now()->toDateString(),
            'outstanding_penalty' => $this->money(0.0), // preview: penalty diinput saat pengajuan
            'settlement_amount' => $settlementAmount,
            'applied_unallocated_payment' => $this->money($appliedUnallocated),
            'net_cash_due' => $netCashDue === null ? null : $this->money($netCashDue),
            'remaining_unallocated_payment' => $this->money($remainingUnallocated),
            'blocked_items' => $this->blockedItems(),
            'pending_notes' => [
                'PENDING BUSINESS CONFIRMATION — settlement tolerance (tidak dipakai: pembayaran harus tepat memenuhi kewajiban).',
                'Overpayment (pembayaran > kewajiban) diakui sebagai Bunga Pendapatan pada akuntansi SHM (COA 4-1101 Pendapatan Bunga SHM).',
                'PENDING BUSINESS CONFIRMATION — approval authority (role) final.',
                'PENDING BUSINESS CONFIRMATION — kanal & field wajib pembayaran pelunasan.',
            ],
        ]);
    }

    // ==================================================================
    // WORKFLOW
    // ==================================================================

    /**
     * Pengajuan pelunasan: buat settlement PENDING + snapshot policy.
     *
     * @param  array{penalty?: numeric, notes?: ?string}  $data
     *
     * @throws ValidationException
     * @throws SettlementContractIncomplete bila policy diskon belum dikonfigurasi
     */
    public function request(ShmLoan $loan, array $data, int $actorId): ShmLoanSettlement
    {
        return DB::transaction(function () use ($loan, $data, $actorId): ShmLoanSettlement {
            $locked = ShmLoan::query()->lockForUpdate()->findOrFail($loan->id);
            $this->guardEligible($locked);

            $existing = $this->settlementOf($locked->id, true);
            if ($existing !== null && ! in_array($existing->status, [ShmLoanSettlement::STATUS_REJECTED, ShmLoanSettlement::STATUS_REVERSED], true)) {
                throw ValidationException::withMessages([
                    'loan' => sprintf(
                        'Pinjaman %s sudah memiliki pengajuan pelunasan berstatus "%s".',
                        $locked->loan_number,
                        $existing->status,
                    ),
                ]);
            }

            $penalty = $data['penalty'] ?? 0;
            if (! is_numeric($penalty) || (float) $penalty < 0) {
                throw ValidationException::withMessages([
                    'penalty' => 'Penalty harus berupa angka >= 0 (diisi manual, tidak dihitung otomatis).',
                ]);
            }

            $components = $this->components($locked, now()->startOfDay());
            $discount = $this->resolveDiscount($locked, $components, now()->startOfDay());

            if ($discount['settlement_amount'] === null) {
                // Dilarang menghitung dengan 0% diam-diam (AC/Q).
                throw new SettlementContractIncomplete($locked, [
                    $discount['error'] ?? 'policy diskon bunga pelunasan belum dikonfigurasi.',
                ]);
            }

            $settlementAmount = $this->money(
                (float) $components['outstanding_principal']
                + (float) $components['outstanding_interest_due']
                + (float) $discount['interest_after_discount']
                + (float) $penalty
            );

            $availableUnallocated = (float) $components['unallocated_payment'];
            $appliedUnallocated = min($availableUnallocated, (float) $settlementAmount);
            $netCashDue = max(0.0, (float) $settlementAmount - $appliedUnallocated);

            $settlement = ShmLoanSettlement::query()->create([
                'shm_loan_id' => $locked->id,
                'settlement_reference' => $this->generateReference(),
                'settlement_date' => now()->toDateString(),
                'outstanding_principal' => $components['outstanding_principal'],
                'outstanding_interest' => $components['outstanding_interest_due'],
                'outstanding_penalty' => $this->money((float) $penalty),
                'unallocated_payment_amount' => $components['unallocated_payment'],
                'applied_unallocated_amount' => $this->money($appliedUnallocated),
                'settlement_amount' => $settlementAmount,
                'net_cash_due' => $this->money($netCashDue),
                'overpayment_amount' => $this->money(0.0),
                'status' => ShmLoanSettlement::STATUS_PENDING,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actorId,
                // Snapshot policy & komponen discount (immutable — AC6).
                'discount_policy_id' => $discount['policy_id'],
                'discount_eligible' => $discount['eligible'],
                'discount_applied' => $discount['applied'],
                'remaining_months' => $components['remaining_months'],
                'discount_percent' => $discount['percent'],
                'remaining_interest_amount' => $components['remaining_interest'],
                'discount_base_amount' => $discount['base'],
                'discount_amount' => $discount['amount'],
                'interest_after_discount_amount' => $discount['interest_after_discount'],
            ]);

            AuditLogger::log(
                'shm.loan.settlement.requested',
                ShmLoanSettlement::class,
                $settlement->id,
                $settlement->settlement_reference,
                sprintf(
                    'Pengajuan pelunasan %s untuk pinjaman %s — kewajiban Rp %s.',
                    $settlement->settlement_reference,
                    $locked->loan_number,
                    number_format((float) $settlement->settlement_amount, 0, ',', '.'),
                ),
                $this->settlementMetadata($locked, $settlement),
            );

            return $settlement;
        });
    }

    /**
     * Approval settlement: pending → approved.
     *
     * @throws ValidationException
     */
    public function approve(ShmLoan $loan, int $actorId): ShmLoanSettlement
    {
        return DB::transaction(function () use ($loan): ShmLoanSettlement {
            $lockedLoan = ShmLoan::query()->lockForUpdate()->findOrFail($loan->id);
            $settlement = $this->lockedSettlementOf($lockedLoan->id);
            $this->guardStatus($settlement, ShmLoanSettlement::STATUS_PENDING, 'disetujui');

            $settlement->status = ShmLoanSettlement::STATUS_APPROVED;
            $settlement->save();

            AuditLogger::log(
                'shm.loan.settlement.approved',
                ShmLoanSettlement::class,
                $settlement->id,
                $settlement->settlement_reference,
                sprintf('Pelunasan %s disetujui untuk pinjaman %s.', $settlement->settlement_reference, $lockedLoan->loan_number),
                $this->settlementMetadata($lockedLoan, $settlement),
            );

            return $settlement;
        });
    }

    /**
     * Penolakan settlement: pending → rejected.
     *
     * @throws ValidationException
     */
    public function reject(ShmLoan $loan, ?string $reason, int $actorId): ShmLoanSettlement
    {
        return DB::transaction(function () use ($loan, $reason): ShmLoanSettlement {
            $lockedLoan = ShmLoan::query()->lockForUpdate()->findOrFail($loan->id);
            $settlement = $this->lockedSettlementOf($lockedLoan->id);
            $this->guardStatus($settlement, ShmLoanSettlement::STATUS_PENDING, 'ditolak');

            $settlement->status = ShmLoanSettlement::STATUS_REJECTED;
            $settlement->save();

            // Lepaskan alokasi penggunaan titipan jika pernah dibuat
            ShmLoanSettlementUnallocatedUsage::query()
                ->where('shm_loan_settlement_id', $settlement->id)
                ->where('status', ShmLoanSettlementUnallocatedUsage::STATUS_ACTIVE)
                ->update(['status' => ShmLoanSettlementUnallocatedUsage::STATUS_RELEASED]);

            AuditLogger::log(
                'shm.loan.settlement.rejected',
                ShmLoanSettlement::class,
                $settlement->id,
                $settlement->settlement_reference,
                sprintf('Pelunasan %s ditolak untuk pinjaman %s.', $settlement->settlement_reference, $lockedLoan->loan_number),
                array_merge($this->settlementMetadata($lockedLoan, $settlement), [
                    'reason' => $reason,
                ]),
            );

            return $settlement;
        });
    }

    /**
     * Tautkan pembayaran pelunasan (Payment T86 yang sudah tercatat):
     * approved → payment_recorded. BELUM dianggap verifikasi.
     *
     * @throws ValidationException
     */
    public function recordPayment(ShmLoan $loan, int $paymentId, int $actorId): ShmLoanSettlement
    {
        return DB::transaction(function () use ($loan, $paymentId, $actorId): ShmLoanSettlement {
            // T15I Financial Actor Row Locking
            $actor = DB::table('users')->where('id', $actorId)->lockForUpdate()->first();
            if (! $actor || ! $actor->is_active) {
                abort(403, 'Aktor tidak aktif atau tidak ditemukan.');
            }

            $lockedLoan = ShmLoan::query()->lockForUpdate()->findOrFail($loan->id);
            $settlement = $this->lockedSettlementOf($lockedLoan->id);

            // Boleh dari approved ATAU payment_recorded (perbaikan pembayaran
            // yang nominalnya kurang saat verifikasi).
            if (! in_array($settlement->status, [
                ShmLoanSettlement::STATUS_APPROVED,
                ShmLoanSettlement::STATUS_PAYMENT_RECORDED,
            ], true)) {
                $this->guardStatus($settlement, ShmLoanSettlement::STATUS_APPROVED, 'menautkan pembayaran');
            }

            $payment = ShmLoanPayment::query()->lockForUpdate()->findOrFail($paymentId);

            if ($payment->shm_loan_id !== $lockedLoan->id) {
                throw ValidationException::withMessages([
                    'payment' => 'Pembayaran bukan milik pinjaman ini.',
                ]);
            }

            if ($payment->status !== ShmLoanPayment::STATUS_POSTED) {
                throw ValidationException::withMessages([
                    'payment' => 'Hanya pembayaran berstatus tercatat (posted) yang dapat ditautkan.',
                ]);
            }

            // T108 — BATAS TRANSAKSI settlement vs angsuran: pembayaran yang
            // SUDAH teralokasi ke angsuran adalah pembayaran REGULER —
            // uangnya sudah mengurangi kewajiban angsuran. Menautkannya
            // sebagai pembayaran pelunasan berarti uang yang sama terhitung
            // dua kali (alokasi angsuran + pemenuhan settlement). Ditolak
            // eksplisit; petugas harus memakai pembayaran baru.
            if ($payment->allocations()->exists()) {
                throw ValidationException::withMessages([
                    'payment' => 'Pembayaran tersebut sudah teralokasi ke angsuran dan tidak dapat ditautkan sebagai pembayaran pelunasan. Catat pembayaran baru untuk pelunasan.',
                ]);
            }

            $used = ShmLoanSettlement::query()
                ->where('shm_loan_payment_id', $payment->id)
                ->where('id', '!=', $settlement->id)
                ->exists();

            if ($used) {
                throw ValidationException::withMessages([
                    'payment' => 'Pembayaran tersebut sudah ditautkan ke settlement lain.',
                ]);
            }

            // T89B / T158: Bila pembayaran settlement digantikan, lepas pembayaran lama kembali ke domain reguler.
            $previousPaymentId = $settlement->shm_loan_payment_id;
            if ($previousPaymentId !== null && $previousPaymentId !== $payment->id) {
                $previousPayment = ShmLoanPayment::query()->lockForUpdate()->find($previousPaymentId);
                if ($previousPayment !== null && $previousPayment->settlement_released_at === null) {
                    $previousPayment->settlement_released_at = now();
                    $previousPayment->save();

                    AuditLogger::log(
                        'shm.loan.payment.settlement_released',
                        ShmLoanPayment::class,
                        $previousPayment->id,
                        $previousPayment->payment_reference,
                        sprintf(
                            'Pembayaran %s dilepas dari pelunasan %s kembali ke domain reguler karena digantikan oleh pembayaran %s.',
                            $previousPayment->payment_reference,
                            $settlement->settlement_reference,
                            $payment->payment_reference,
                        ),
                        [
                            'loan_id' => $lockedLoan->id,
                            'loan_number' => $lockedLoan->loan_number,
                            'payment_id' => $previousPayment->id,
                            'payment_reference' => $previousPayment->payment_reference,
                            'payment_amount' => (string) $previousPayment->amount,
                            'settlement_id' => $settlement->id,
                            'settlement_reference' => $settlement->settlement_reference,
                            'replacement_payment_id' => $payment->id,
                            'replacement_payment_reference' => $payment->payment_reference,
                            'released_at' => now()->toIso8601String(),
                        ],
                    );
                }
            }

            $settlement->shm_loan_payment_id = $payment->id;
            $settlement->payment_recorded_at = now();
            $settlement->status = ShmLoanSettlement::STATUS_PAYMENT_RECORDED;
            $settlement->save();

            AuditLogger::log(
                'shm.loan.settlement.payment_recorded',
                ShmLoanSettlement::class,
                $settlement->id,
                $settlement->settlement_reference,
                sprintf(
                    'Pembayaran %s (Rp %s) ditautkan ke pelunasan %s — menunggu verifikasi.',
                    $payment->payment_reference,
                    number_format((float) $payment->amount, 0, ',', '.'),
                    $settlement->settlement_reference,
                ),
                array_merge($this->settlementMetadata($lockedLoan, $settlement), [
                    'payment_reference' => $payment->payment_reference,
                    'payment_amount' => (string) $payment->amount,
                ]),
            );

            return $settlement;
        });
    }

    /**
     * Verifikasi EKSPLISIT pembayaran pelunasan: payment_recorded →
     * payment_verified (atau approved → payment_verified untuk zero-cash settlement).
     * `posted` saja TIDAK cukup (created ≠ verified).
     *
     * @throws ValidationException
     */
    public function verifyPayment(ShmLoan $loan, int $actorId): ShmLoanSettlement
    {
        return DB::transaction(function () use ($loan, $actorId): ShmLoanSettlement {
            $lockedLoan = ShmLoan::query()->lockForUpdate()->findOrFail($loan->id);
            $settlement = $this->lockedSettlementOf($lockedLoan->id);

            // Boleh diverifikasi dari payment_recorded, ATAU dari approved jika net_cash_due <= 0.
            if ($settlement->status === ShmLoanSettlement::STATUS_APPROVED) {
                // Hitung ulang saldo titipan aktual
                $components = $this->components($lockedLoan, now()->startOfDay());
                $availableUnallocated = (float) $components['unallocated_payment'];
                $grossObligation = (float) $settlement->settlement_amount;
                $appliedUnallocated = min($availableUnallocated, $grossObligation);
                $netCashDue = max(0.0, $grossObligation - $appliedUnallocated);

                if ($netCashDue > 0.005) {
                    $this->guardStatus($settlement, ShmLoanSettlement::STATUS_PAYMENT_RECORDED, 'memverifikasi pembayaran');
                }
            } else {
                $this->guardStatus($settlement, ShmLoanSettlement::STATUS_PAYMENT_RECORDED, 'memverifikasi pembayaran');
            }

            if ($settlement->shm_loan_payment_id !== null) {
                $payment = $this->lockedPaymentOf($settlement);

                // Validasi kas baru terhadap net_cash_due aktual
                $netDue = (float) $settlement->net_cash_due;
                if ((float) $payment->amount + 0.005 < $netDue) {
                    throw ValidationException::withMessages([
                        'payment' => sprintf(
                            'Pembayaran Rp %s kurang dari kewajiban settlement Rp %s.',
                            number_format((float) $payment->amount, 2, ',', '.'),
                            number_format($netDue, 2, ',', '.'),
                        ),
                    ]);
                }
            }

            $settlement->payment_verified_at = now();
            $settlement->payment_verified_by = $actorId;
            $settlement->status = ShmLoanSettlement::STATUS_PAYMENT_VERIFIED;
            $settlement->save();

            AuditLogger::log(
                'shm.loan.settlement.payment_verified',
                ShmLoanSettlement::class,
                $settlement->id,
                $settlement->settlement_reference,
                sprintf('Pembayaran pelunasan %s diverifikasi.', $settlement->settlement_reference),
                array_merge($this->settlementMetadata($lockedLoan, $settlement), [
                    'payment_reference' => $settlement->payment?->payment_reference,
                    'payment_amount' => $settlement->payment ? (string) $settlement->payment->amount : '0.00',
                ]),
            );

            return $settlement;
        });
    }

    /**
     * Penyelesaian: payment_verified → completed + tutup pinjaman.
     *
     * Idempoten: panggilan ulang pada settlement completed mengembalikan
     * baris yang sama tanpa audit/penutupan ganda. Dua request paralel
     * diproses berurutan oleh lock; partial unique index pada
     * (shm_loan_id) WHERE status='completed' adalah lapisan database.
     *
     * @throws ValidationException
     * @throws SettlementContractIncomplete bila hubungan member sumber overpayment tidak tersedia
     */
    public function complete(ShmLoan $loan, int $actorId): ShmLoanSettlement
    {
        return DB::transaction(function () use ($loan, $actorId): ShmLoanSettlement {
            // T15I Financial Actor Row Locking
            $actor = DB::table('users')->where('id', $actorId)->lockForUpdate()->first();
            if (! $actor || ! $actor->is_active) {
                abort(403, 'Aktor tidak aktif atau tidak ditemukan.');
            }

            $lockedLoan = ShmLoan::query()->lockForUpdate()->findOrFail($loan->id);
            $settlement = $this->lockedSettlementOf($lockedLoan->id);

            // Idempotensi: sudah selesai → kembalikan tanpa efek ganda.
            if ($settlement->status === ShmLoanSettlement::STATUS_COMPLETED) {
                return $settlement;
            }

            $this->guardStatus($settlement, ShmLoanSettlement::STATUS_PAYMENT_VERIFIED, 'menyelesaikan');

            if ($lockedLoan->status !== ShmLoan::STATUS_ACTIVE) {
                throw ValidationException::withMessages([
                    'loan' => 'Pinjaman tidak berstatus aktif sehingga settlement tidak dapat diselesaikan.',
                ]);
            }

            // Hitung ulang saldo titipan aktual pada pinjaman ini & lock payments sumber
            $payments = ShmLoanPayment::query()
                ->where('shm_loan_id', $lockedLoan->id)
                ->where('status', ShmLoanPayment::STATUS_POSTED)
                ->whereNull('settlement_released_at')
                ->lockForUpdate()
                ->orderBy('id')
                ->get();

            $grossSettlementObligation = (float) $settlement->settlement_amount;
            $remainingNeeded = $grossSettlementObligation;
            $appliedUnallocated = 0.0;

            // Hapus atau batalkan penggunaan sebelumnya jika pernah dicatat pada settlement ini (idempotensi)
            ShmLoanSettlementUnallocatedUsage::query()
                ->where('shm_loan_settlement_id', $settlement->id)
                ->delete();

            foreach ($payments as $srcPayment) {
                // Jangan gunakan payment pelunasan yang saat ini ditautkan sebagai sumber titipan angsuran
                if ($settlement->shm_loan_payment_id === $srcPayment->id) {
                    continue;
                }

                $availableInPayment = (float) $srcPayment->unallocatedAmount();
                if ($availableInPayment > 0.005 && $remainingNeeded > 0.005) {
                    $useAmount = min($availableInPayment, $remainingNeeded);
                    ShmLoanSettlementUnallocatedUsage::create([
                        'shm_loan_settlement_id' => $settlement->id,
                        'shm_loan_payment_id' => $srcPayment->id,
                        'amount' => $this->money($useAmount),
                        'status' => ShmLoanSettlementUnallocatedUsage::STATUS_ACTIVE,
                        'created_by' => $actorId,
                    ]);

                    $appliedUnallocated += $useAmount;
                    $remainingNeeded -= $useAmount;
                }
            }

            $netCashDue = max(0.0, round($grossSettlementObligation - $appliedUnallocated, 2));

            $payment = null;
            if ($settlement->shm_loan_payment_id !== null) {
                $payment = $this->lockedPaymentOf($settlement);
            }

            if ($netCashDue > 0.005 && $payment === null) {
                throw ValidationException::withMessages([
                    'payment' => 'Settlement ini memerlukan pembayaran kas baru, namun belum ada pembayaran yang ditautkan.',
                ]);
            }

            $paidCashAmount = $payment !== null ? (float) $payment->amount : 0.0;
            $overpayment = round($paidCashAmount - $netCashDue, 2);

            if ($overpayment < -0.005) {
                throw ValidationException::withMessages([
                    'payment' => sprintf(
                        'Pembayaran kas baru Rp %s kurang dari sisa tagihan settlement Rp %s sehingga tidak dapat diselesaikan.',
                        number_format($paidCashAmount, 2, ',', '.'),
                        number_format($netCashDue, 2, ',', '.'),
                    ),
                ]);
            }

            // 1. Settlementkan seluruh sisa angsuran (kewajiban dianggap
            //    selesai: sebagian tunai + sebagian titipan + sebagian potongan discount yang
            //    terekam pada settlement). Komponen jadwal TIDAK diubah.
            $installments = $lockedLoan->installments()
                ->where('status', '!=', ShmLoanInstallment::STATUS_PAID)
                ->orderBy('installment_number')
                ->lockForUpdate()
                ->get();

            foreach ($installments as $installment) {
                $installment->paid_interest_amount = $installment->interest_amount;
                $installment->paid_principal_amount = $installment->principal_amount;
                $installment->paid_amount = $installment->total_amount;
                $installment->remaining_amount = 0;
                $installment->status = ShmLoanInstallment::STATUS_PAID;
                $installment->paid_at = now();
                $installment->save();
            }

            // 2. Settlement completed (SATU audit completion).
            $settlement->applied_unallocated_amount = $this->money($appliedUnallocated);
            $settlement->net_cash_due = $this->money($netCashDue);
            $settlement->overpayment_amount = $this->money($overpayment > 0.005 ? $overpayment : 0.0);
            $settlement->status = ShmLoanSettlement::STATUS_COMPLETED;
            $settlement->save();

            // Automatic journal posting dinonaktifkan sesuai ticket refactor SHM Accounting.
            // Pencatatan akuntansi kini dilakukan via import XLSX berstatus DRAFT dan diposting manual.
            // $this->autoJournalService->createSettlementJournal($settlement, $actorId);

            AuditLogger::log(
                'shm.loan.settled',
                ShmLoanSettlement::class,
                $settlement->id,
                $settlement->settlement_reference,
                sprintf(
                    'Pelunasan %s pinjaman %s selesai — Rp %s.',
                    $settlement->settlement_reference,
                    $lockedLoan->loan_number,
                    number_format((float) $settlement->settlement_amount, 0, ',', '.'),
                ),
                $this->settlementMetadata($lockedLoan, $settlement),
            );

            // 3. Tutup pinjaman HANYA di sini (setelah completed).
            $previousStatus = $lockedLoan->status;
            $lockedLoan->status = ShmLoan::STATUS_CLOSED;
            $lockedLoan->save();

            AuditLogger::log(
                'shm.loan.closed',
                ShmLoan::class,
                $lockedLoan->id,
                $lockedLoan->loan_number,
                sprintf('Pinjaman %s ditutup setelah pelunasan selesai.', $lockedLoan->loan_number),
                [
                    'settlement_reference' => $settlement->settlement_reference,
                    'previous_status' => $previousStatus,
                    'new_status' => ShmLoan::STATUS_CLOSED,
                ],
            );

            return $settlement;
        });
    }

    // ==================================================================
    // KOMPONEN & DISCOUNT
    // ==================================================================

    /**
     * Komponen kewajiban dari persisted state (jadwal + alokasi T87).
     *
     * @return array<string, mixed>
     */
    private function components(ShmLoan $loan, Carbon $asOf): array
    {
        $outstandingPrincipal = 0.0;
        $dueInterest = 0.0;
        $remainingInterest = 0.0;
        $remainingMonths = 0;

        foreach ($loan->installments as $installment) {
            $outstandingPrincipal += max(
                0.0,
                (float) $installment->principal_amount - (float) $installment->paid_principal_amount,
            );

            $interestOutstanding = max(
                0.0,
                (float) $installment->interest_amount - (float) $installment->paid_interest_amount,
            );

            if ($installment->due_date !== null && $installment->due_date->lte($asOf)) {
                $dueInterest += $interestOutstanding;
            } else {
                $remainingInterest += $interestOutstanding;
                if ($interestOutstanding > 0) {
                    $remainingMonths++;
                }
            }
        }

        $totalPayments = (float) ShmLoanPayment::query()
            ->where('shm_loan_id', $loan->id)
            ->where('status', ShmLoanPayment::STATUS_POSTED)
            ->sum('amount');

        $totalAllocated = (float) ShmLoanPaymentAllocation::query()
            ->whereIn(
                'shm_loan_payment_id',
                ShmLoanPayment::query()->where('shm_loan_id', $loan->id)->select('id'),
            )
            ->sum('total_amount');

        $totalConsumedInSettlement = (float) ShmLoanSettlementUnallocatedUsage::query()
            ->whereIn(
                'shm_loan_payment_id',
                ShmLoanPayment::query()->where('shm_loan_id', $loan->id)->select('id'),
            )
            ->where('status', ShmLoanSettlementUnallocatedUsage::STATUS_ACTIVE)
            ->sum('amount');

        return [
            'outstanding_principal' => $this->money($outstandingPrincipal),
            'outstanding_interest_due' => $this->money($dueInterest),
            'remaining_interest' => $this->money($remainingInterest),
            'remaining_months' => $remainingMonths,
            'unallocated_payment' => $this->money(max(0.0, $totalPayments - $totalAllocated - $totalConsumedInSettlement)),
        ];
    }

    /**
     * Resolusi discount untuk satu pinjaman pada tanggal settlement.
     *
     * @param  array<string, mixed>  $components
     * @return array<string, mixed>
     *
     * @throws ValidationException bila konfigurasi policy bentrok
     */
    private function resolveDiscount(ShmLoan $loan, array $components, Carbon $asOf): array
    {
        $remainingInterest = (float) $components['remaining_interest'];
        $remainingMonths = (int) $components['remaining_months'];
        $eligible = $remainingInterest > self::DISCOUNT_THRESHOLD;

        // Tidak ada bunga masa depan → tidak ada yang didiskon (bukan "0%
        // diam-diam": memang tidak ada base).
        if ($remainingInterest <= 0) {
            return [
                'eligible' => $eligible,
                'applied' => false,
                'discount_status' => $eligible ? 'not_applicable_no_remaining_interest' : 'not_eligible',
                'error' => null,
                'policy_id' => null,
                'policy_label' => null,
                'percent' => null,
                'base' => $this->money(0.0),
                'amount' => $this->money(0.0),
                'discount_amount' => $this->money(0.0),
                'interest_after_discount' => $this->money(0.0),
                'settlement_amount' => '0.00', // sinyal komputabel — caller menambah komponen lain
            ];
        }

        if (! $eligible) {
            // Loan <= threshold: bunga flat penuh tanpa potongan (T89A #3).
            return [
                'eligible' => false,
                'applied' => false,
                'discount_status' => 'not_eligible',
                'error' => null,
                'policy_id' => null,
                'policy_label' => null,
                'percent' => null,
                'base' => $this->money($remainingInterest),
                'amount' => $this->money(0.0),
                'discount_amount' => $this->money(0.0),
                'interest_after_discount' => $this->money($remainingInterest),
                'settlement_amount' => '0.00',
            ];
        }

        $policy = $this->policies->selectFor($remainingMonths, $asOf);

        if ($policy === null) {
            $message = sprintf(
                'policy diskon bunga pelunasan untuk sisa %d bulan belum dikonfigurkan (pinjaman > Rp%s eligible; dilarang memakai 0%% diam-diam).',
                $remainingMonths,
                number_format(self::DISCOUNT_THRESHOLD, 0, ',', '.'),
            );

            return [
                'eligible' => true,
                'applied' => false,
                'discount_status' => 'no_policy',
                'error' => $message,
                'policy_id' => null,
                'policy_label' => null,
                'percent' => null,
                'base' => $this->money($remainingInterest),
                'amount' => null,
                'discount_amount' => null,
                'interest_after_discount' => null,
                'settlement_amount' => null, // TIDAK dapat dihitung → request ditolak
            ];
        }

        $percent = (float) $policy->discount_percent;
        // Pembulatan rupiah terdekat — konsisten dengan jadwal angsuran.
        $discountAmount = round($remainingInterest * $percent / 100, 0, PHP_ROUND_HALF_UP);

        return [
            'eligible' => true,
            'applied' => true,
            'discount_status' => 'applied',
            'error' => null,
            'policy_id' => $policy->id,
            'policy_label' => $policy->rangeLabel(),
            'percent' => number_format($percent, 2, '.', ''),
            'base' => $this->money($remainingInterest),
            'amount' => $this->money($discountAmount),
            'discount_amount' => $this->money($discountAmount),
            'interest_after_discount' => $this->money($remainingInterest - $discountAmount),
            'settlement_amount' => '0.00',
        ];
    }

    // ==================================================================
    // GUARD & HELPERS
    // ==================================================================

    /**
     * Guard kelayakan pinjaman untuk seluruh operasi settlement.
     *
     * @throws ValidationException
     */
    private function guardEligible(ShmLoan $loan): void
    {
        if ($loan->disbursed_at === null) {
            throw ValidationException::withMessages([
                'loan' => sprintf('Pinjaman %s belum dicairkan sehingga tidak dapat dipelunasi.', $loan->loan_number),
            ]);
        }

        if ($loan->status !== ShmLoan::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'loan' => sprintf('Pinjaman %s sudah ditutup sehingga tidak dapat dipelunasi.', $loan->loan_number),
            ]);
        }

        if ($loan->installments()->count() === 0) {
            throw ValidationException::withMessages([
                'loan' => sprintf(
                    'Pinjaman %s belum memiliki jadwal angsuran sehingga state finansial tidak valid untuk pelunasan.',
                    $loan->loan_number,
                ),
            ]);
        }
    }

    /**
     * Settlement terbaru untuk sebuah pinjaman (dikunci bila $lock).
     */
    private function settlementOf(int $loanId, bool $lock = false): ?ShmLoanSettlement
    {
        return ShmLoanSettlement::query()
            ->where('shm_loan_id', $loanId)
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Settlement terbaru yang wajib ada untuk operasi workflow.
     *
     * @throws ValidationException
     */
    private function lockedSettlementOf(int $loanId): ShmLoanSettlement
    {
        $settlement = $this->settlementOf($loanId, true);

        if ($settlement === null) {
            throw ValidationException::withMessages([
                'settlement' => 'Belum ada pengajuan pelunasan untuk pinjaman ini.',
            ]);
        }

        return $settlement;
    }

    /**
     * Guard status tunggal untuk transisi workflow.
     *
     * @throws ValidationException
     */
    private function guardStatus(ShmLoanSettlement $settlement, string $required, string $action): void
    {
        if ($settlement->status !== $required) {
            throw ValidationException::withMessages([
                'settlement' => sprintf(
                    'Pelunasan %s berstatus "%s" — hanya status "%s" yang dapat %s.',
                    $settlement->settlement_reference,
                    $settlement->status,
                    $required,
                    $action,
                ),
            ]);
        }
    }

    /**
     * Pembayaran yang ditautkan, dalam keadaan terkunci & masih posted.
     *
     * @throws ValidationException
     */
    private function lockedPaymentOf(ShmLoanSettlement $settlement): ShmLoanPayment
    {
        if ($settlement->shm_loan_payment_id === null) {
            throw ValidationException::withMessages([
                'payment' => 'Belum ada pembayaran pelunasan yang ditautkan.',
            ]);
        }

        $payment = ShmLoanPayment::query()
            ->lockForUpdate()
            ->findOrFail($settlement->shm_loan_payment_id);

        if ($payment->status !== ShmLoanPayment::STATUS_POSTED) {
            throw ValidationException::withMessages([
                'payment' => 'Pembayaran pelunasan tidak lagi berstatus tercatat (posted).',
            ]);
        }

        return $payment;
    }

    /**
     * Metadata audit settlement (komponen + snapshot discount).
     *
     * @return array<string, mixed>
     */
    private function settlementMetadata(ShmLoan $loan, ShmLoanSettlement $settlement): array
    {
        return [
            'shm_loan_id' => $loan->id,
            'loan_number' => $loan->loan_number,
            'settlement_reference' => $settlement->settlement_reference,
            'settlement_date' => $settlement->settlement_date?->toDateString(),
            'status' => $settlement->status,
            'outstanding_principal' => (string) $settlement->outstanding_principal,
            'outstanding_interest' => (string) $settlement->outstanding_interest,
            'outstanding_penalty' => (string) $settlement->outstanding_penalty,
            'unallocated_payment' => (string) $settlement->unallocated_payment_amount,
            'applied_unallocated_amount' => (string) $settlement->applied_unallocated_amount,
            'settlement_amount' => (string) $settlement->settlement_amount,
            'net_cash_due' => (string) $settlement->net_cash_due,
            'overpayment_amount' => (string) $settlement->overpayment_amount,
            'discount_eligible' => $settlement->discount_eligible,
            'discount_applied' => $settlement->discount_applied,
            'discount_policy_id' => $settlement->discount_policy_id,
            'remaining_months' => $settlement->remaining_months,
            'discount_percent' => $settlement->discount_percent !== null ? (string) $settlement->discount_percent : null,
            'discount_base_amount' => $settlement->discount_base_amount !== null ? (string) $settlement->discount_base_amount : null,
            'discount_amount' => $settlement->discount_amount !== null ? (string) $settlement->discount_amount : null,
            'previous_loan_status' => $loan->status,
        ];
    }

    /**
     * Nomor referensi settlement server-generated (SHM-SET-YYYYMMDD-XXXXXXXX).
     *
     * @throws RuntimeException bila setelah beberapa percobaan tetap bentrok
     */
    private function generateReference(): string
    {
        for ($attempt = 0; $attempt < self::REFERENCE_ATTEMPTS; $attempt++) {
            $candidate = sprintf(
                'SHM-SET-%s-%s',
                now()->format('Ymd'),
                strtoupper(bin2hex(random_bytes(4))),
            );

            if (! ShmLoanSettlement::query()->where('settlement_reference', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new RuntimeException('Referensi settlement SHM tidak dapat dibuat setelah beberapa percobaan.');
    }

    /**
     * Item kontrak settlement yang masih PENDING (tampil di UI sebagai
     * catatan transparansi; tidak lagi menjadi gerbang blokir total).
     *
     * @return array<int, string>
     */
    public function blockedItems(): array
    {
        return array_values(array_filter(
            (array) config('shm-business-rule-contract.settlement.blocked_by', []),
            fn ($item): bool => is_string($item) && trim($item) !== '',
        ));
    }

    /**
     * Format nominal finansial presisi desimal (18,2).
     */
    private function money(float $amount): string
    {
        return number_format(max(0.0, $amount), 2, '.', '');
    }
}
