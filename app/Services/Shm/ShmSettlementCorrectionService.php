<?php

namespace App\Services\Shm;

use App\Models\ShmLoan;
use App\Models\ShmLoanInstallment;
use App\Models\ShmLoanPayment;
use App\Models\ShmLoanPaymentAllocation;
use App\Models\ShmLoanSettlement;
use App\Models\ShmSettlementCorrection;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Service Domain Koreksi Pelunasan Pinjaman SHM (T005).
 *
 * Mengimplementasikan Policy B — Current Period Correction:
 * - Reversal non-destruktif terhadap settlement original yang berstatus COMPLETED.
 * - Nilai finansial original tidak diedit/dihapus; status berpindah ke REVERSED.
 * - Pembayaran tertaut dilepas kembali ke domain reguler (settlement_released_at = now()).
 * - Overpayment yang diposting ke Simpanan Pokok dikompensasi secara non-cash impact.
 * - Status pinjaman dipulihkan dari closed ke active.
 * - Jadwal angsuran dipulihkan secara deterministik ke alokasi sebelum pelunasan.
 * - Replacement settlement diterbitkan dengan ID baru dan referensi SHM-SET-... baru dalam status pending.
 * - Atomik, idempotent, dan dilindungi row locking untuk proteksi concurrency.
 */
class ShmSettlementCorrectionService
{
    public function __construct(
        private readonly ShmLoanSettlementService $settlementService,
        private readonly ShmSavingsIntegrationService $savingsIntegration,
    ) {}

    /**
     * Preview autoritatif dampak koreksi pelunasan (READ-ONLY).
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException bila settlement tidak eligible untuk koreksi
     */
    public function preview(ShmLoan $loan, ShmLoanSettlement $settlement): array
    {
        if ($settlement->shm_loan_id !== $loan->id) {
            throw ValidationException::withMessages([
                'settlement' => ['Settlement tidak sesuai dengan pinjaman yang dituju.'],
            ]);
        }

        if ($settlement->status !== ShmLoanSettlement::STATUS_COMPLETED) {
            throw ValidationException::withMessages([
                'settlement' => [sprintf(
                    'Hanya settlement berstatus completed yang dapat dikoreksi. Status saat ini: "%s".',
                    $settlement->status,
                )],
            ]);
        }

        if (ShmSettlementCorrection::where('original_settlement_id', $settlement->id)->exists()) {
            throw ValidationException::withMessages([
                'settlement' => ['Settlement ini sudah pernah dikoreksi sebelumnya.'],
            ]);
        }

        $installments = $loan->installments()->orderBy('installment_number')->get();
        $restoredPrincipal = 0.0;
        $restoredInterest = 0.0;
        $installmentsToRestoreCount = 0;

        foreach ($installments as $installment) {
            $allocations = ShmLoanPaymentAllocation::query()
                ->where('shm_loan_installment_id', $installment->id)
                ->get();

            $paidTotal = (float) $allocations->sum('total_amount');
            $total = (float) $installment->total_amount;
            $remaining = max(0.0, round($total - $paidTotal, 2));

            if ($remaining > 0.005) {
                $installmentsToRestoreCount++;
                $paidPrincipal = (float) $allocations->sum('principal_amount');
                $paidInterest = (float) $allocations->sum('interest_amount');
                $restoredPrincipal += max(0.0, (float) $installment->principal_amount - $paidPrincipal);
                $restoredInterest += max(0.0, (float) $installment->interest_amount - $paidInterest);
            }
        }

        return [
            'original_settlement' => [
                'id' => $settlement->id,
                'reference' => $settlement->settlement_reference,
                'settlement_date' => $settlement->settlement_date?->toDateString(),
                'settlement_amount' => (string) $settlement->settlement_amount,
                'outstanding_principal' => (string) $settlement->outstanding_principal,
                'outstanding_interest' => (string) $settlement->outstanding_interest,
                'outstanding_penalty' => (string) $settlement->outstanding_penalty,
                'discount_amount' => (string) $settlement->discount_amount,
                'overpayment_amount' => (string) $settlement->overpayment_amount,
                'status' => $settlement->status,
            ],
            'restored_state' => [
                'loan_status' => ShmLoan::STATUS_ACTIVE,
                'installments_to_restore' => $installmentsToRestoreCount,
                'restored_outstanding_principal' => number_format($restoredPrincipal, 2, '.', ''),
                'restored_outstanding_interest' => number_format($restoredInterest, 2, '.', ''),
                'overpayment_compensation_amount' => (string) $settlement->overpayment_amount,
            ],
        ];
    }

