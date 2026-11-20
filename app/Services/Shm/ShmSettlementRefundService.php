<?php

namespace App\Services\Shm;

use App\Models\ShmLoan;
use App\Models\ShmLoanSettlement;
use App\Models\ShmRefundAccountingLink;
use App\Models\ShmSettlementRefund;
use App\Models\ShmSettlementRefundRecovery;
use App\Services\AuditLogger;
use App\Support\AccountingContext;
use App\Support\DbTransaction;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * TICKET 42 — Layanan Operasional Refund Kelebihan Bayar Pelunasan SHM.
 *
 * Mengimplementasikan BR-42-01 s/d BR-42-15:
 * - Hitung saldo refundable per settlement secara dinamis & aman.
 * - Workflow pengajuan (pending), approval (approved), penolakan (rejected),
 *   pembatalan (cancelled), pembayaran kas/transfer (paid), dan pembalikan (reversed).
 * - Row locking dengan lockForUpdate() dan pengurutan deterministik.
 * - Idempotensi payout.
 * - Jejak audit permanen.
 * - Strict Option A (TIDAK MEMBUAT auto-jurnal akuntansi).
 */
class ShmSettlementRefundService
{
    /**
     * Hitung ringkasan saldo refundable untuk sebuah settlement (Read-Only / Dalam Transaksi).
     *
     * Canonical Formula (Ticket 42C — Phase 4):
     * - Total Reserved = Pending Amount + Approved Amount
     * - Net Consumed Refund = \sum_{r \in \{PAID, RECOVERY_PENDING, RECOVERED\}} \max(0, r.amount - ConfirmedRecoveries(r))
     * - Remaining Refundable = \max(0, Settlement Overpayment - Total Reserved - Net Consumed Refund)
     *
     * Catatan:
     * - recovery_pending TIDAK memulihkan saldo dan TIDAK di-double-count.
     * - recoveries berstatus PENDING dihitung hanya sebagai info terpisah (pending_recovery_reservations)
     *   dan tidak memengaruhi remaining_refundable sampai berstatus CONFIRMED.
     *
     * @return array{
     *     overpayment_amount: string,
     *     pending_reservations: string,
     *     approved_reservations: string,
     *     paid_amount: string,
     *     recovered_amount: string,
     *     pending_recovery_reservations: string,
     *     total_reserved: string,
     *     remaining_refundable: string,
     *     is_fully_refunded: bool
     * }
     */
    public function calculateRefundableSummary(ShmLoanSettlement $settlement): array
    {
        $overpayment = round((float) $settlement->overpayment_amount, 2);

        // Ambil refund yang relevan beserta data recoveries
        $refunds = ShmSettlementRefund::query()
            ->with(['recoveries'])
            ->where('shm_loan_settlement_id', $settlement->id)
            ->get();

        $pending = round((float) $refunds->where('status', ShmSettlementRefund::STATUS_PENDING)->sum('amount'), 2);
        $approved = round((float) $refunds->where('status', ShmSettlementRefund::STATUS_APPROVED)->sum('amount'), 2);

        $totalReserved = round($pending + $approved, 2);

        $totalGrossPaid = 0.0;
        $totalConfirmedRecoveries = 0.0;
        $totalPendingRecoveries = 0.0;
        $netConsumedRefund = 0.0;

        foreach ($refunds as $ref) {
            $confSum = (float) $ref->recoveries->where('status', ShmSettlementRefundRecovery::STATUS_CONFIRMED)->sum('amount');
            if ($confSum <= 0.0 && (float) $ref->recovered_amount > 0.0) {
                $confSum = (float) $ref->recovered_amount;
            }
            $pendSum = (float) $ref->recoveries->where('status', ShmSettlementRefundRecovery::STATUS_PENDING)->sum('amount');

            $totalConfirmedRecoveries += $confSum;
            $totalPendingRecoveries += $pendSum;

            if (in_array($ref->status, [
                ShmSettlementRefund::STATUS_PAID,
                ShmSettlementRefund::STATUS_RECOVERY_PENDING,
                ShmSettlementRefund::STATUS_RECOVERED,
            ], true)) {
                $totalGrossPaid += (float) $ref->amount;
                // Net consumed per refund: amount paid minus confirmed recoveries
                $netConsumed = max(0.0, (float) $ref->amount - $confSum);
                $netConsumedRefund += $netConsumed;
            }
        }

        $totalConfirmedRecoveries = round($totalConfirmedRecoveries, 2);
        $totalPendingRecoveries = round($totalPendingRecoveries, 2);
        $netConsumedRefund = round($netConsumedRefund, 2);

        // Remaining refundable capacity
        $totalOccupied = round($totalReserved + $netConsumedRefund, 2);
        $remaining = max(0.0, round($overpayment - $totalOccupied, 2));

        return [
            'overpayment_amount' => number_format($overpayment, 2, '.', ''),
            'pending_reservations' => number_format($pending, 2, '.', ''),
            'approved_reservations' => number_format($approved, 2, '.', ''),
            'paid_amount' => number_format($totalGrossPaid, 2, '.', ''),
            'recovered_amount' => number_format($totalConfirmedRecoveries, 2, '.', ''),
            'pending_recovery_reservations' => number_format($totalPendingRecoveries, 2, '.', ''),
            'total_reserved' => number_format($totalReserved, 2, '.', ''),
            'remaining_refundable' => number_format($remaining, 2, '.', ''),
            'is_fully_refunded' => $overpayment > 0 && $remaining <= 0.005,
        ];
    }

