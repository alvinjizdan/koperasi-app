<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Chart of Accounts — daftar perkiraan akun (Ticket 18).
 *
 * Kategori: asset | liability | equity | revenue | expense.
 * Saldo normal menentukan arah penjumlahan saldo pada buku besar.
 */
class ChartOfAccount extends Model
{
    use HasFactory;

    public const CATEGORY_ASSET = 'asset';

    public const CATEGORY_LIABILITY = 'liability';

    public const CATEGORY_EQUITY = 'equity';

    public const CATEGORY_REVENUE = 'revenue';

    public const CATEGORY_EXPENSE = 'expense';

    public const NORMAL_DEBIT = 'debit';

    public const NORMAL_CREDIT = 'credit';

    /** Kategori standar akuntansi dan saldo normalnya. */
    public const CATEGORIES = [
        self::CATEGORY_ASSET => ['label' => 'Aset', 'normal_balance' => self::NORMAL_DEBIT],
        self::CATEGORY_LIABILITY => ['label' => 'Liabilitas', 'normal_balance' => self::NORMAL_CREDIT],
        self::CATEGORY_EQUITY => ['label' => 'Ekuitas', 'normal_balance' => self::NORMAL_CREDIT],
        self::CATEGORY_REVENUE => ['label' => 'Pendapatan', 'normal_balance' => self::NORMAL_CREDIT],
        self::CATEGORY_EXPENSE => ['label' => 'Beban', 'normal_balance' => self::NORMAL_DEBIT],
    ];

    protected $fillable = [
        'code',
        'name',
        'category',
        'normal_balance',
        'parent_id',
        'is_active',
        'description',
        'is_template',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_template' => 'boolean',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function journalLines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'account_id');
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category]['label'] ?? $this->category;
    }

    public function isUsedInJournals(): bool
    {
        return $this->journalLines()->exists();
    }
}
