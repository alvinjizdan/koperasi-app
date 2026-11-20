<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShmCashTransaction extends Model
{
    use HasFactory;

    public const TYPE_INFLOW = 'INFLOW';

    public const TYPE_OUTFLOW = 'OUTFLOW';

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_POSTED = 'POSTED';

    public const STATUS_REVERSED = 'REVERSED';

    protected $table = 'shm_cash_transactions';

    protected $fillable = [
        'transaction_number',
        'type',
        'transaction_date',
        'cash_account_id',
        'account_id',
        'shm_settlement_refund_id',
        'amount',
        'description',
        'status',
        'journal_entry_id',
        'created_by',
        'posted_by',
        'posted_at',
        'reversed_by',
        'reversed_at',
        'reversal_reason',
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'amount' => 'decimal:2',
        'posted_at' => 'datetime',
        'reversed_at' => 'datetime',
    ];

    /**
     * Scope for Cash Inflow (Arus Kas Masuk).
     */
    public function scopeInflow(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_INFLOW);
    }

    /**
     * Scope for Cash Outflow (Arus Kas Keluar).
     */
    public function scopeOutflow(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_OUTFLOW);
    }

    /**
     * Scope for Draft status.
     */
    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_DRAFT);
    }

    /**
     * Scope for Posted status.
     */
    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_POSTED);
    }

    /**
     * Cash/Bank Account (Kas / Bank SHM).
     */
    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'cash_account_id');
    }

    /**
     * Counterpart Account (Nama Perkiraan).
     */
    public function counterpartAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'account_id');
    }

    public function refund(): BelongsTo
    {
        return $this->belongsTo(ShmSettlementRefund::class, 'shm_settlement_refund_id');
    }

    /**
     * Associated Journal Entry.
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    /**
     * Created by user.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Posted by user.
     */
    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    /**
     * Reversed by user.
     */
    public function reverser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }
}
