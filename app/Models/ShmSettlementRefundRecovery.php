<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * TICKET 42B — Multi-Transaction Partial Recovery Model.
 *
 * @property int $id
 * @property int $shm_settlement_refund_id
 * @property int $shm_loan_settlement_id
 * @property string $recovery_reference
 * @property string $amount
 * @property string $recovery_method
 * @property string|null $recovery_reference_external
 * @property string|null $evidence_path
 * @property string|null $notes
 * @property string $status
 * @property int $requested_by
 * @property Carbon $requested_at
 * @property string|null $request_reason
 * @property int|null $confirmed_by
 * @property Carbon|null $confirmed_at
 * @property int|null $rejected_by
 * @property Carbon|null $rejected_at
 * @property string|null $rejection_reason
 * @property int|null $cancelled_by
 * @property Carbon|null $cancelled_at
 * @property string|null $cancellation_reason
 * @property string|null $idempotency_key
 */
class ShmSettlementRefundRecovery extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'shm_settlement_refund_recoveries';

    protected $guarded = ['id'];

    protected $casts = [
        'amount' => 'decimal:2',
        'requested_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'rejected_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function refund(): BelongsTo
    {
        return $this->belongsTo(ShmSettlementRefund::class, 'shm_settlement_refund_id');
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(ShmLoanSettlement::class, 'shm_loan_settlement_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isConfirmed(): bool
    {
        return $this->status === self::STATUS_CONFIRMED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }
}
