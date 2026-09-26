<?php

namespace App\Services\Reconciliation;

use App\Models\Loan;
use App\Models\LoanDisbursement;

/**
 * Pemeriksaan konsistensi pinjaman, pencairan, jadwal, dan pembayaran angsuran.
 *
 * Bunga saat ini selalu 0 pada pembuatan jadwal (flat, tanpa rumus bunga
 * terdefinisi), sehingga sisa kewajiban dihitung sebagai total jadwal dikurangi
 * pembayaran sah. Pemeriksaan bunga ditandai terbatas.
 *
 * Read-only: tidak pernah mengubah data pinjaman.
 */
class LoanReconciliationChecker
{
    /**
     * @param  string  $periodStart  Y-m-d (inklusif)
     * @param  string  $periodEnd  Y-m-d (inklusif)
     * @return array{findings: array<int, array<string, mixed>>, checked_rows: int, summary: array<string, mixed>}
     */
    public function check(string $periodStart, string $periodEnd): array
    {
        $findings = [];
        $checkedRows = 0;
        $loansChecked = 0;
        $paymentsChecked = 0;

        Loan::query()
            ->with(['disbursement', 'schedules', 'payments'])
            ->orderBy('id')
            ->chunkById(200, function ($loans) use (&$findings, &$checkedRows, &$loansChecked, &$paymentsChecked) {
                foreach ($loans as $loan) {
                    $loansChecked++;

                    $disbursement = $loan->disbursement;
                    $schedules = $loan->schedules;
                    $payments = $loan->payments;
                    $checkedRows += $schedules->count() + $payments->count();

                    // Status final harus memiliki referensi pencairan yang valid.
                    if (in_array($loan->status, ['ACTIVE', 'DISBURSED', 'PAID_OFF'], true) && $disbursement === null) {
                        $findings[] = $this->finding(
                            loan: $loan,
                            type: 'MISSING_REFERENCE',
                            expected: 1.0,
                            actual: 0.0,
                            variance: null,
                            description: sprintf('Pinjaman %s berstatus %s tetapi tidak memiliki referensi pencairan.', $loan->loan_number, $loan->status),
                            meta: ['loan_status' => $loan->status, 'disbursement_number' => null],
                        );
                    }

                    // Pencairan ganda untuk pinjaman yang sama.
                    $disbursementCount = LoanDisbursement::query()->where('loan_id', $loan->id)->count();

                    if ($disbursementCount > 1) {
                        $findings[] = $this->finding(
                            loan: $loan,
                            type: 'DUPLICATE_REFERENCE',
                            expected: 1.0,
                            actual: (float) $disbursementCount,
                            variance: (float) ($disbursementCount - 1),
                            description: sprintf('Pinjaman %s memiliki %d catatan pencairan.', $loan->loan_number, $disbursementCount),
                            meta: ['disbursement_count' => $disbursementCount],
                        );
                    }

                    // Pinjaman berstatus PENDING/APPROVED/REJECTED tidak boleh sudah dicairkan.
                    if (in_array($loan->status, ['PENDING', 'APPROVED', 'REJECTED'], true) && $disbursement !== null) {
                        $findings[] = $this->finding(
                            loan: $loan,
                            type: 'INVALID_STATUS',
                            expected: null,
                            actual: null,
                            variance: null,
                            description: sprintf('Pinjaman %s berstatus %s tetapi memiliki catatan pencairan %s.', $loan->loan_number, $loan->status, $disbursement->disbursement_number),
                            meta: ['loan_status' => $loan->status, 'disbursement_number' => $disbursement->disbursement_number],
                        );
                    }

                    if ($disbursement !== null) {
                        // Nominal pencairan harus sama dengan nominal pinjaman yang disetujui.
                        $disbursedAmount = round((float) $disbursement->amount, 2);
                        $approvedAmount = round((float) $loan->amount, 2);

                        if (round($approvedAmount - $disbursedAmount, 2) !== 0.0) {
                            $findings[] = $this->finding(
                                loan: $loan,
                                type: 'BALANCE_MISMATCH',
                                expected: $approvedAmount,
                                actual: $disbursedAmount,
                                variance: round($disbursedAmount - $approvedAmount, 2),
                                description: sprintf('Nominal pencairan %s (Rp %s) berbeda dari nominal pinjaman disetujui (Rp %s).', $disbursement->disbursement_number, number_format($disbursedAmount, 2, ',', '.'), number_format($approvedAmount, 2, ',', '.')),
                                meta: ['disbursement_number' => $disbursement->disbursement_number],
                            );
                        }

                        // Jadwal harus konsisten: total jadwal = nominal pencairan.
                        $scheduleTotal = round((float) $schedules->sum('total_amount'), 2);

                        if ($schedules->isNotEmpty() && round($scheduleTotal - $disbursedAmount, 2) !== 0.0) {
                            $findings[] = $this->finding(
                                loan: $loan,
                                type: 'BALANCE_MISMATCH',
                                expected: $disbursedAmount,
                                actual: $scheduleTotal,
                                variance: round($scheduleTotal - $disbursedAmount, 2),
                                description: sprintf('Total jadwal angsuran pinjaman %s (Rp %s) tidak sama dengan nominal pencairan (Rp %s).', $loan->loan_number, number_format($scheduleTotal, 2, ',', '.'), number_format($disbursedAmount, 2, ',', '.')),
                                meta: ['schedule_count' => $schedules->count()],
                            );
                        }

                        // Jadwal kosong padahal sudah dicairkan.
                        if ($schedules->isEmpty()) {
                            $findings[] = $this->finding(
                                loan: $loan,
                                type: 'INCOMPLETE_DATA',
                                expected: null,
                                actual: null,
                                variance: null,
                                description: sprintf('Pinjaman %s sudah dicairkan tetapi tidak memiliki jadwal angsuran.', $loan->loan_number),
                                meta: null,
                            );
                        }
                    }

                    // Pembayaran harus terhubung ke pinjaman yang benar dan tidak dihitung ganda.
                    $completedPayments = $payments->filter(fn ($p) => $p->status === 'COMPLETED');

                    foreach ($payments as $payment) {
                        if (! in_array($payment->status, ['COMPLETED', 'REVERSED', 'CANCELLED'], true)) {
                            $findings[] = $this->finding(
                                loan: $loan,
                                type: 'INVALID_STATUS',
                                expected: null,
                                actual: null,
                                variance: null,
                                description: sprintf('Pembayaran %s memiliki status tidak dikenal: %s.', $payment->payment_number, $payment->status),
                                meta: ['payment_number' => $payment->payment_number, 'payment_status' => $payment->status],
                            );
                        }
                    }

                    // Nominal pembayaran tidak boleh melebihi total kewajiban (sisa kewajiban negatif).
                    $totalScheduled = round((float) $schedules->sum('total_amount'), 2);
                    $totalPaid = round((float) $completedPayments->sum('amount'), 2);
                    $paymentsChecked += $completedPayments->count();

                    if ($disbursement !== null && $totalScheduled > 0 && round($totalPaid - $totalScheduled, 2) > 0.0) {
                        $findings[] = $this->finding(
                            loan: $loan,
                            type: 'UNALLOCATED_PAYMENT',
                            expected: $totalScheduled,
                            actual: $totalPaid,
                            variance: round($totalPaid - $totalScheduled, 2),
                            description: sprintf('Total pembayaran sah pinjaman %s (Rp %s) melebihi total kewajiban terjadwal (Rp %s).', $loan->loan_number, number_format($totalPaid, 2, ',', '.'), number_format($totalScheduled, 2, ',', '.')),
                            meta: ['payment_count' => $completedPayments->count()],
                        );
                    }

                    // Pembayaran COMPLETED tanpa alokasi jadwal (installment_schedule_id kosong).
                    foreach ($completedPayments as $payment) {
                        if ($payment->installment_schedule_id === null) {
                            $findings[] = $this->finding(
                                loan: $loan,
                                type: 'UNALLOCATED_PAYMENT',
                                expected: null,
                                actual: null,
                                variance: null,
                                description: sprintf('Pembayaran %s belum memiliki alokasi jadwal angsuran.', $payment->payment_number),
                                meta: ['payment_number' => $payment->payment_number, 'amount' => round((float) $payment->amount, 2)],
                            );
                        }
                    }

                    // Status lunas harus sesuai seluruh kewajiban yang sah.
                    if ($loan->status === 'PAID_OFF' && $disbursement !== null && $totalScheduled > 0) {
                        if (round($totalScheduled - $totalPaid, 2) > 0.01) {
                            $findings[] = $this->finding(
                                loan: $loan,
                                type: 'INVALID_STATUS',
                                expected: $totalScheduled,
                                actual: $totalPaid,
                                variance: round($totalPaid - $totalScheduled, 2),
                                description: sprintf('Pinjaman %s berstatus PAID_OFF tetapi masih tersisa kewajiban Rp %s.', $loan->loan_number, number_format($totalScheduled - $totalPaid, 2, ',', '.')),
                                meta: ['remaining' => round($totalScheduled - $totalPaid, 2)],
                            );
                        }
                    }

                    // Sisa kewajiban pada jadwal harus konsisten dengan status PAID.
                    foreach ($schedules as $schedule) {
                        $remaining = round((float) $schedule->remaining_amount, 2);

                        if ($schedule->status === 'PAID' && $remaining > 0.01) {
                            $findings[] = $this->finding(
                                loan: $loan,
                                type: 'INVALID_STATUS',
                                expected: 0.0,
                                actual: $remaining,
                                variance: $remaining,
                                description: sprintf('Angsuran ke-%d pinjaman %s berstatus PAID tetapi masih memiliki sisa Rp %s.', $schedule->installment_number, $loan->loan_number, number_format($remaining, 2, ',', '.')),
                                meta: ['installment_number' => $schedule->installment_number],
                            );
                        }
                    }
                }
            });

        return [
            'findings' => $findings,
            'checked_rows' => $checkedRows,
            'summary' => [
                'loans_checked' => $loansChecked,
                'payments_reviewed' => $paymentsChecked,
                'missing_disbursement_count' => count(array_filter($findings, fn ($f) => $f['finding_type'] === 'MISSING_REFERENCE')),
                'duplicate_disbursement_count' => count(array_filter($findings, fn ($f) => $f['finding_type'] === 'DUPLICATE_REFERENCE')),
                'status_issue_count' => count(array_filter($findings, fn ($f) => $f['finding_type'] === 'INVALID_STATUS')),
                'unallocated_payment_count' => count(array_filter($findings, fn ($f) => $f['finding_type'] === 'UNALLOCATED_PAYMENT')),
                'note' => 'Pemeriksaan bunga dan denda terbatas: komponen kewajiban belum terdefinisi (interest_amount = 0).',
            ],
        ];
    }

    /**
     * Struktur temuan mentah sebelum dipersistenkan.
     *
     * @param  array<string, mixed>|null  $meta
     * @return array<string, mixed>
     */
    private function finding(Loan $loan, string $type, ?float $expected, ?float $actual, ?float $variance, string $description, ?array $meta = null): array
    {
        $magnitude = $variance !== null ? abs($variance) : ($expected ?? 0);

        return [
            'finding_type' => $type,
            'module' => 'loans',
            'object_type' => Loan::class,
            'object_id' => $loan->id,
            'object_label' => $loan->loan_number,
            'expected_value' => $expected,
            'actual_value' => $actual,
            'variance' => $variance,
            'priority' => $magnitude >= 1000000 ? 'HIGH' : ($magnitude >= 10000 ? 'MEDIUM' : 'LOW'),
            'description' => $description,
            'meta' => $meta,
        ];
    }
}
