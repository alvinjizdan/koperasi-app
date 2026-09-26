<?php

namespace App\Models;

use Database\Factories\CashierSessionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashierSession extends Model
{
    /** @use HasFactory<CashierSessionFactory> */
    use HasFactory;

    public const STATUS_OPEN = 'OPEN';

    public const STATUS_CLOSED = 'CLOSED';

    protected $fillable = [
        'session_number',
        'operational_date',
        'opened_by',
        'opening_balance',
        'opening_notes',
        'status',
        'opened_at',
        'total_income',
        'total_expense',
        'total_deposit',
        'system_closing_balance',
        'physical_closing_balance',
        'difference',
        'closed_at',
        'closed_by',
        'closing_notes',
    ];

    protected $casts = [
        'operational_date' => 'date',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'opening_balance' => 'decimal:2',
        'total_income' => 'decimal:2',
        'total_expense' => 'decimal:2',
        'total_deposit' => 'decimal:2',
        'system_closing_balance' => 'decimal:2',
        'physical_closing_balance' => 'decimal:2',
        'difference' => 'decimal:2',
    ];

    /**
     * Nomor sesi unik dengan format SESI-YYYYMMDD-XXXXXX.
     */
    public static function generateSessionNumber(): string
    {
        $date = now()->format('Ymd');
        $maxId = (static::max('id') ?? 0) + 1;
        $seq = str_pad((string) $maxId, 6, '0', STR_PAD_LEFT);

        return config('cashier.prefixes.session', 'SESI')."-{$date}-{$seq}";
    }

    /**
     * Operator yang membuka sesi kasir.
     */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    /**
     * Operator yang menutup sesi kasir.
     */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(CashTransaction::class);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    /**
     * Scope: hanya sesi kasir yang masih terbuka.
     */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', self::STATUS_OPEN);
    }

    /**
     * Scope: hanya sesi kasir yang sudah ditutup.
     */
    public function scopeClosed(Builder $query): void
    {
        $query->where('status', self::STATUS_CLOSED);
    }

    /**
     * Eager load total transaksi sah (POSTED) per jenis sebagai alias income_total,
     * expense_total, dan deposit_total.
     */
    public function scopeWithTotals(Builder $query): void
    {
        $postedOnly = fn (Builder $query, string $type) => $query
            ->where('type', $type)
            ->where('status', CashTransaction::STATUS_POSTED);

        $query
            ->withSum(['transactions as income_total' => fn (Builder $q) => $postedOnly($q, CashTransaction::TYPE_INCOME)], 'amount')
            ->withSum(['transactions as expense_total' => fn (Builder $q) => $postedOnly($q, CashTransaction::TYPE_EXPENSE)], 'amount')
            ->withSum(['transactions as deposit_total' => fn (Builder $q) => $postedOnly($q, CashTransaction::TYPE_DEPOSIT)], 'amount');
    }

    /**
     * Representasi sesi kasir untuk tampilan ringkasan.
     *
     * Saldo akhir sistem selalu dihitung dari ledger transaksi sah (POSTED),
     * bukan dari input pengguna.
     *
     * @return array<string, mixed>
     */
    public function toSummaryArray(): array
    {
        $openingBalance = round((float) $this->opening_balance, 2);
        $income = round((float) ($this->income_total ?? 0), 2);
        $expense = round((float) ($this->expense_total ?? 0), 2);
        $deposit = round((float) ($this->deposit_total ?? 0), 2);

        return [
            'id' => $this->id,
            'session_number' => $this->session_number,
            'operational_date' => $this->operational_date?->format('Y-m-d'),
            'operator' => $this->relationLoaded('operator') && $this->operator !== null
                ? ['id' => $this->operator->id, 'name' => $this->operator->name]
                : ['id' => $this->opened_by, 'name' => null],
            'opening_balance' => $openingBalance,
            'income_total' => $income,
            'expense_total' => $expense,
            'deposit_total' => $deposit,
            'system_balance' => round($openingBalance + $income - $expense - $deposit, 2),
            'physical_closing_balance' => $this->physical_closing_balance !== null
                ? round((float) $this->physical_closing_balance, 2)
                : null,
            'difference' => $this->difference !== null ? round((float) $this->difference, 2) : null,
            'status' => $this->status,
            'opened_at' => $this->opened_at?->toDateTimeString(),
            'closed_at' => $this->closed_at?->toDateTimeString(),
            'opening_notes' => $this->opening_notes,
            'closing_notes' => $this->closing_notes,
        ];
    }
}
