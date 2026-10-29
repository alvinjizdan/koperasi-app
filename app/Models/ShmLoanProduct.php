<?php

namespace App\Models;

use Database\Factories\ShmLoanProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Master produk Pinjaman SHM (Ticket 64; kontrak rate T82).
 *
 * TERPISAH dari `App\Models\Loan` dan master pinjaman koperasi. Sejak T82
 * master ini menjadi source of truth kategori & rate produk: rate harian
 * TIDAK disimpan sebagai satu kolom global — rate = f(product, tenor) yang
 * diambil dari child `shm_loan_product_rate_tiers` (lihat
 * ShmProductRateService). Universal 2% BUKAN lagi rate SHM (T82).
 *
 * Seluruh produk memakai calculation_method 'flat' dan rate_period
 * 'monthly' (baseline T79 yang tetap berlaku pasca T82).
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $category
 * @property string|null $description
 * @property string|null $eligibility_summary
 * @property bool $is_active
 */
class ShmLoanProduct extends Model
{
    /** @use HasFactory<ShmLoanProductFactory> */
    use HasFactory;

    /** Kategori produk resmi T82. */
    public const CATEGORY_PERUMAHAN = 'PERUMAHAN';

    public const CATEGORY_PERKAMPUNGAN = 'PERKAMPUNGAN';

    public const CATEGORY_RO = 'RO';

    protected $fillable = [
        'code',
        'name',
        'category',
        'description',
        'eligibility_summary',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Tier rate produk (rentang tenor EXAKT) — urut mena menurut tenor min.
     *
     * @return HasMany<ShmLoanProductRateTier, $this>
     */
    public function rateTiers(): HasMany
    {
        return $this->hasMany(ShmLoanProductRateTier::class, 'shm_loan_product_id')
            ->orderBy('min_tenor_months')
            ->orderBy('max_tenor_months');
    }

    /**
     * Tier yang menutup tenor EXAKT ini, atau null bila tenor berada di gap
     * / di luar seluruh range produk. Tidak ada interpolasi/fallback.
     */
    public function rateTierFor(int $tenorMonths): ?ShmLoanProductRateTier
    {
        return $this->rateTiers()
            ->where('min_tenor_months', '<=', $tenorMonths)
            ->where('max_tenor_months', '>=', $tenorMonths)
            ->first();
    }

    /**
     * Record pinjaman SHM yang memakai produk ini.
     *
     * @return HasMany<ShmLoan, $this>
     */
    public function loans(): HasMany
    {
        return $this->hasMany(ShmLoan::class);
    }

    /**
     * Konfigurasi Akuntansi Produk SHM (T006C).
     *
     * @return HasOne<ShmProductAccounting, $this>
     */
    public function accounting(): HasOne
    {
        return $this->hasOne(ShmProductAccounting::class, 'shm_loan_product_id');
    }

    /**
     * Hanya produk yang masih aktif.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope pencarian kode/nama produk pinjaman SHM.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeSearch(Builder $query, ?string $search): Builder
    {
        if (empty($search)) {
            return $query;
        }

        $term = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($search)).'%';

        return $query->where(function (Builder $q) use ($term): void {
            $q->where('code', 'like', $term)
                ->orWhere('name', 'like', $term);
        });
    }
}
