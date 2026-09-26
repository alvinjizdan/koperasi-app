<?php

namespace App\Services;

use App\Models\CashTransaction;
use App\Models\InstallmentPayment;
use App\Models\Loan;
use App\Support\DbTransaction;
use Illuminate\Validation\ValidationException;

class InstallmentPaymentService
{
    public function __construct(private CashierService $cashierService) {}

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

            $remainingLoanBalance = $lockedLoan->remaining_balance;
            if (round($paymentAmount, 2) > round($remainingLoanBalance, 2)) {
                throw ValidationException::withMessages([
                    'amount' => ['Nominal pembayaran melebihi sisa kewajiban pinjaman (Rp '.number_format($remainingLoanBalance, 0, ',', '.').').'],
                ]);
            }

            // Alokasikan pembayaran ke jadwal angsuran secara berurutan
            $unpaidSchedules = $lockedLoan->schedules()
                ->whereIn('status', ['UPCOMING', 'DUE', 'PARTIALLY_PAID', 'OVERDUE'])
                ->orderBy('installment_number')
                ->get();

            $allocationLeft = $paymentAmount;
            $primaryTargetScheduleId = null;

            foreach ($unpaidSchedules as $schedule) {
                if ($allocationLeft <= 0) {
                    break;
                }

                if ($primaryTargetScheduleId === null) {
                    $primaryTargetScheduleId = $schedule->id;
                }

                $scheduleRemaining = (float) $schedule->remaining_amount;

                if ($allocationLeft >= $scheduleRemaining) {
                    $allocationLeft -= $scheduleRemaining;
                    $schedule->paid_amount = (string) ((float) $schedule->total_amount);
                    $schedule->remaining_amount = '0.00';
                    $schedule->status = 'PAID';
                } else {
                    $newPaid = (float) $schedule->paid_amount + $allocationLeft;
                    $newRemaining = (float) $schedule->total_amount - $newPaid;
                    $schedule->paid_amount = (string) round($newPaid, 2);
                    $schedule->remaining_amount = (string) round($newRemaining, 2);
                    $schedule->status = 'PARTIALLY_PAID';
                    $allocationLeft = 0;
                }

                $schedule->save();
            }

            // Buat record pembayaran angsuran
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

            // Perbarui status pinjaman ke PAID_OFF jika seluruh kewajiban sudah terpenuhi
            $freshRemaining = $lockedLoan->getRemainingBalanceAttribute();
            $paidOff = $freshRemaining <= 0.01;
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
}
