<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class SavingsAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'account_number',
        'member_id',
        'saving_product_id',
        'opened_at',
        'status',
        'balance',
    ];

    protected $casts = [
        'opened_at' => 'date',
        'balance' => 'decimal:2',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function savingProduct(): BelongsTo
    {
        return $this->belongsTo(SavingProduct::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(SavingsTransaction::class);
    }

    /**
     * Generate nomor rekening unik dengan format REK-YYYYMMDD-XXXXXX.
     * Menggunakan sequence DB untuk mencegah race condition; constraint unique
     * pada kolom account_number menjadi safety net terakhir.
     */
    public static function generateAccountNumber(): string
    {
        $date = now()->format('Ymd');
        try {
            $sequence = DB::selectOne("SELECT nextval('savings_accounts_id_seq') AS seq");
            $seq = str_pad((string) $sequence->seq, 6, '0', STR_PAD_LEFT);
        } catch (\Throwable $e) {
            $maxId = (static::max('id') ?? 0) + 1;
            $seq = str_pad((string) $maxId, 6, '0', STR_PAD_LEFT);
        }

        return "REK-{$date}-{$seq}";
    }

    /**
     * Scope: hanya rekening aktif.
     */
    public function scopeActive($query): void
    {
        $query->where('status', 'active');
    }
}