    /**
     * Ajukan permohonan refund baru.
     *
     * @param  array{
     *     amount: numeric,
     *     payout_method: string,
     *     payout_details?: ?array,
     *     reason?: ?string,
     *     idempotency_key?: ?string
     * }  $data
     *
     * @throws ValidationException
     */
    public function request(ShmLoanSettlement $settlement, array $data, int $actorId): ShmSettlementRefund
    {
        return DbTransaction::run(function () use ($settlement, $data, $actorId): ShmSettlementRefund {
            // 1. Lock settlement
            $lockedSettlement = ShmLoanSettlement::query()
                ->lockForUpdate()
                ->findOrFail($settlement->id);

            // Validasi eligibility settlement (BR-42-02)
            if ($lockedSettlement->status !== ShmLoanSettlement::STATUS_COMPLETED) {
                throw ValidationException::withMessages([
                    'settlement' => ['Permohonan refund hanya dapat diajukan untuk settlement yang telah selesai (completed).'],
                ]);
            }

            if ((float) $lockedSettlement->overpayment_amount <= 0.005) {
                throw ValidationException::withMessages([
                    'settlement' => ['Settlement ini tidak memiliki kelebihan bayar (overpayment) sehingga tidak eligible untuk refund.'],
                ]);
            }

            // Validasi kepemilikan dan relasi konsisten (BR-42-03)
            $loan = ShmLoan::findOrFail($lockedSettlement->shm_loan_id);
            $customerId = $loan->shm_customer_id;

            // Validasi idempotency jika disertakan
            $idempotencyKey = $data['idempotency_key'] ?? null;
            if ($idempotencyKey !== null) {
                $existing = ShmSettlementRefund::where('idempotency_key', $idempotencyKey)->first();
                if ($existing) {
                    return $existing;
                }
            }

            $amount = round((float) ($data['amount'] ?? 0), 2);
            if ($amount <= 0.005) {
                throw ValidationException::withMessages([
                    'amount' => ['Nominal refund harus lebih besar dari Rp 0,00.'],
                ]);
            }

            // Lock existing active refunds untuk hitung sisa saldo
            $summary = $this->calculateRefundableSummary($lockedSettlement);
            $available = (float) $summary['remaining_refundable'];

            if ($amount > $available + 0.005) {
                throw ValidationException::withMessages([
                    'amount' => [sprintf(
                        'Nominal refund Rp %s melebihi sisa saldo overpayment yang tersedia (Rp %s).',
                        number_format($amount, 2, ',', '.'),
                        number_format($available, 2, ',', '.')
                    )],
                ]);
            }

            $method = $data['payout_method'] ?? ShmSettlementRefund::METHOD_CASH;
            if (! in_array($method, [ShmSettlementRefund::METHOD_CASH, ShmSettlementRefund::METHOD_BANK_TRANSFER], true)) {
                throw ValidationException::withMessages([
                    'payout_method' => ['Metode pengeluaran dana tidak valid (harus cash atau bank_transfer).'],
                ]);
            }

            $payoutDetails = $data['payout_details'] ?? [];
            if ($method === ShmSettlementRefund::METHOD_BANK_TRANSFER) {
                if (empty($payoutDetails['bank_name']) || empty($payoutDetails['account_number']) || empty($payoutDetails['account_holder'])) {
                    throw ValidationException::withMessages([
                        'payout_details' => ['Informasi transfer bank (nama bank, nomor rekening, dan nama pemilik) wajib dilengkapi.'],
                    ]);
                }
            }

            $refund = ShmSettlementRefund::create([
                'shm_loan_settlement_id' => $lockedSettlement->id,
                'shm_loan_id' => $loan->id,
                'shm_customer_id' => $customerId,
                'refund_reference' => $this->generateReference(),
                'amount' => $amount,
                'payout_method' => $method,
                'payout_details' => $payoutDetails,
                'reason' => $data['reason'] ?? null,
                'status' => ShmSettlementRefund::STATUS_PENDING,
                'idempotency_key' => $idempotencyKey,
                'requested_by' => $actorId,
                'requested_at' => now(),
            ]);

            AuditLogger::log(
                'shm.settlement.refund.requested',
                ShmSettlementRefund::class,
                $refund->id,
                $refund->refund_reference,
                sprintf(
                    'Pengajuan refund %s sebesar Rp %s untuk pelunasan %s.',
                    $refund->refund_reference,
                    number_format($amount, 0, ',', '.'),
                    $lockedSettlement->settlement_reference
                ),
                [
                    'settlement_id' => $lockedSettlement->id,
                    'settlement_reference' => $lockedSettlement->settlement_reference,
                    'loan_id' => $loan->id,
                    'customer_id' => $customerId,
                    'amount' => (string) $amount,
                    'payout_method' => $method,
                ]
            );

            return $refund;
        });
    }

