<?php

namespace App\Models\Shm;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class ShmSavingsTransaction extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $table = 'shm_savings_transactions';

    protected $fillable = [
        'transaction_number',
        'shm_savings_account_id',
        'type',
        'amount',
        'transaction_date',
        'description',
        'processed_by',
        'status',
        'idempotency_key',
        'reversal_of_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'transaction_date' => 'date',
    ];

    public function savingsAccount(): BelongsTo
    {
        return $this->belongsTo(ShmSavingsAccount::class, 'shm_savings_account_id');
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function reversals(): HasMany
    {
        return $this->hasMany(self::class, 'reversal_of_id');
    }

    public static function generateTransactionNumber(string $type): string
    {
        $prefix = strtoupper($type) === 'WITHDRAWAL' ? 'TRX-SHM-WD' : 'TRX-SHM-DEP';
        $date = now()->format('Ymd');
        try {
            $sequence = DB::selectOne("SELECT nextval('shm_savings_transactions_id_seq') AS seq");
            $seq = str_pad((string) $sequence->seq, 6, '0', STR_PAD_LEFT);
        } catch (\Throwable $e) {
            $maxId = (static::max('id') ?? 0) + 1;
            $seq = str_pad((string) $maxId, 6, '0', STR_PAD_LEFT);
        }

        return "{$prefix}-{$date}-{$seq}";
    }
}
