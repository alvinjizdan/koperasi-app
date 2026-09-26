<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoanDisbursement extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'disbursement_number',
        'loan_id',
        'amount',
        'disbursement_date',
        'method',
        'processed_by',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'disbursement_date' => 'date',
    ];

    public static function generateDisbursementNumber(): string
    {
        $date = now()->format('Ymd');
        $maxId = (static::max('id') ?? 0) + 1;
        $seq = str_pad((string) $maxId, 6, '0', STR_PAD_LEFT);

        return "CAIR-{$date}-{$seq}";
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }
}