    /**
     * Setujui permohonan refund (pending -> approved).
     *
     * @throws ValidationException
     */
    public function approve(ShmSettlementRefund $refund, int $actorId): ShmSettlementRefund
    {
        return DbTransaction::run(function () use ($refund, $actorId): ShmSettlementRefund {
            $lockedSettlement = ShmLoanSettlement::query()
                ->lockForUpdate()
                ->findOrFail($refund->shm_loan_settlement_id);

            $lockedRefund = ShmSettlementRefund::query()
                ->lockForUpdate()
                ->findOrFail($refund->id);

            // Idempotensi jika sudah approved
            if ($lockedRefund->status === ShmSettlementRefund::STATUS_APPROVED) {
                return $lockedRefund;
            }

            if ($lockedRefund->status !== ShmSettlementRefund::STATUS_PENDING) {
                throw ValidationException::withMessages([
                    'refund' => [sprintf('Hanya permohonan berstatus pending yang dapat disetujui. Status saat ini: "%s".', $lockedRefund->status)],
                ]);
            }

            // Periksa ulang apakah saldo overpayment masih mencukupi
            // (karena refund ini sudah berstatus pending, amount-nya sudah terhitung di totalReserved, jadi aman)
            $lockedRefund->status = ShmSettlementRefund::STATUS_APPROVED;
            $lockedRefund->approved_by = $actorId;
            $lockedRefund->approved_at = now();
            $lockedRefund->save();

            AuditLogger::log(
                'shm.settlement.refund.approved',
                ShmSettlementRefund::class,
                $lockedRefund->id,
                $lockedRefund->refund_reference,
                sprintf('Permohonan refund %s disetujui.', $lockedRefund->refund_reference),
                [
                    'settlement_id' => $lockedSettlement->id,
                    'amount' => (string) $lockedRefund->amount,
                    'approver_id' => $actorId,
                ]
            );

            return $lockedRefund;
        });
    }

    /**
     * Tolak permohonan refund (pending -> rejected).
     *
     * @throws ValidationException
     */
    public function reject(ShmSettlementRefund $refund, string $reason, int $actorId): ShmSettlementRefund
    {
        return DbTransaction::run(function () use ($refund, $reason, $actorId): ShmSettlementRefund {
            $lockedSettlement = ShmLoanSettlement::query()
                ->lockForUpdate()
                ->findOrFail($refund->shm_loan_settlement_id);

            $lockedRefund = ShmSettlementRefund::query()
                ->lockForUpdate()
                ->findOrFail($refund->id);

            if ($lockedRefund->status !== ShmSettlementRefund::STATUS_PENDING) {
                throw ValidationException::withMessages([
                    'refund' => [sprintf('Hanya permohonan berstatus pending yang dapat ditolak. Status saat ini: "%s".', $lockedRefund->status)],
                ]);
            }

            $trimmedReason = trim($reason);
            if ($trimmedReason === '' || strlen($trimmedReason) < 3) {
                throw ValidationException::withMessages([
                    'reason' => ['Alasan penolakan refund wajib diisi (minimal 3 karakter).'],
                ]);
            }

            $lockedRefund->status = ShmSettlementRefund::STATUS_REJECTED;
            $lockedRefund->rejected_by = $actorId;
            $lockedRefund->rejected_at = now();
            $lockedRefund->rejection_reason = $trimmedReason;
            $lockedRefund->save();

            AuditLogger::log(
                'shm.settlement.refund.rejected',
                ShmSettlementRefund::class,
                $lockedRefund->id,
                $lockedRefund->refund_reference,
                sprintf('Permohonan refund %s ditolak: %s', $lockedRefund->refund_reference, $trimmedReason),
                [
                    'settlement_id' => $lockedSettlement->id,
                    'reason' => $trimmedReason,
                    'actor_id' => $actorId,
                ]
            );

            return $lockedRefund;
        });
    }

