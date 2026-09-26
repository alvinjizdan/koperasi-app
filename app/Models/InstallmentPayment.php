<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstallmentPayment extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'payment_number',
        'loan_id',
        'installment_schedule_id',
        'payment_date',
        'amount',
        'processed_by',
        'notes',
        'status',
        'idempotency_key',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'payment_date' => 'date',
    ];

    public static function generatePaymentNumber(): string
    {
        $date = now()->format('Ymd');
        $maxId = (static::max('id') ?? 0) + 1;
        $seq = str_pad((string) $maxId, 6, '0', STR_PAD_LEFT);

        return "ANGS-{$date}-{$seq}";
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(InstallmentSchedule::class, 'installment_schedule_id');
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }
}
