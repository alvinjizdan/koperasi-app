<?php

namespace App\Services\Reconciliation;

use App\Models\InstallmentAllocation;
use App\Models\Loan;
use App\Models\LoanDisbursement;

/**
 * Pemeriksaan konsistensi pinjaman, pencairan, jadwal, pembayaran, dan alokasi
 * angsuran (Ticket 17).
 *
 * Sejak Ticket 15, jadwal memiliki komponen pokok, bunga, dan denda; snapshot
 * metode/rate bunga tersimpan di loans. Pemeriksaan mengikuti model tersebut:
 * - total POKOK jadwal = nominal pencairan (bunga & denda bukan bagian pokok);
 * - seluruh komponen jadwal tidak negatif;
 * - setiap pembayaran COMPLETED memiliki rincian alokasi komponen;
 * - terbayar per komponen tidak melebihi kewajiban komponen;
 * - status PAID_OFF konsisten dengan sisa kewajiban total_owed.
 *
 * Catatan semantik kolom: remaining_amount = sisa pokok+bunga (kompatibilitas
 * laporan lama, TIDAK termasuk denda); sumber kebenaran kewajiban total adalah
 * perhitungan komponen (principal+interest+penalty belum dibayar).
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

                        // Jadwal harus konsisten (Ticket 17): total POKOK jadwal =
                        // nominal pencairan; bunga/denda dilaporkan terpisah.
                        $schedulePrincipal = round((float) $schedules->sum('principal_amount'), 2);
                        $scheduleInterest = round((float) $schedules->sum('interest_amount'), 2);
                        $scheduleTotal = round((float) $schedules->sum('total_amount'), 2);

                        if ($schedules->isNotEmpty() && round($schedulePrincipal - $disbursedAmount, 2) !== 0.0) {
                            $findings[] = $this->finding(
                                loan: $loan,
                                type: 'BALANCE_MISMATCH',
                                expected: $disbursedAmount,
                                actual: $schedulePrincipal,
                                variance: round($schedulePrincipal - $disbursedAmount, 2),
                                description: sprintf('Total pokok jadwal angsuran pinjaman %s (Rp %s) tidak sama dengan nominal pencairan (Rp %s).', $loan->loan_number, number_format($schedulePrincipal, 2, ',', '.'), number_format($disbursedAmount, 2, ',', '.')),
                                meta: ['schedule_count' => $schedules->count(), 'total_interest' => $scheduleInterest, 'schedule_total' => $scheduleTotal],
                            );
                        }

                        // Komponen kewajiban tidak boleh negatif.
                        foreach ($schedules as $schedule) {
                            foreach ([
                                'principal_amount' => (float) $schedule->principal_amount,
                                'interest_amount' => (float) $schedule->interest_amount,
                                'penalty_accrued' => (float) $schedule->penalty_accrued,
                                'total_amount' => (float) $schedule->total_amount,
                            ] as $field => $value) {
                                if ($value < -0.009) {
                                    $findings[] = $this->finding(
                                        loan: $loan,
                                        type: 'BALANCE_MISMATCH',
                                        expected: 0.0,
                                        actual: $value,
                                        variance: $value,
                                        description: sprintf('Angsuran ke-%d pinjaman %s memiliki komponen %s negatif (Rp %s).', $schedule->installment_number, $loan->loan_number, $field, number_format($value, 2, ',', '.')),
                                        meta: ['installment_number' => $schedule->installment_number, 'field' => $field],
                                    );
                                }
                            }
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

                    // Total pembayaran tidak boleh melebihi total kewajiban terjadwal
                    // termasuk denda terakrual (Ticket 17; sebelumnya hanya pokok+bunga).
                    $totalScheduled = round((float) $schedules->sum('total_amount'), 2);
                    $totalScheduledWithPenalty = round($totalScheduled + (float) $schedules->sum('penalty_accrued'), 2);
                    $totalPaid = round((float) $completedPayments->sum('amount'), 2);
                    $paymentsChecked += $completedPayments->count();

                    if ($disbursement !== null && $totalScheduled > 0 && round($totalPaid - $totalScheduledWithPenalty, 2) > 0.0) {
                        $findings[] = $this->finding(
                            loan: $loan,
                            type: 'UNALLOCATED_PAYMENT',
                            expected: $totalScheduledWithPenalty,
                            actual: $totalPaid,
                            variance: round($totalPaid - $totalScheduledWithPenalty, 2),
                            description: sprintf('Total pembayaran sah pinjaman %s (Rp %s) melebihi total kewajiban terjadwal termasuk denda (Rp %s).', $loan->loan_number, number_format($totalPaid, 2, ',', '.'), number_format($totalScheduledWithPenalty, 2, ',', '.')),
                            meta: ['payment_count' => $completedPayments->count()],
                        );
                    }

                    // Rekonsiliasi alokasi komponen (Ticket 17): setiap pembayaran
                    // COMPLETED harus memiliki rincian alokasi di installment_allocations
                    // (installment_schedule_id hanya penanda angsuran utama).
                    $paymentIds = $completedPayments->pluck('id');
                    $allocationsByPayment = $paymentIds->isNotEmpty()
                        ? InstallmentAllocation::query()
                            ->whereIn('installment_payment_id', $paymentIds)
                            ->get()
                            ->groupBy('installment_payment_id')
                        : collect();

                    foreach ($completedPayments as $payment) {
                        if (! $allocationsByPayment->has($payment->id)) {
                            $findings[] = $this->finding(
                                loan: $loan,
                                type: 'UNALLOCATED_PAYMENT',
                                expected: round((float) $payment->amount, 2),
                                actual: 0.0,
                                variance: round((float) $payment->amount, 2),
                                description: sprintf('Pembayaran %s (Rp %s) tidak memiliki rincian alokasi komponen.', $payment->payment_number, number_format((float) $payment->amount, 2, ',', '.')),
                                meta: ['payment_number' => $payment->payment_number, 'amount' => round((float) $payment->amount, 2)],
                            );
                        }
                    }

                    // Terbayar per komponen jadwal tidak boleh melebihi kewajiban komponen.
                    foreach ($schedules as $schedule) {
                        foreach ([
                            'principal' => round((float) $schedule->principal_amount - (float) $schedule->paid_amount, 2),
                            'interest' => round((float) $schedule->interest_amount - (float) ($schedule->interest_paid ?? 0), 2),
                            'penalty' => round((float) $schedule->penalty_accrued - (float) ($schedule->penalty_paid ?? 0), 2),
                        ] as $component => $remainingOwed) {
                            if ($remainingOwed < -0.009) {
                                $findings[] = $this->finding(
                                    loan: $loan,
                                    type: 'BALANCE_MISMATCH',
                                    expected: 0.0,
                                    actual: $remainingOwed,
                                    variance: $remainingOwed,
                                    description: sprintf('Angsuran ke-%d pinjaman %s: terbayar komponen %s melebihi kewajibannya (kelebihan Rp %s).', $schedule->installment_number, $loan->loan_number, $component, number_format(abs($remainingOwed), 2, ',', '.')),
                                    meta: ['installment_number' => $schedule->installment_number, 'component' => $component],
                                );
                            }
                        }
                    }

                    // Status lunas harus sesuai sisa kewajiban seluruh komponen
                    // (pokok+bunga+denda belum dibayar) sesuai definisi total_owed.
                    if ($loan->status === 'PAID_OFF' && $disbursement !== null && $schedules->isNotEmpty()) {
                        $remainingOwed = round((float) $schedules->sum(fn ($schedule) => max(0.0, (float) $schedule->principal_amount - (float) $schedule->paid_amount)
                            + max(0.0, (float) $schedule->interest_amount - (float) ($schedule->interest_paid ?? 0))
                            + max(0.0, (float) $schedule->penalty_accrued - (float) ($schedule->penalty_paid ?? 0))), 2);

                        if ($remainingOwed > 0.01) {
                            $findings[] = $this->finding(
                                loan: $loan,
                                type: 'INVALID_STATUS',
                                expected: 0.0,
                                actual: $remainingOwed,
                                variance: $remainingOwed,
                                description: sprintf('Pinjaman %s berstatus PAID_OFF tetapi masih tersisa kewajiban Rp %s.', $loan->loan_number, number_format($remainingOwed, 2, ',', '.')),
                                meta: ['remaining' => $remainingOwed],
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
                'note' => 'Pemeriksaan mengikuti model komponen Ticket 15: pokok, bunga, dan denda diperiksa terpisah.',
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
