<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Loan extends Model
{
    use HasFactory;

    protected $fillable = [
        'loan_number',
        'member_id',
        'amount',
        'tenor_months',
        'submission_date',
        'status',
        'approved_at',
        'approved_by',
        'rejected_at',
        'rejected_by',
        'rejection_reason',
        'disbursed_at',
        'disbursed_by',
        'first_due_date',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'submission_date' => 'date',
        'approved_at' => 'date',
        'rejected_at' => 'date',
        'disbursed_at' => 'date',
        'first_due_date' => 'date',
    ];

    public static function generateLoanNumber(): string
    {
        $date = now()->format('Ymd');
        $maxId = (static::max('id') ?? 0) + 1;
        $seq = str_pad((string) $maxId, 6, '0', STR_PAD_LEFT);

        return "PINJ-{$date}-{$seq}";
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function disburser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disbursed_by');
    }

    public function disbursement(): HasOne
    {
        return $this->hasOne(LoanDisbursement::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(InstallmentSchedule::class)->orderBy('installment_number');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(InstallmentPayment::class)->orderBy('created_at');
    }

    /**
     * Hitung total pembayaran yang berhasil dilakukan
     */
    public function getTotalPaidAttribute(): float
    {
        return (float) $this->payments()->where('status', 'COMPLETED')->sum('amount');
    }

    /**
     * Hitung sisa kewajiban pokok
     */
    public function getRemainingBalanceAttribute(): float
    {
        $disbursedAmount = $this->disbursement ? (float) $this->disbursement->amount : 0.0;
        $totalPaid = $this->total_paid;

        return max(0.0, $disbursedAmount - $totalPaid);
    }
}
