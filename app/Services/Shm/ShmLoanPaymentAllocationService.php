<?php

namespace App\Services\Shm;

use App\Models\ShmLoan;
use App\Models\ShmLoanInstallment;
use App\Models\ShmLoanPayment;
use App\Models\ShmLoanPaymentAllocation;
use App\Models\ShmLoanSettlement;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ticket 87 — Alokasi pembayaran pinjaman SHM ke angsuran.
 *
 * Source of truth alokasi. SATU transaksi database berisi: lock baris
 * pembayaran (lockForUpdate), lock baris pinjaman, guard kelayakan, lock
 * angsuran secara deterministik (installment_number ASC), alokasi
 * kronologis dengan urutan penalty → interest → principal, pembaruan
 * state angsuran, tepat SATU baris audit, dan commit. Bila salah satu
 * bagian gagal, tidak ada satu pun bagian yang tertulis (rollback penuh).
 *
 * URUTAN ALOKASI (kontrak T79/T84):
 * 1. Penalty — T87 TIDAK PERNAH menghitung penalty. Tidak ada field
 *    penalty authoritative pada schema angsuran (formula = PENDING
 *    BUSINESS CONFIRMATION), sehingga alokasi penalty SELALU 0.
 * 2. Interest — dari nilai interest angsuran yang sudah persist (jadwal),
 *    TIDAK dihitung ulang dari rate produk.
 * 3. Principal — dari outstanding principal angsuran.
 *
 * ANGSURAN DILANJUTKAN: bila sisa pembayaran masih ada setelah satu
 * angsuran lunas, alokasi lanjut ke angsuran berikutnya secara kronologis.
 *
 * OVERPAYMENT: bila sisa pembayaran tersisa setelah SELURUH angsuran
 * teralokasi, sisa TIDAK dibuang dan TIDAK dipaksa menjadi angsuran
 * berikutnya — sisa disimpan sebagai `unallocated` pada respons dan
 * tampil eksplisit di UI. Perlakuan akhir overpayment masih PENDING
 * BUSINESS CONFIRMATION (T88 settlement/refund).
 *
 * IDEMPOTEN: memanggil allocate dua kali terhadap pembayaran yang sama
 * tidak menggandakan alokasi. Dilindungi berlapis: (1) cek sisa
 * teralokasi di dalam transaksi, (2) unique constraint (payment,
 * installment) di database untuk request paralel.
 *
 * BATAS TRANSAKSI (T108 — P1-08): alokasi HANYA menerima pembayaran
 * reguler. Pembayaran yang sudah ditautkan ke ShmLoanSettlement (T89B)
 * adalah uang pelunasan dan DITOLAK eksplisit — settlement dan angsuran
 * tidak pernah berbagi alokasi, sehingga satu rupiah tidak terhitung
 * dua kali. Discriminator = tautan `shm_loan_settlements.shm_loan_payment_id`
 * (tidak ada kolom baru; guard berjalan di dalam transaksi yang sama
 * dengan lock baris pembayaran sehingga aman terhadap request paralel).
 *
 * YANG TIDAK DILAKUKAN ticket ini (sengaja):
 * - menghitung penalty (formula belum dikonfirmasi);
 * - mengubah saldo pokok pinjaman;
 * - menutup pinjaman (settlement = T89);
 * - mengubah status pembayaran menjadi reversed (reversal = T88);
 * - membuat accounting journal / collection.
 */
