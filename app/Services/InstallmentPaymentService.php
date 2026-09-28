<?php

namespace App\Services;

use App\Models\CashTransaction;
use App\Models\InstallmentPayment;
use App\Models\Loan;
use App\Services\Allocation\InstallmentAllocationService;
use App\Support\DbTransaction;
use Illuminate\Validation\ValidationException;

class InstallmentPaymentService
{
    public function __construct(
        private CashierService $cashierService,
        private InstallmentAllocationService $allocationService,
    ) {}

    /**
     * Catat Pembayaran Angsuran secara atomik.
     *
     * @param  array{amount: numeric, payment_date: string, notes: ?string, idempotency_key: ?string}  $data
     */
    public function pay(Loan $loan, array $data, int $userId): InstallmentPayment
    {
        // Idempotensi: jika idempotency_key sudah terdaftar, kembalikan transaksi sebelumnya
        if (! empty($data['idempotency_key'])) {
            $existing = InstallmentPayment::where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing !== null) {
                return $existing;
            }
        }

        return DbTransaction::run(function () use ($loan, $data, $userId) {
            $lockedLoan = Loan::lockForUpdate()->findOrFail($loan->id);

            // Re-check idempotensi setelah lock: request berulang yang datang
            // bersamaan tetap mengembalikan pembayaran pertama tanpa dampak ganda.
            if (! empty($data['idempotency_key'])) {
                $existing = InstallmentPayment::where('idempotency_key', $data['idempotency_key'])->first();

                if ($existing !== null) {
                    return $existing;
                }
            }

            if (! in_array($lockedLoan->status, ['DISBURSED', 'ACTIVE'], true)) {
                throw ValidationException::withMessages([
                    'status' => ['Pembayaran angsuran hanya dapat diproses untuk pinjaman aktif.'],
                ]);
            }

            $paymentAmount = (float) $data['amount'];
            if ($paymentAmount <= 0) {
                throw ValidationException::withMessages([
                    'amount' => ['Nominal pembayaran harus lebih besar dari 0.'],
                ]);
            }

            // Sisa kewajiban total = pokok + bunga + denda belum dibayar (B.8).
            $tolerance = (float) config('business-rules.loan.settlement_tolerance', 0.01);
            $totalOwed = $lockedLoan->total_owed;

            if (round($paymentAmount, 2) > round($totalOwed, 2)) {
                throw ValidationException::withMessages([
                    'amount' => ['Nominal pembayaran melebihi sisa kewajiban pinjaman (Rp '.number_format($totalOwed, 0, ',', '.').').'],
                ]);
            }

            // Buat record pembayaran angsuran lebih dulu agar rincian alokasi
            // dapat mereferensikannya (E.5: setiap alokasi tercatat terperinci).
            $unpaidSchedules = $lockedLoan->schedules()
                ->whereIn('status', ['UPCOMING', 'DUE', 'PARTIALLY_PAID', 'OVERDUE'])
                ->orderBy('installment_number')
                ->get();

            $primaryTargetScheduleId = $unpaidSchedules->first()?->id;

            $payment = InstallmentPayment::create([
                'payment_number' => InstallmentPayment::generatePaymentNumber(),
                'loan_id' => $lockedLoan->id,
                'installment_schedule_id' => $primaryTargetScheduleId,
                'payment_date' => $data['payment_date'],
                'amount' => $paymentAmount,
                'processed_by' => $userId,
                'notes' => $data['notes'] ?? null,
                'status' => 'COMPLETED',
                'idempotency_key' => $data['idempotency_key'] ?? null,
            ]);

            // Alokasikan ke komponen kewajiban sesuai urutan yang dikonfigurasi
            // (E.1-E.3): default provisional denda → bunga → pokok.
            $allocation = $this->allocationService->allocate(
                $paymentAmount,
                $unpaidSchedules,
                $payment->id
            );

            foreach ($unpaidSchedules as $schedule) {
                if ($schedule->isDirty()) {
                    $schedule->save();
                }
            }

            // Integrasi kas: pembayaran angsuran tunai menambah kas kasir.
            // Idempotensi dijaga melalui referensi pembayaran sebagai transaksi sumber.
            $this->cashierService->recordSourceImpact(
                CashTransaction::TYPE_INCOME,
                config('cashier.source_categories.installment_payment'),
                $paymentAmount,
                $data['payment_date'],
                'Pembayaran angsuran pinjaman '.$lockedLoan->loan_number,
                $payment,
                $userId,
            );

            // Perbarui status pinjaman ke PAID_OFF bila seluruh kewajiban
            // (pokok+bunga+denda) selesai sesuai toleransi pelunasan (B.9).
            $paidOff = $lockedLoan->total_owed <= $tolerance;
            if ($paidOff) {
                $lockedLoan->update(['status' => 'PAID_OFF']);
            }

            AuditLogger::log(
                'installment.payment.recorded',
                InstallmentPayment::class,
                $payment->id,
                $payment->payment_number,
                sprintf(
                    'Pembayaran angsuran Rp %s untuk pinjaman %s%s.',
                    number_format($paymentAmount, 2, ',', '.'),
                    $lockedLoan->loan_number,
                    $paidOff ? ' (pinjaman lunas)' : ''
                ),
                [
                    'payment_number' => $payment->payment_number,
                    'loan_number' => $lockedLoan->loan_number,
                    'amount' => $paymentAmount,
                    'primary_schedule_id' => $primaryTargetScheduleId,
                    'allocation' => $allocation['by_component'],
                    'paid_off' => $paidOff,
                    'processed_by' => $userId,
                ],
            );

            return $payment;
        });
    }

    /**
     * Perbarui status angsuran berdasarkan tanggal (UPCOMING -> DUE / OVERDUE).
     */
    public function updateScheduleStatuses(Loan $loan): void
    {
        $today = now()->format('Y-m-d');
        foreach ($loan->schedules as $schedule) {
            if (in_array($schedule->status, ['PAID'], true)) {
                continue;
            }

            if ($schedule->status !== 'PARTIALLY_PAID') {
                if ($schedule->due_date < $today) {
                    $schedule->update(['status' => 'OVERDUE']);
                } elseif ($schedule->due_date === $today) {
                    $schedule->update(['status' => 'DUE']);
                }
            }
        }
    }

    /**
     * Koreksi pembayaran angsuran melalui reversal teraudit (Ticket 21, E).
     *
     * Pembayaran asal dan rincian alokasinya TIDAK diubah secara finansial;
     * status pembayaran asal menjadi REVERSED dan dibuat pembayaran penyesuaian
     * (entry_type=reversal) yang membatalkan efek alokasi secara berlawanan
     * (pokok/bunga/denda terbayar dikembalikan, status jadwal & PAID_OFF
     * dihitung ulang). Dampak kas dibalik via modul kasir sesuai konvensi
     * modul sumber ( reversal kas lintas modul hanya dari modul asal).
     */
    public function reverse(InstallmentPayment $payment, int $userId, string $reason): InstallmentPayment
    {
        // Seluruh pemeriksaan state dievaluasi DI DALAM transaksi database setelah
        // baris pembayaran dikunci, sehingga dua reversal konkuren terhadap
        // pembayaran yang sama tidak dapat lolos bersamaan.
        return DbTransaction::run(function () use ($payment, $userId, $reason) {
            $lockedPayment = InstallmentPayment::lockForUpdate()->findOrFail($payment->id);

            if ($lockedPayment->status !== 'COMPLETED') {
                throw ValidationException::withMessages([
                    'payment' => ['Hanya pembayaran berstatus COMPLETED yang dapat direversi.'],
                ]);
            }

            if ($lockedPayment->entry_type === 'reversal') {
                throw ValidationException::withMessages([
                    'payment' => ['Pembayaran penyesuaian (reversal) tidak dapat direversi kembali.'],
                ]);
            }

            if (InstallmentPayment::where('reversal_of_id', $lockedPayment->id)->where('status', 'COMPLETED')->exists()) {
                throw ValidationException::withMessages([
                    'payment' => ['Pembayaran ini sudah pernah direversi.'],
                ]);
            }

            $lockedLoan = Loan::lockForUpdate()->findOrFail($lockedPayment->loan_id);

            if (in_array($lockedLoan->status, ['PENDING', 'APPROVED', 'REJECTED'], true)) {
                throw ValidationException::withMessages([
                    'loan' => ['Pinjaman belum dicairkan; reversal tidak dapat diproses.'],
                ]);
            }

            // Buat pembayaran penyesuaian (status REVERSED = tidak efektif finansial).
            // Tidak memakai idempotency_key dari asal: reversal adalah kejadian baru.
            $reversal = InstallmentPayment::create([
                'payment_number' => InstallmentPayment::generatePaymentNumber(),
                'loan_id' => $lockedPayment->loan_id,
                'installment_schedule_id' => $lockedPayment->installment_schedule_id,
                'payment_date' => now()->format('Y-m-d'),
                'amount' => $lockedPayment->amount,
                'processed_by' => $userId,
                'notes' => 'Reversal '.$lockedPayment->payment_number.': '.$reason,
                'status' => 'REVERSED',
                'idempotency_key' => null,
                'reversal_of_id' => $lockedPayment->id,
                'entry_type' => 'reversal',
            ]);

            // Tandai pembayaran asal REVERSED (koreksi status, bukan penghapusan —
            // riwayat finansial asli tetap terbaca).
            $lockedPayment->forceFill(['status' => 'REVERSED'])->save();

            // Batalkan efek alokasi secara berlawanan, tetap dalam transaksi yang sama.
            $this->allocationService->reverseAllocations($lockedPayment);

            // Status lunas dihitung ulang dari kewajiban yang telah dikembalikan.
            $tolerance = (float) config('business-rules.loan.settlement_tolerance', 0.01);
            if ($lockedLoan->status === 'PAID_OFF' && $lockedLoan->total_owed > $tolerance) {
                $lockedLoan->forceFill(['status' => 'ACTIVE'])->save();
            }

            AuditLogger::log(
                'installment.payment.reversed',
                InstallmentPayment::class,
                $reversal->id,
                $reversal->payment_number,
                sprintf('Reversal pembayaran angsuran %s (Rp %s) oleh pengguna #%d. Alasan: %s', $lockedPayment->payment_number, number_format((float) $lockedPayment->amount, 2, ',', '.'), $userId, $reason),
                [
                    'original_payment' => $lockedPayment->payment_number,
                    'reversal_payment' => $reversal->payment_number,
                    'amount' => (float) $lockedPayment->amount,
                    'reason' => $reason,
                    'processed_by' => $userId,
                ],
            );

            // Dampak kas dibalik sebagai ENTRI KOMPENSASI baru (pengeluaran kas)
            // pada sesi operator saat ini — mengikuti konvensi reversal simpanan:
            // transaksi kas asal TIDAK diubah (immutable), net effect kas = nol.
            // Idempotensi dijaga melalui referensi pembayaran reversal sebagai sumber.
            $this->cashierService->recordSourceImpact(
                CashTransaction::TYPE_EXPENSE,
                config('cashier.source_categories.installment_payment'),
                (float) $lockedPayment->amount,
                now()->format('Y-m-d'),
                'Reversal pembayaran angsuran '.$lockedPayment->payment_number.' ('.$lockedLoan->loan_number.')',
                $reversal,
                $userId,
            );

            return $reversal;
        });
    }
}
