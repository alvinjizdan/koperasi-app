<?php

namespace App\Models;

use App\Support\AccountingContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SHM Accounting Mapping (T006D).
 *
 * Pemetaan transaksi/event bisnis pinjaman SHM ke peran akun akuntansi
 * (account role) atau akun CoA spesifik dalam AccountingContext::SHM.
 */
class ShmAccountingMapping extends Model
{
    // Authoritative Business Event Identifiers
    public const EVENT_DISBURSEMENT = 'SHM_LOAN_DISBURSEMENT';

    public const EVENT_REGULAR_PAYMENT = 'SHM_PAYMENT';

    public const EVENT_SETTLEMENT = 'SHM_SETTLEMENT';

    public const EVENT_SETTLEMENT_CORRECTION = 'SHM_SETTLEMENT_CORRECTION';

    // Authoritative Account Roles (resolvable via ShmProductAccounting)
    public const ROLE_CASH = 'cash';

    public const ROLE_RECEIVABLE = 'receivable';

    public const ROLE_INTEREST_REVENUE = 'interest_revenue';

    public const ROLE_PENALTY_REVENUE = 'penalty_revenue';

    public const ROLE_SETTLEMENT_DISCOUNT = 'settlement_discount';

    protected $fillable = [
        'accounting_context',
        'business_event',
        'shm_loan_product_id',
        'debit_account_role',
        'debit_account_id',
        'credit_account_role',
        'credit_account_id',
        'is_active',
        'description',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(ShmLoanProduct::class, 'shm_loan_product_id');
    }

    public function debitAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'debit_account_id');
    }

    public function creditAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'credit_account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeShm($query)
    {
        return $query->where('accounting_context', AccountingContext::SHM);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * List supported business events.
     *
     * @return array<string, string>
     */
    public static function supportedEvents(): array
    {
        return [
            self::EVENT_DISBURSEMENT => 'Pencairan Pinjaman SHM (Disbursement)',
            self::EVENT_REGULAR_PAYMENT => 'Pembayaran Angsuran Reguler SHM',
            self::EVENT_SETTLEMENT => 'Pelunasan Dipercepat SHM (Settlement)',
            self::EVENT_SETTLEMENT_CORRECTION => 'Koreksi Pelunasan SHM (Settlement Correction)',
        ];
    }

    /**
     * List supported account roles.
     *
     * @return array<string, string>
     */
    public static function supportedRoles(): array
    {
        return [
            self::ROLE_CASH => 'Kas / Bank (Dari Konfigurasi Produk)',
            self::ROLE_RECEIVABLE => 'Piutang Pokok Pinjaman (Dari Konfigurasi Produk)',
            self::ROLE_INTEREST_REVENUE => 'Pendapatan Bunga (Dari Konfigurasi Produk)',
            self::ROLE_PENALTY_REVENUE => 'Pendapatan Denda (Dari Konfigurasi Produk)',
            self::ROLE_SETTLEMENT_DISCOUNT => 'Diskon Pelunasan (Dari Konfigurasi Produk)',
        ];
    }
}