    /**
     * Batalkan permohonan refund (pending/approved -> cancelled).
     *
     * @throws ValidationException
     */
    public function cancel(ShmSettlementRefund $refund, string $reason, int $actorId): ShmSettlementRefund
    {
        return DbTransaction::run(function () use ($refund, $reason, $actorId): ShmSettlementRefund {
            $lockedSettlement = ShmLoanSettlement::query()
                ->lockForUpdate()
                ->findOrFail($refund->shm_loan_settlement_id);

            $lockedRefund = ShmSettlementRefund::query()
                ->lockForUpdate()
                ->findOrFail($refund->id);

            if (! in_array($lockedRefund->status, [ShmSettlementRefund::STATUS_PENDING, ShmSettlementRefund::STATUS_APPROVED], true)) {
                throw ValidationException::withMessages([
                    'refund' => [sprintf('Refund berstatus "%s" tidak dapat dibatalkan.', $lockedRefund->status)],
                ]);
            }

            $trimmedReason = trim($reason);
            if ($trimmedReason === '' || strlen($trimmedReason) < 3) {
                throw ValidationException::withMessages([
                    'reason' => ['Alasan pembatalan refund wajib diisi (minimal 3 karakter).'],
                ]);
            }

            $lockedRefund->status = ShmSettlementRefund::STATUS_CANCELLED;
            $lockedRefund->cancelled_by = $actorId;
            $lockedRefund->cancelled_at = now();
            $lockedRefund->cancellation_reason = $trimmedReason;
            $lockedRefund->save();

            AuditLogger::log(
                'shm.settlement.refund.cancelled',
                ShmSettlementRefund::class,
                $lockedRefund->id,
                $lockedRefund->refund_reference,
                sprintf('Refund %s dibatalkan: %s', $lockedRefund->refund_reference, $trimmedReason),
                [
                    'settlement_id' => $lockedSettlement->id,
                    'reason' => $trimmedReason,
                    'actor_id' => $actorId,
                ]
            );

            return $lockedRefund;
        });
    }

