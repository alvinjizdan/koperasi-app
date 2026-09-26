<?php

namespace App\Models;

use Database\Factories\CashTransactionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class CashTransaction extends Model
{
    /** @use HasFactory<CashTransactionFactory> */
    use HasFactory;

    public const TYPE_INCOME = 'INCOME';

    public const TYPE_EXPENSE = 'EXPENSE';

    public const TYPE_DEPOSIT = 'DEPOSIT';

    public const STATUS_POSTED = 'POSTED';

    public const STATUS_REVERSED = 'REVERSED';

    protected $fillable = [
        'transaction_number',
        'cashier_session_id',
        'type',
        'category',
        'amount',
        'transaction_date',
        'transacted_at',
        'description',
        'document_reference',
        'processed_by',
        'status',
        'source_type',
        'source_id',
        'reversed_at',
        'reversed_by',
        'reversal_reason',
        'idempotency_key',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'transaction_date' => 'date',
        'transacted_at' => 'datetime',
        'reversed_at' => 'datetime',
    ];

    /**
     * Nomor transaksi unik dengan format PREFIX-YYYYMMDD-XXXXXX.
     */
    public static function generateTransactionNumber(string $type): string
    {
        $prefix = match ($type) {
            self::TYPE_INCOME => config('cashier.prefixes.income', 'KMASUK'),
            self::TYPE_EXPENSE => config('cashier.prefixes.expense', 'KKELUAR'),
            default => config('cashier.prefixes.deposit', 'KSETOR'),
        };

        $date = now()->format('Ymd');
        $maxId = (static::max('id') ?? 0) + 1;
        $seq = str_pad((string) $maxId, 6, '0', STR_PAD_LEFT);

        return "{$prefix}-{$date}-{$seq}";
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(CashierSession::class, 'cashier_session_id');
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    /**
     * Transaksi sumber dari modul lain (Simpanan, Pinjaman, Angsuran).
     */
    public function source(): MorphTo
    {
        return $this->morphTo('source', 'source_type', 'source_id');
    }

    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }

    /**
     * Transaksi yang belum dikoreksi dan masih memengaruhi saldo kas.
     */
    public function scopePosted(Builder $query): void
    {
        $query->where('status', self::STATUS_POSTED);
    }

    /**
     * Scope: transaksi yang dicatat langsung melalui modul kasir (tanpa transaksi sumber).
     */
    public function scopeManual(Builder $query): void
    {
        $query->whereNull('source_type');
    }

    public function getTypeLabelAttribute(): string
    {
        return match ($this->type) {
            self::TYPE_INCOME => 'Penerimaan',
            self::TYPE_EXPENSE => 'Pengeluaran',
            self::TYPE_DEPOSIT => 'Setoran Kasir',
            default => $this->type,
        };
    }

    public function getSourceLabelAttribute(): ?string
    {
        return match ($this->source_type) {
            SavingsTransaction::class => 'Transaksi Simpanan',
            LoanDisbursement::class => 'Pencairan Pinjaman',
            InstallmentPayment::class => 'Pembayaran Angsuran',
            default => null,
        };
    }

    /**
     * Arah dampak kas: true bila menambah saldo kas.
     */
    public function getIncreasesCashAttribute(): bool
    {
        return $this->type === self::TYPE_INCOME;
    }

    /**
     * Representasi transaksi kas untuk tampilan React/Inertia.
     *
     * @return array<string, mixed>
     */
    public function toPayloadArray(): array
    {
        return [
            'id' => $this->id,
            'transaction_number' => $this->transaction_number,
            'cashier_session_id' => $this->cashier_session_id,
            'session_number' => $this->relationLoaded('session') && $this->session !== null
                ? $this->session->session_number
                : null,
            'type' => $this->type,
            'type_label' => $this->type_label,
            'category' => $this->category,
            'amount' => round((float) $this->amount, 2),
            'transaction_date' => $this->transaction_date?->format('Y-m-d'),
            'transacted_at' => $this->transacted_at?->toDateTimeString(),
            'description' => $this->description,
            'document_reference' => $this->document_reference,
            'status' => $this->status,
            'increases_cash' => $this->increases_cash,
            'operator' => $this->relationLoaded('operator') && $this->operator !== null
                ? ['id' => $this->operator->id, 'name' => $this->operator->name]
                : ['id' => $this->processed_by, 'name' => null],
            'source_label' => $this->source_label,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
        ];
    }
}
