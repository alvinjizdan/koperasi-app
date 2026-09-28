<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rincian alokasi pembayaran angsuran per komponen kewajiban (Ticket 15, E.5).
 *
 * Setiap (pembayaran, angsuran, komponen) maksimal satu baris; komponen:
 * penalty | interest | principal.
 */
class InstallmentAllocation extends Model
{
    public const COMPONENT_PENALTY = 'penalty';

    public const COMPONENT_INTEREST = 'interest';

    public const COMPONENT_PRINCIPAL = 'principal';

    protected $fillable = [
        'installment_payment_id',
        'installment_schedule_id',
        'component',
        'amount',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(InstallmentPayment::class, 'installment_payment_id');
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(InstallmentSchedule::class, 'installment_schedule_id');
    }
}