    /**
     * Eksekusi pembayaran refund kepada nasabah (approved -> paid).
     *
     * @param  array{
     *     payout_reference?: ?string,
     *     payout_notes?: ?string,
     *     payout_evidence_path?: ?string,
     *     recipient_name?: ?string,
     *     destination_bank?: ?string,
     *     destination_account_number?: ?string,
     *     destination_account_holder?: ?string,
     *     idempotency_key?: ?string
     * }  $payload
     *
     * @throws ValidationException
     */
    public function executePayout(ShmSettlementRefund $refund, array $payload, int $actorId): ShmSettlementRefund
    {
        return DbTransaction::run(function () use ($refund, $payload, $actorId): ShmSettlementRefund {
            $lockedSettlement = ShmLoanSettlement::query()
                ->lockForUpdate()
                ->findOrFail($refund->shm_loan_settlement_id);

            $lockedRefund = ShmSettlementRefund::query()
                ->lockForUpdate()
                ->findOrFail($refund->id);

            // Idempotensi jika sudah dibayarkan
            if ($lockedRefund->status === ShmSettlementRefund::STATUS_PAID) {
                return $lockedRefund;
            }

            if ($lockedRefund->status !== ShmSettlementRefund::STATUS_APPROVED) {
                throw ValidationException::withMessages([
                    'refund' => [sprintf('Hanya refund yang telah disetujui (approved) yang dapat dibayarkan. Status saat ini: "%s".', $lockedRefund->status)],
                ]);
            }

            // BR-42A-04: Validasi Bukti Pembayaran Payout
            $evidencePath = trim($payload['payout_evidence_path'] ?? '');
            if ($evidencePath === '') {
                throw ValidationException::withMessages([
                    'payout_evidence_path' => ['Bukti pembayaran fisik / dokumen transfer wajib disertakan.'],
                ]);
            }

            $method = $lockedRefund->payout_method;
            $payoutRef = trim($payload['payout_reference'] ?? '');

            if ($method === ShmSettlementRefund::METHOD_CASH) {
                $recipient = trim($payload['recipient_name'] ?? '');
                if ($recipient === '') {
                    throw ValidationException::withMessages([
                        'recipient_name' => ['Nama penerima uang tunai wajib dicatat.'],
                    ]);
                }
                $lockedRefund->recipient_name = $recipient;
                $lockedRefund->payout_reference = $payoutRef !== '' ? $payoutRef : 'CASH-'.now()->format('YmdHis');
            } elseif ($method === ShmSettlementRefund::METHOD_BANK_TRANSFER) {
                if ($payoutRef === '') {
                    throw ValidationException::withMessages([
                        'payout_reference' => ['Nomor referensi transfer bank wajib dicatat untuk pembayaran transfer.'],
                    ]);
                }

                $bank = trim($payload['destination_bank'] ?? ($lockedRefund->payout_details['bank_name'] ?? ''));
                $accNo = trim($payload['destination_account_number'] ?? ($lockedRefund->payout_details['account_number'] ?? ''));
                $accHolder = trim($payload['destination_account_holder'] ?? ($lockedRefund->payout_details['account_holder'] ?? ''));

                if ($bank === '' || $accNo === '' || $accHolder === '') {
                    throw ValidationException::withMessages([
                        'payout_details' => ['Kelengkapan data rekening tujuan transfer wajib terisi lengkap.'],
                    ]);
                }

                $lockedRefund->payout_reference = $payoutRef;
                $lockedRefund->destination_bank = $bank;
                $lockedRefund->destination_account_number = $accNo;
                $lockedRefund->destination_account_holder = $accHolder;
            }

            $lockedRefund->status = ShmSettlementRefund::STATUS_PAID;
            $lockedRefund->paid_by = $actorId;
            $lockedRefund->paid_at = now();
            $lockedRefund->payout_notes = $payload['payout_notes'] ?? null;
            $lockedRefund->payout_evidence_path = $evidencePath;
            $lockedRefund->save();

            AuditLogger::log(
                'shm.settlement.refund.paid',
                ShmSettlementRefund::class,
                $lockedRefund->id,
                $lockedRefund->refund_reference,
                sprintf(
                    'Pembayaran refund %s sebesar Rp %s selesai (%s).',
                    $lockedRefund->refund_reference,
                    number_format((float) $lockedRefund->amount, 0, ',', '.'),
                    $lockedRefund->payout_reference
                ),
                [
                    'settlement_id' => $lockedSettlement->id,
                    'amount' => (string) $lockedRefund->amount,
                    'payout_reference' => $lockedRefund->payout_reference,
                    'paid_by' => $actorId,
                ]
            );

            return $lockedRefund;
        });
    }

    /**
     * BR-42A-01: Ajukan permintaan pembalikan / reversal atas refund yang sudah dibayar.
     * Transisi: paid -> recovery_pending (TIDAK membuka kembali saldo refundable).
     *
     * @throws ValidationException
     */
    public function requestReversal(ShmSettlementRefund $refund, string $reason, int $actorId): ShmSettlementRefund
    {
        return DbTransaction::run(function () use ($refund, $reason, $actorId): ShmSettlementRefund {
            $lockedSettlement = ShmLoanSettlement::query()
                ->lockForUpdate()
                ->findOrFail($refund->shm_loan_settlement_id);

            $lockedRefund = ShmSettlementRefund::query()
                ->lockForUpdate()
                ->findOrFail($refund->id);

            if ($lockedRefund->status === ShmSettlementRefund::STATUS_RECOVERY_PENDING) {
                return $lockedRefund;
            }

            if ($lockedRefund->status !== ShmSettlementRefund::STATUS_PAID) {
                throw ValidationException::withMessages([
                    'refund' => [sprintf('Hanya refund yang berstatus "paid" yang dapat diajukan reversal. Status saat ini: "%s".', $lockedRefund->status)],
                ]);
            }

            $trimmedReason = trim($reason);
            if ($trimmedReason === '' || strlen($trimmedReason) < 5) {
                throw ValidationException::withMessages([
                    'reason' => ['Alasan pengajuan reversal refund wajib diisi (minimal 5 karakter).'],
                ]);
            }

            $lockedRefund->status = ShmSettlementRefund::STATUS_RECOVERY_PENDING;
            $lockedRefund->recovery_requested_by = $actorId;
            $lockedRefund->recovery_requested_at = now();
            $lockedRefund->recovery_request_reason = $trimmedReason;
            $lockedRefund->save();

            AuditLogger::log(
                'shm.settlement.refund.recovery_requested',
                ShmSettlementRefund::class,
                $lockedRefund->id,
                $lockedRefund->refund_reference,
                sprintf('Pengajuan reversal refund %s sebesar Rp %s: %s (Menunggu konfirmasi pengembalian dana riil).', $lockedRefund->refund_reference, number_format((float) $lockedRefund->amount, 0, ',', '.'), $trimmedReason),
                [
                    'settlement_id' => $lockedSettlement->id,
                    'amount' => (string) $lockedRefund->amount,
                    'reason' => $trimmedReason,
                    'actor_id' => $actorId,
                ]
            );

            return $lockedRefund;
        });
    }