class ShmLoanPaymentAllocationService
{
    /**
     * Alokasikan SATU pembayaran (status posted) ke angsuran pinjaman SHM.
     *
     * @return array{
     *     allocated: bool,
     *     allocations: int,
     *     allocated_amount: string,
     *     unallocated_amount: string,
     *     installment_ids: array<int, int>
     * }
     *
     * @throws ValidationException bila guard kelayakan menolak
     * @throws \Throwable bila alokasi/pembaruan/audit gagal (rollback penuh)
     */
    public function allocate(ShmLoanPayment $payment): array
    {
        return DB::transaction(function () use ($payment): array {
            // 1. Lock baris pembayaran — state dibaca ulang di dalam
            //    transaksi, bukan dari state UI yang mungkin sudah basi.
            $locked = ShmLoanPayment::query()->lockForUpdate()->findOrFail($payment->id);

            // 2. Guard: hanya pembayaran berstatus posted yang boleh
            //    dialokasikan. Reversed ditolak (reversal = T88).
            if ($locked->status !== ShmLoanPayment::STATUS_POSTED) {
                throw ValidationException::withMessages([
                    'payment' => sprintf(
                        'Pembayaran %s berstatus "%s" dan tidak dapat dialokasikan.',
                        $locked->payment_reference,
                        $locked->status,
                    ),
                ]);
            }

            // 2b. T108 — BATAS TRANSAKSI settlement vs angsuran: pembayaran
            //     yang sudah ditautkan ke settlement pelunasan adalah uang
            //     pelunasan, BUKAN pembayaran angsuran. Dialokasikan ke
            //     angsuran berarti uang yang sama terhitung dua kali
            //     (alokasi angsuran + pemenuhan kewajiban settlement).
            //     Ditolak eksplisit; jangan dialokasikan diam-diam.
            $settlementLink = ShmLoanSettlement::query()
                ->where('shm_loan_payment_id', $locked->id)
                ->first();

            if ($settlementLink !== null) {
                throw ValidationException::withMessages([
                    'payment' => sprintf(
                        'Pembayaran %s adalah pembayaran pelunasan (settlement %s) dan tidak dapat dialokasikan ke angsuran.',
                        $locked->payment_reference,
                        $settlementLink->settlement_reference,
                    ),
                ]);
            }

            // 3. Lock baris pinjaman pemilik pembayaran.
            $loan = ShmLoan::query()->lockForUpdate()->findOrFail($locked->shm_loan_id);

            // 4. Guard: pinjaman harus sudah dicairkan dan masih berjalan.
            if ($loan->disbursed_at === null) {
                throw ValidationException::withMessages([
                    'payment' => sprintf(
                        'Pinjaman %s belum dicairkan sehingga alokasi tidak dapat dilakukan.',
                        $loan->loan_number,
                    ),
                ]);
            }

            if ($loan->status !== ShmLoan::STATUS_ACTIVE) {
                throw ValidationException::withMessages([
                    'payment' => sprintf(
                        'Pinjaman %s sudah ditutup sehingga alokasi tidak dapat dilakukan.',
                        $loan->loan_number,
                    ),
                ]);
            }

            // 5. Guard: nominal pembayaran harus valid (> 0).
            if (! is_numeric($locked->amount) || (float) $locked->amount <= 0) {
                throw ValidationException::withMessages([
                    'payment' => 'Nominal pembayaran tidak valid sehingga alokasi tidak dapat dilakukan.',
                ]);
            }

            // 6. Hitung sisa yang belum teralokasi. Bila sudah penuh,
            //    ini adalah panggilan idempoten — kembalikan status tanpa
            //    membuat alokasi/audit baru.
            $alreadyAllocated = (float) $locked->allocations()->sum('total_amount');
            $remaining = (float) $locked->amount - $alreadyAllocated;

            if ($remaining <= 0) {
                return [
                    'allocated' => false,
                    'allocations' => 0,
                    'allocated_amount' => number_format($alreadyAllocated, 2, '.', ''),
                    'unallocated_amount' => '0.00',
                    'installment_ids' => [],
                ];
            }

            // 7. Lock angsuran secara deterministik (installment_number ASC)
            //    — dua request concurrent diproses berurutan, tidak race.
            $installments = $loan->installments()
                ->where('status', ShmLoanInstallment::STATUS_SCHEDULED)
                ->orderBy('installment_number')
                ->lockForUpdate()
                ->get();

            $created = [];
            $affectedIds = [];
            $totalAllocatedThisRun = 0.0;

            foreach ($installments as $installment) {
                if ($remaining <= 0) {
                    break;
                }

                // Urutan alokasi: penalty (selalu 0, T87) → bunga → pokok.
                // Sisa bunga/pokok dihitung dari kolom paid split; TIDAK
                // dihitung ulang dari rate produk.
                $interestOutstanding = max(
                    0.0,
                    (float) $installment->interest_amount - (float) $installment->paid_interest_amount,
                );
                $principalOutstanding = max(
                    0.0,
                    (float) $installment->principal_amount - (float) $installment->paid_principal_amount,
                );

                if ($interestOutstanding <= 0 && $principalOutstanding <= 0) {
                    continue; // Angsuran ini sudah lunas — lanjut berikutnya.
                }

                // Interest — ambil dari sisa pembayaran, dibatasi outstanding.
                $interestAlloc = min($remaining, $interestOutstanding);
                $remaining -= $interestAlloc;

                // Principal — ambil dari sisa pembayaran, dibatasi outstanding.
                $principalAlloc = min($remaining, $principalOutstanding);
                $remaining -= $principalAlloc;

                $total = $interestAlloc + $principalAlloc;

                if ($total <= 0) {
                    continue;
                }

                // 8. Tulis baris alokasi + perbarui state angsuran dalam
                //    transaksi yang sama.
                $allocation = ShmLoanPaymentAllocation::create([
                    'shm_loan_payment_id' => $locked->id,
                    'shm_loan_installment_id' => $installment->id,
                    // Penalty SELALU 0 — formula PENDING (T87 tidak menghitung).
                    'penalty_amount' => 0,
                    'interest_amount' => $interestAlloc,
                    'principal_amount' => $principalAlloc,
                    'total_amount' => $total,
                ]);

                $created[] = $allocation;
                $affectedIds[] = $installment->id;
                $totalAllocatedThisRun += $total;

                // Perbarui paid split + status angsuran.
                $installment->paid_interest_amount = (float) $installment->paid_interest_amount + $interestAlloc;
                $installment->paid_principal_amount = (float) $installment->paid_principal_amount + $principalAlloc;
                $installment->paid_amount = (float) $installment->paid_amount + $total;
                $installment->remaining_amount = max(
                    0.0,
                    (float) $installment->total_amount - (float) $installment->paid_amount,
                );

                if ((float) $installment->remaining_amount <= 0) {
                    $installment->status = ShmLoanInstallment::STATUS_PAID;
                    $installment->paid_at = now();
                }

                $installment->save();
            }

            // 9. Tepat SATU baris audit bila ada alokasi yang dibuat. Panggilan
            //    idempoten kedua tidak membuat audit baru (created kosong).
            if ($created !== []) {
                AuditLogger::log(
                    'shm.loan.payment.allocated',
                    ShmLoanPayment::class,
                    $locked->id,
                    $locked->payment_reference,
                    sprintf(
                        'Alokasi pembayaran %s untuk pinjaman %s: %d angsuran terpengaruh.',
                        $locked->payment_reference,
                        $loan->loan_number,
                        count($created),
                    ),
                    [
                        'shm_loan_id' => $loan->id,
                        'loan_number' => $loan->loan_number,
                        'payment_reference' => $locked->payment_reference,
                        'total_payment' => (string) $locked->amount,
                        'allocated_amount' => number_format($totalAllocatedThisRun, 2, '.', ''),
                        'unallocated_amount' => number_format($remaining, 2, '.', ''),
                        'affected_installments' => $affectedIds,
                    ],
                );
            }

            return [
                'allocated' => $created !== [],
                'allocations' => count($created),
                'allocated_amount' => number_format($totalAllocatedThisRun, 2, '.', ''),
                'unallocated_amount' => number_format($remaining, 2, '.', ''),
                'installment_ids' => $affectedIds,
            ];
        });
    }
}
