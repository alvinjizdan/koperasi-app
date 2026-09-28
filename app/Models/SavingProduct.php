<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SavingProduct extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'category',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Kategori produk: principal | mandatory | voluntary
     * (daftar kategori: config/business-rules.php 'savings.product_categories').
     */
    public const CATEGORY_PRINCIPAL = 'principal';

    public const CATEGORY_MANDATORY = 'mandatory';

    public const CATEGORY_VOLUNTARY = 'voluntary';

    /**
     * Peta kategori dan labelnya (Ticket 20: sumber tunggal validasi + UI).
     */
    public const CATEGORIES = [
        self::CATEGORY_PRINCIPAL => 'Simpanan Pokok',
        self::CATEGORY_MANDATORY => 'Simpanan Wajib',
        self::CATEGORY_VOLUNTARY => 'Simpanan Sukarela',
    ];

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? $this->category;
    }

    /**
     * Scope: hanya produk aktif.
     */
    public function scopeActive($query): void
    {
        $query->where('is_active', true);
    }

    public function savingsAccounts(): HasMany
    {
        return $this->hasMany(SavingsAccount::class);
    }

    /**
     * Produk tidak bisa dinonaktifkan dengan hard delete jika sudah ada rekening.
     */
    public function hasAccounts(): bool
    {
        return $this->savingsAccounts()->exists();
    }
}
