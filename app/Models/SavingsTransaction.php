<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavingsTransaction extends Model
{
    use HasFactory;

    /**
     * Transaksi tidak memiliki updated_at: rekaman transaksi bersifat immutable.
     */
    public const UPDATED_AT = null;

    protected $fillable = [
        'transaction_number',
        'savings_account_id',
        'type',
        'amount',
        'transaction_date',
        'description',
        'processed_by',
        'status',
        'idempotency_key',
        'reversal_of_id',
        'entry_type',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'transaction_date' => 'date',
    ];

    /**
     * Transaksi asal bila baris ini merupakan reversal/penyesuaian (A.8).
     */
    public function reversalOf()
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function reversals()
    {
        return $this->hasMany(self::class, 'reversal_of_id');
    }

    public function savingsAccount(): BelongsTo
    {
        return $this->belongsTo(SavingsAccount::class);
    }

    /**
     * Operator yang mencatat transaksi (relasi ke User).
     */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }
}
