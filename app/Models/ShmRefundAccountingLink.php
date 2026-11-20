<?php

namespace App\Models;

use App\Support\AccountingContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * TICKET 42B — Structured Refund Payout / Accounting Linkage Model.
 *
 * @property int $id
 * @property string $accounting_context
 * @property int $shm_settlement_refund_id
 * @property string $recognition_channel
 * @property int|null $shm_cash_transaction_id
 * @property int $journal_entry_id
 * @property string $status
 * @property int $linked_by
 * @property Carbon $linked_at
 * @property string|null $notes
 */
class ShmRefundAccountingLink extends Model
{
    use HasFactory;

    public const CHANNEL_CASH_OUTFLOW = 'cash_outflow';

    public const CHANNEL_MANUAL_JOURNAL = 'manual_journal';

    public const CHANNEL_XLSX_IMPORT = 'xlsx_import';

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_POSTED = 'POSTED';

    public const STATUS_REVERSED = 'REVERSED';

    protected $table = 'shm_refund_accounting_links';

    protected $guarded = ['id'];

    protected $casts = [
        'linked_at' => 'datetime',
    ];

    public function refund(): BelongsTo
    {
        return $this->belongsTo(ShmSettlementRefund::class, 'shm_settlement_refund_id');
    }

    public function cashTransaction(): BelongsTo
    {
        return $this->belongsTo(ShmCashTransaction::class, 'shm_cash_transaction_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function linker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_by');
    }

    /**
     * Scope only active effective POSTED recognitions.
     */
    public function scopeActivePosted($query)
    {
        return $query->where('accounting_context', AccountingContext::SHM)
            ->where('status', self::STATUS_POSTED);
    }
}
