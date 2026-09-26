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
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

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