    /**
     * BR-42A-01: Konfirmasi penerimaan pengembalian dana riil (recovery) atas refund yang dibalikkan.
     * Transisi: recovery_pending -> recovered (HANYA nominal riil yang dikonfirmasi yang dipulihkan ke saldo refundable).
     *
     * @param  array{
     *     amount: numeric,
     *     recovery_reference: string,
     *     recovery_method: string,
     *     recovery_evidence_path: string,
     *     recovery_notes?: ?string
     * }  $payload
     *
     * @throws ValidationException
     */
    public function confirmRecovery(ShmSettlementRefund $refund, array $payload, int $actorId): ShmSettlementRefund
    {
        return DbTransaction::run(function () use ($refund, $payload, $actorId): ShmSettlementRefund {
            $lockedSettlement = ShmLoanSettlement::query()
                ->lockForUpdate()
                ->findOrFail($refund->shm_loan_settlement_id);

            $lockedRefund = ShmSettlementRefund::query()
                ->lockForUpdate()
                ->findOrFail($refund->id);

            if ($lockedRefund->status === ShmSettlementRefund::STATUS_RECOVERED && (float) $lockedRefund->outstandingPayoutAmount() <= 0.005) {
                return $lockedRefund;
            }

            if (! in_array($lockedRefund->status, [ShmSettlementRefund::STATUS_RECOVERY_PENDING, ShmSettlementRefund::STATUS_RECOVERED], true)) {
                throw ValidationException::withMessages([
                    'refund' => [sprintf('Konfirmasi pengembalian dana hanya dapat diproses untuk status "recovery_pending" atau partial "recovered". Status saat ini: "%s".', $lockedRefund->status)],
                ]);
            }

            $recAmount = round((float) ($payload['amount'] ?? 0), 2);
            if ($recAmount <= 0.005) {
                throw ValidationException::withMessages([
                    'amount' => ['Nominal pengembalian dana riil harus lebih besar dari Rp 0,00.'],
                ]);
            }

            $outstanding = $lockedRefund->outstandingPayoutAmount();
            if ($recAmount > $outstanding + 0.005) {
                throw ValidationException::withMessages([
                    'amount' => [sprintf(
                        'Nominal recovery Rp %s melebihi sisa dana refund yang belum dikembalikan (Rp %s).',
                        number_format($recAmount, 2, ',', '.'),
                        number_format($outstanding, 2, ',', '.')
                    )],
                ]);
            }

            $recRef = trim($payload['recovery_reference'] ?? '');
            if ($recRef === '') {
                throw ValidationException::withMessages([
                    'recovery_reference' => ['Nomor referensi bukti penerimaan pengembalian dana wajib diisi.'],
                ]);
            }

            $evidence = trim($payload['recovery_evidence_path'] ?? '');
            if ($evidence === '') {
                throw ValidationException::withMessages([
                    'recovery_evidence_path' => ['Bukti transfer/tanda terima pengembalian dana riil wajib dilampirkan.'],
                ]);
            }

            $method = $payload['recovery_method'] ?? ShmSettlementRefund::METHOD_CASH;
            if (! in_array($method, [ShmSettlementRefund::METHOD_CASH, ShmSettlementRefund::METHOD_BANK_TRANSFER], true)) {
                throw ValidationException::withMessages([
                    'recovery_method' => ['Metode pengembalian dana tidak valid.'],
                ]);
            }

            // Validasi Idempotency key jika disertakan
            $idempotencyKey = $payload['idempotency_key'] ?? null;
            if ($idempotencyKey !== null && $idempotencyKey !== '') {
                $existingRecovery = ShmSettlementRefundRecovery::where('idempotency_key', $idempotencyKey)->first();
                if ($existingRecovery !== null) {
                    return $lockedRefund;
                }
            }

            // BR-42B-05: Catat ke tabel shm_settlement_refund_recoveries sebagai transaksi terpisah
            $recoveryTxRef = $this->generateRecoveryReference();
            $recoveryRecord = ShmSettlementRefundRecovery::create([
                'shm_settlement_refund_id' => $lockedRefund->id,
                'shm_loan_settlement_id' => $lockedSettlement->id,
                'recovery_reference' => $recoveryTxRef,
                'amount' => $recAmount,
                'recovery_method' => $method,
                'recovery_reference_external' => $recRef,
                'evidence_path' => $evidence,
                'notes' => $payload['recovery_notes'] ?? null,
                'status' => ShmSettlementRefundRecovery::STATUS_CONFIRMED,
                'requested_by' => $actorId,
                'requested_at' => now(),
                'request_reason' => $lockedRefund->recovery_request_reason ?? 'Penerimaan pemulihan dana',
                'confirmed_by' => $actorId,
                'confirmed_at' => now(),
                'idempotency_key' => $idempotencyKey,
            ]);

            $totalRecoveredSoFar = round((float) $lockedRefund->recovered_amount + $recAmount, 2);
            $lockedRefund->recovered_amount = $totalRecoveredSoFar;
            $lockedRefund->recovery_reference = $recRef;
            $lockedRefund->recovery_method = $method;
            $lockedRefund->recovery_evidence_path = $evidence;
            $lockedRefund->recovered_by = $actorId;
            $lockedRefund->recovered_at = now();
            $lockedRefund->recovery_notes = $payload['recovery_notes'] ?? null;
            $lockedRefund->status = ShmSettlementRefund::STATUS_RECOVERED;
            $lockedRefund->save();

            AuditLogger::log(
                'shm.settlement.refund.recovered',
                ShmSettlementRefund::class,
                $lockedRefund->id,
                $lockedRefund->refund_reference,
                sprintf(
                    'Pengembalian dana riil atas refund %s sebesar Rp %s berhasil dikonfirmasi (%s, Ref Tx: %s). Sisa belum kembali: Rp %s.',
                    $lockedRefund->refund_reference,
                    number_format($recAmount, 0, ',', '.'),
                    $recRef,
                    $recoveryTxRef,
                    number_format($lockedRefund->outstandingPayoutAmount(), 0, ',', '.')
                ),
                [
                    'settlement_id' => $lockedSettlement->id,
                    'recovery_transaction_id' => $recoveryRecord->id,
                    'recovered_amount' => (string) $recAmount,
                    'total_recovered' => (string) $totalRecoveredSoFar,
                    'outstanding' => (string) $lockedRefund->outstandingPayoutAmount(),
                    'recovery_reference' => $recRef,
                    'actor_id' => $actorId,
                ]
            );

            return $lockedRefund;
        });
    }

