<?php

namespace App\Models\Shm;

use App\Models\ShmCustomer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class ShmSavingsAccount extends Model
{
    use HasFactory;

    protected $table = 'shm_savings_accounts';

    protected $fillable = [
        'account_number',
        'shm_customer_id',
        'shm_saving_product_id',
        'opened_at',
        'status',
        'balance',
    ];

    protected $casts = [
        'opened_at' => 'date',
        'balance' => 'decimal:2',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(ShmCustomer::class, 'shm_customer_id');
    }

    public function savingProduct(): BelongsTo
    {
        return $this->belongsTo(ShmSavingProduct::class, 'shm_saving_product_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(ShmSavingProduct::class, 'shm_saving_product_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(ShmSavingsTransaction::class, 'shm_savings_account_id');
    }

    /**
     * Generate nomor rekening unik dengan format REK-SHM-YYYYMMDD-XXXXXX.
     */
    public static function generateAccountNumber(): string
    {
        $date = now()->format('Ymd');
        try {
            $sequence = DB::selectOne("SELECT nextval('shm_savings_accounts_id_seq') AS seq");
            $seq = str_pad((string) $sequence->seq, 6, '0', STR_PAD_LEFT);
        } catch (\Throwable $e) {
            $maxId = (static::max('id') ?? 0) + 1;
            $seq = str_pad((string) $maxId, 6, '0', STR_PAD_LEFT);
        }

        return "REK-SHM-{$date}-{$seq}";
    }

    public function scopeActive($query): void
    {
        $query->where('status', 'active');
    }
}
