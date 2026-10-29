<?php

namespace App\Models;

use App\Support\AccountingContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Journal Entry — jurnal umum double-entry (Ticket 18).
 *
 * Status: DRAFT (dapat diedit) → POSTED (immutable, hanya reversal) →
 * REVERSED (ditandai saat jurnal pembalik diposting).
 */
class JournalEntry extends Model
{
    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_POSTED = 'POSTED';

    public const STATUS_REVERSED = 'REVERSED';

    protected $fillable = [
        'accounting_context',
        'journal_number',
        'transaction_date',
        'description',
        'status',
        'source_type',
        'source_id',
        'posting_type',
        'reversed_by_entry_id',
        'created_by',
        'posted_by',
        'posted_at',
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'posted_at' => 'datetime',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class)->orderBy('line_order');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_by_entry_id');
    }

    public function totalDebit(): float
    {
        return round((float) $this->lines()->sum('debit'), 2);
    }

    public function totalCredit(): float
    {
        return round((float) $this->lines()->sum('credit'), 2);
    }

    public function isBalanced(): bool
    {
        return $this->totalDebit() === $this->totalCredit() && $this->totalDebit() > 0.0;
    }

    /**
     * Scope query to a specific accounting context.
     */
    public function scopeContext($query, ?string $context)
    {
        $normalized = AccountingContext::normalize($context);

        return $query->where('accounting_context', $normalized);
    }

    /**
     * Scope query to Koperasi accounting context.
     */
    public function scopeKoperasi($query)
    {
        return $query->where('accounting_context', AccountingContext::KOPERASI);
    }

    /**
     * Scope query to SHM accounting context.
     */
    public function scopeShm($query)
    {
        return $query->where('accounting_context', AccountingContext::SHM);
    }
}