    /**
     * Eksekusi koreksi pelunasan (Policy B — Non-destructive Reversal + Replacement).
     *
     * @param  array{penalty?: numeric, notes?: ?string}  $replacementData
     *
     * @throws ValidationException
     */
    public function correct(
        ShmLoan $loan,
        ShmLoanSettlement $settlement,
        string $reason,
        int $actorId,
        array $replacementData = [],
    ): ShmSettlementCorrection {
        return DB::transaction(function () use ($loan, $settlement, $reason, $actorId, $replacementData): ShmSettlementCorrection {
            $trimmedReason = trim($reason);
            if ($trimmedReason === '' || strlen($trimmedReason) < 5) {
                throw ValidationException::withMessages([
                    'reason' => ['Alasan koreksi pelunasan wajib diisi (minimal 5 karakter) dan tidak boleh hanya berupa spasi.'],
                ]);
            }

            $lockedLoan = ShmLoan::query()->lockForUpdate()->findOrFail($loan->id);
            $lockedSettlement = ShmLoanSettlement::query()->lockForUpdate()->findOrFail($settlement->id);

            if ($lockedSettlement->shm_loan_id !== $lockedLoan->id) {
                throw ValidationException::withMessages([
                    'settlement' => ['Settlement tidak sesuai dengan pinjaman yang dituju.'],
                ]);
            }

            if ($lockedSettlement->status !== ShmLoanSettlement::STATUS_COMPLETED) {
                throw ValidationException::withMessages([
                    'settlement' => [sprintf(
                        'Hanya settlement berstatus completed yang dapat dikoreksi. Status saat ini: "%s".',
                        $lockedSettlement->status,
                    )],
                ]);
            }

            if (ShmSettlementCorrection::where('original_settlement_id', $lockedSettlement->id)->exists()) {
                throw ValidationException::withMessages([
                    'settlement' => ['Settlement ini sudah pernah dikoreksi sebelumnya.'],
                ]);
            }

            // 1. Reversal settlement original: status menjadi reversed (nilai finansial tetap utuh)
            $lockedSettlement->status = ShmLoanSettlement::STATUS_REVERSED;
            $lockedSettlement->save();

            // 2. Lepas tautan pembayaran pelunasan original ke domain reguler
            if ($lockedSettlement->shm_loan_payment_id !== null) {
                $payment = ShmLoanPayment::query()->lockForUpdate()->find($lockedSettlement->shm_loan_payment_id);
                if ($payment !== null) {
                    $payment->settlement_released_at = now();
                    $payment->save();

                    AuditLogger::log(
                        'shm.loan.payment.settlement_released',
                        ShmLoanPayment::class,
                        $payment->id,
                        $payment->payment_reference,
                        sprintf(
                            'Pembayaran %s dilepas dari pelunasan %s karena koreksi pelunasan.',
                            $payment->payment_reference,
                            $lockedSettlement->settlement_reference,
                        ),
                        [
                            'shm_loan_payment_id' => $payment->id,
                            'settlement_id' => $lockedSettlement->id,
                            'settlement_reference' => $lockedSettlement->settlement_reference,
                            'actor_id' => $actorId,
                        ],
                    );
                }
            }

            // 3. Kompensasi overpayment Simpanan Pokok (non-cash impact)
            if ((float) $lockedSettlement->overpayment_amount > 0.005) {
                $this->savingsIntegration->reverseOverpayment(
                    $lockedSettlement,
                    $actorId,
                    $trimmedReason,
                );
            }

            // 4. Pulihkan jadwal angsuran ke alokasi sebelum pelunasan
            $installments = $lockedLoan->installments()
                ->orderBy('installment_number')
                ->lockForUpdate()
                ->get();

            foreach ($installments as $installment) {
                $allocations = ShmLoanPaymentAllocation::query()
                    ->where('shm_loan_installment_id', $installment->id)
                    ->get();

                $paidPrincipal = round((float) $allocations->sum('principal_amount'), 2);
                $paidInterest = round((float) $allocations->sum('interest_amount'), 2);
                $paidTotal = round((float) $allocations->sum('total_amount'), 2);
                $total = round((float) $installment->total_amount, 2);
                $remaining = max(0.0, round($total - $paidTotal, 2));

                $installment->paid_principal_amount = number_format($paidPrincipal, 2, '.', '');
                $installment->paid_interest_amount = number_format($paidInterest, 2, '.', '');
                $installment->paid_amount = number_format($paidTotal, 2, '.', '');
                $installment->remaining_amount = number_format($remaining, 2, '.', '');
                $installment->status = $remaining <= 0.005 ? ShmLoanInstallment::STATUS_PAID : ShmLoanInstallment::STATUS_SCHEDULED;
                if ($remaining > 0.005) {
                    $installment->paid_at = null;
                }
                $installment->save();
            }

            // 5. Pulihkan status pinjaman dari closed ke active
            $lockedLoan->status = ShmLoan::STATUS_ACTIVE;
            $lockedLoan->save();

            // 6. Terbitkan replacement settlement baru dalam status pending
            $replacementSettlement = $this->settlementService->request(
                $lockedLoan,
                [
                    'penalty' => $replacementData['penalty'] ?? 0,
                    'notes' => $replacementData['notes'] ?? sprintf(
                        'Pengganti settlement %s (koreksi: %s)',
                        $lockedSettlement->settlement_reference,
                        $trimmedReason,
                    ),
                ],
                $actorId,
            );

            // 7. Simpan record koreksi settlement
            $correction = ShmSettlementCorrection::create([
                'shm_loan_id' => $lockedLoan->id,
                'original_settlement_id' => $lockedSettlement->id,
                'replacement_settlement_id' => $replacementSettlement->id,
                'reason' => $trimmedReason,
                'status' => ShmSettlementCorrection::STATUS_COMPLETED,
                'created_by' => $actorId,
            ]);

            // 8. Audit trail
            AuditLogger::log(
                'shm.settlement.reversed',
                ShmLoanSettlement::class,
                $lockedSettlement->id,
                $lockedSettlement->settlement_reference,
                sprintf(
                    'Settlement %s dibatalkan (reversed) melalui koreksi #%d. Alasan: %s',
                    $lockedSettlement->settlement_reference,
                    $correction->id,
                    $trimmedReason,
                ),
                [
                    'shm_loan_id' => $lockedLoan->id,
                    'settlement_id' => $lockedSettlement->id,
                    'settlement_reference' => $lockedSettlement->settlement_reference,
                    'correction_id' => $correction->id,
                    'reason' => $trimmedReason,
                    'actor_id' => $actorId,
                ],
            );

            AuditLogger::log(
                'shm.settlement.correction.executed',
                ShmSettlementCorrection::class,
                $correction->id,
                (string) $correction->id,
                sprintf(
                    'Koreksi settlement #%d selesai. Original: %s, Replacement: %s. Alasan: %s',
                    $correction->id,
                    $lockedSettlement->settlement_reference,
                    $replacementSettlement->settlement_reference,
                    $trimmedReason,
                ),
                [
                    'correction_id' => $correction->id,
                    'shm_loan_id' => $lockedLoan->id,
                    'original_settlement_id' => $lockedSettlement->id,
                    'original_settlement_reference' => $lockedSettlement->settlement_reference,
                    'replacement_settlement_id' => $replacementSettlement->id,
                    'replacement_settlement_reference' => $replacementSettlement->settlement_reference,
                    'reason' => $trimmedReason,
                    'actor_id' => $actorId,
                ],
            );

            return $correction->load(['originalSettlement', 'replacementSettlement', 'loan', 'creator']);
        });
    }
}
