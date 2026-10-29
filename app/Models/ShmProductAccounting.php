<?php

namespace App\Models;

use App\Support\AccountingContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SHM Product Accounting Configuration (Ticket T006C).
 *
 * Menghubungkan Master Produk Pinjaman SHM (ShmLoanProduct) dengan Akun-Akun
 * Chart of Accounts resmi entitas Akuntansi SHM (AccountingContext::SHM).
 *
 * @property int $id
 * @property int $shm_loan_product_id
 * @property string $accounting_context
 * @property int|null $cash_account_id
 * @property int|null $receivable_account_id
 * @property int|null $interest_revenue_account_id
 * @property int|null $penalty_revenue_account_id
 * @property int|null $settlement_discount_account_id
 * @property bool $is_active
 * @property string|null $notes
 * @property int|null $created_by
 * @property int|null $updated_by
 */
class ShmProductAccounting extends Model
{
    use HasFactory;

    protected $table = 'shm_product_accountings';

    protected $fillable = [
        'shm_loan_product_id',
        'accounting_context',
        'cash_account_id',
        'receivable_account_id',
        'interest_revenue_account_id',
        'penalty_revenue_account_id',
        'settlement_discount_account_id',
        'is_active',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(ShmLoanProduct::class, 'shm_loan_product_id');
    }

    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'cash_account_id');
    }

    public function receivableAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'receivable_account_id');
    }

    public function interestRevenueAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'interest_revenue_account_id');
    }

    public function penaltyRevenueAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'penalty_revenue_account_id');
    }

    public function settlementDiscountAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'settlement_discount_account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Scope query to SHM accounting context.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeShm(Builder $query): Builder
    {
        return $query->where('accounting_context', AccountingContext::SHM);
    }

    /**
     * Scope query to active configurations.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
