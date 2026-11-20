<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * TICKET 42 — Model Transaksi Refund Kelebihan Bayar Pelunasan SHM.
 *
 * @property int $id
 * @property int $shm_loan_settlement_id
 * @property int $shm_loan_id
 * @property int $shm_customer_id
 * @property string $refund_reference
 * @property string $amount
 * @property string $payout_method
 * @property array|null $payout_details
 * @property string|null $reason
 * @property string $status
 * @property string|null $idempotency_key
 * @property int $requested_by
 * @property Carbon $requested_at
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property int|null $rejected_by
 * @property Carbon|null $rejected_at
 * @property string|null $rejection_reason
 * @property int|null $cancelled_by
 * @property Carbon|null $cancelled_at
 * @property string|null $cancellation_reason
 * @property int|null $paid_by
 * @property Carbon|null $paid_at
 * @property string|null $payout_reference
 * @property string|null $payout_notes
 * @property string|null $payout_evidence_path
 * @property int|null $reversed_by
 * @property Carbon|null $reversed_at
 * @property string|null $reversal_reason
 */
class ShmSettlementRefund extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_PAID = 'paid';

    public const STATUS_REVERSED = 'reversed';

    public const STATUS_RECOVERY_PENDING = 'recovery_pending';

    public const STATUS_RECOVERED = 'recovered';

    public const METHOD_CASH = 'cash';

    public const METHOD_BANK_TRANSFER = 'bank_transfer';

    protected $guarded = ['id'];

    protected $casts = [
        'amount' => 'decimal:2',
        'recovered_amount' => 'decimal:2',
        'payout_details' => 'array',
        'requested_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'paid_at' => 'datetime',
        'reversed_at' => 'datetime',
        'recovery_requested_at' => 'datetime',
        'recovered_at' => 'datetime',
    ];

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(ShmLoanSettlement::class, 'shm_loan_settlement_id');
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(ShmLoan::class, 'shm_loan_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(ShmCustomer::class, 'shm_customer_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function reverser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    public function recoveryRequester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recovery_requested_by');
    }

    public function recoverer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recovered_by');
    }

    public function cashTransaction(): BelongsTo
    {
        return $this->belongsTo(ShmCashTransaction::class, 'shm_cash_transaction_id');
    }

    public function recoveries(): HasMany
    {
        return $this->hasMany(ShmSettlementRefundRecovery::class, 'shm_settlement_refund_id');
    }

    public function accountingLinks(): HasMany
    {
        return $this->hasMany(ShmRefundAccountingLink::class, 'shm_settlement_refund_id');
    }

    public function activeAccountingLink(): ?ShmRefundAccountingLink
    {
        return $this->accountingLinks()->where('status', ShmRefundAccountingLink::STATUS_POSTED)->first();
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isReversed(): bool
    {
        return $this->status === self::STATUS_REVERSED;
    }

    public function isRecoveryPending(): bool
    {
        return $this->status === self::STATUS_RECOVERY_PENDING;
    }

    public function isRecovered(): bool
    {
        return $this->status === self::STATUS_RECOVERED;
    }

    /**
     * Sisa dana payout yang belum dikembalikan (unrecovered payout amount)
     * dihitung dari riwayat transaksi konfirmasi recovery riil yang telah disetujui (confirmed).
     */
    public function outstandingPayoutAmount(): float
    {
        if (! in_array($this->status, [self::STATUS_PAID, self::STATUS_RECOVERY_PENDING, self::STATUS_RECOVERED, self::STATUS_REVERSED], true)) {
            return 0.0;
        }

        $amount = (float) $this->amount;

        // Ambil dari relasi recoveries confirmed jika ada, atau fallback ke recovered_amount
        $confirmedRecoveriesSum = (float) $this->recoveries()
            ->where('status', ShmSettlementRefundRecovery::STATUS_CONFIRMED)
            ->sum('amount');

        $recovered = max((float) $this->recovered_amount, $confirmedRecoveriesSum);

        return max(0.0, round($amount - $recovered, 2));
    }

    /**
     * Total dana yang sedang dalam proses pengajuan recovery (pending).
     */
    public function pendingRecoveryAmount(): float
    {
        return (float) $this->recoveries()
            ->where('status', ShmSettlementRefundRecovery::STATUS_PENDING)
            ->sum('amount');
    }

    /**
     * Sisa kapasitas nominal yang masih dapat diajukan untuk recovery.
     */
    public function availableRecoveryCapacity(): float
    {
        $outstanding = $this->outstandingPayoutAmount();
        $pending = $this->pendingRecoveryAmount();

        return max(0.0, round($outstanding - $pending, 2));
    }

    /**
     * Apakah refund ini saat ini mereservasi / mengonsumsi saldo overpayment?
     */
    public function reservesBalance(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_PAID, self::STATUS_RECOVERY_PENDING], true);
    }
}