    /**
     * Generate format referensi transaksi recovery server-side: SHM-REC-YYYYMMDD-XXXXXXXX
     */
    private function generateRecoveryReference(): string
    {
        $date = now()->format('Ymd');
        do {
            $random = strtoupper(Str::random(8));
            $ref = "SHM-REC-{$date}-{$random}";
        } while (ShmSettlementRefundRecovery::where('recovery_reference', $ref)->exists());

        return $ref;
    }

    /**
     * BR-42B-01 & BR-42B-02: Catat tautan pengakuan akuntansi (Accounting Linkage) untuk refund payout.
     * Mencegah pengakuan ganda (duplicate accounting recognition) secara ketat across channels.
     *
     * @throws ValidationException
     */
    public function linkAccountingRecognition(
        ShmSettlementRefund $refund,
        int $journalEntryId,
        string $channel,
        int $actorId,
        ?int $cashTransactionId = null,
        ?string $notes = null
    ): ShmRefundAccountingLink {
        return DbTransaction::run(function () use ($refund, $journalEntryId, $channel, $actorId, $cashTransactionId, $notes): ShmRefundAccountingLink {
            // Lock refund row untuk mencegah race condition pengakuan ganda
            $lockedRefund = ShmSettlementRefund::query()
                ->lockForUpdate()
                ->findOrFail($refund->id);

            // Validasi status refund harus sudah dibayar (paid/recovery_pending/recovered)
            if (! in_array($lockedRefund->status, [ShmSettlementRefund::STATUS_PAID, ShmSettlementRefund::STATUS_RECOVERY_PENDING, ShmSettlementRefund::STATUS_RECOVERED], true)) {
                throw ValidationException::withMessages([
                    'refund' => [sprintf('Hanya refund yang telah berstatus dibayarkan (paid) yang dapat diakui secara akuntansi. Status saat ini: "%s".', $lockedRefund->status)],
                ]);
            }

            // Validasi apakah jurnal berstatus POSTED sudah pernah ditautkan
            $existingJournalLink = ShmRefundAccountingLink::query()
                ->where('journal_entry_id', $journalEntryId)
                ->where('status', ShmRefundAccountingLink::STATUS_POSTED)
                ->first();

            if ($existingJournalLink !== null) {
                if ($existingJournalLink->shm_settlement_refund_id === $lockedRefund->id) {
                    return $existingJournalLink;
                }
                throw ValidationException::withMessages([
                    'journal_entry_id' => [sprintf(
                        'Jurnal #%d sudah ditautkan ke pengakuan refund #%d.',
                        $journalEntryId,
                        $existingJournalLink->shm_settlement_refund_id
                    )],
                ]);
            }

            // Validasi apakah sudah ada tautan pengakuan berstatus POSTED yang aktif
            $existingLink = ShmRefundAccountingLink::query()
                ->where('shm_settlement_refund_id', $lockedRefund->id)
                ->where('status', ShmRefundAccountingLink::STATUS_POSTED)
                ->first();

            if ($existingLink !== null) {
                if ($existingLink->journal_entry_id === $journalEntryId) {
                    return $existingLink;
                }
                throw ValidationException::withMessages([
                    'accounting_recognition' => [sprintf(
                        'Pembayaran refund %s sudah diakui dalam akuntansi SHM melalui Jurnal #%d (%s) pada %s. Pengakuan ganda dicegah.',
                        $lockedRefund->refund_reference,
                        $existingLink->journal_entry_id,
                        $existingLink->recognition_channel,
                        $existingLink->linked_at->format('d/m/Y H:i')
                    )],
                ]);
            }

            $link = ShmRefundAccountingLink::create([
                'accounting_context' => AccountingContext::SHM,
                'shm_settlement_refund_id' => $lockedRefund->id,
                'recognition_channel' => $channel,
                'shm_cash_transaction_id' => $cashTransactionId,
                'journal_entry_id' => $journalEntryId,
                'status' => ShmRefundAccountingLink::STATUS_POSTED,
                'linked_by' => $actorId,
                'linked_at' => now(),
                'notes' => $notes,
            ]);

            // Jika melalui cash outflow, update shm_cash_transaction_id pada tabel refund
            if ($cashTransactionId !== null) {
                $lockedRefund->shm_cash_transaction_id = $cashTransactionId;
                $lockedRefund->save();
            }

            AuditLogger::log(
                'shm.settlement.refund.accounting_linked',
                ShmSettlementRefund::class,
                $lockedRefund->id,
                $lockedRefund->refund_reference,
                sprintf(
                    'Pengakuan akuntansi atas refund %s berhasil ditautkan ke Jurnal #%d via kanal %s.',
                    $lockedRefund->refund_reference,
                    $journalEntryId,
                    $channel
                ),
                [
                    'journal_entry_id' => $journalEntryId,
                    'channel' => $channel,
                    'cash_transaction_id' => $cashTransactionId,
                    'linked_by' => $actorId,
                ]
            );

            return $link;
        });
    }

