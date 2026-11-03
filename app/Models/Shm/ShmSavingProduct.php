<?php

namespace App\Models\Shm;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShmSavingProduct extends Model
{
    use HasFactory;

    protected $table = 'shm_saving_products';

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

    public const CATEGORY_PRINCIPAL = 'principal';

    public const CATEGORY_MANDATORY = 'mandatory';

    public const CATEGORY_VOLUNTARY = 'voluntary';

    public const CATEGORIES = [
        self::CATEGORY_PRINCIPAL => 'Simpanan Pokok',
        self::CATEGORY_MANDATORY => 'Simpanan Wajib',
        self::CATEGORY_VOLUNTARY => 'Simpanan Sukarela',
    ];

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? $this->category;
    }

    public function scopeActive($query): void
    {
        $query->where('is_active', true);
    }

    public function savingsAccounts(): HasMany
    {
        return $this->hasMany(ShmSavingsAccount::class, 'shm_saving_product_id');
    }
}