    /**
     * Reversal atas pengakuan akuntansi (jika jurnal dibalikkan).
     */
    public function reverseAccountingRecognition(int $journalEntryId, int $actorId): void
    {
        DbTransaction::run(function () use ($journalEntryId, $actorId) {
            $links = ShmRefundAccountingLink::query()
                ->lockForUpdate()
                ->where('journal_entry_id', $journalEntryId)
                ->where('status', ShmRefundAccountingLink::STATUS_POSTED)
                ->get();

            foreach ($links as $link) {
                $link->status = ShmRefundAccountingLink::STATUS_REVERSED;
                $link->save();

                AuditLogger::log(
                    'shm.settlement.refund.accounting_unlinked',
                    ShmSettlementRefund::class,
                    $link->shm_settlement_refund_id,
                    (string) $link->shm_settlement_refund_id,
                    sprintf('Pengakuan akuntansi atas refund #%d dibalikkan bersamaan dengan reversal Jurnal #%d.', $link->shm_settlement_refund_id, $journalEntryId),
                    [
                        'journal_entry_id' => $journalEntryId,
                        'reversed_by' => $actorId,
                    ]
                );
            }
        });
    }

    /**
     * Generate format referensi refund server-side: SHM-REF-YYYYMMDD-XXXXXXXX
     */
    private function generateReference(): string
    {
        $date = now()->format('Ymd');
        do {
            $random = strtoupper(Str::random(8));
            $ref = "SHM-REF-{$date}-{$random}";
        } while (ShmSettlementRefund::where('refund_reference', $ref)->exists());

        return $ref;
    }
}
