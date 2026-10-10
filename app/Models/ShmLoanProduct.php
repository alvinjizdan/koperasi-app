<?php

namespace App\Models;

use Database\Factories\ShmLoanProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Master produk Pinjaman SHM (Ticket 64).
 *
 * TERPISAH dari `App\Models\Loan` dan master pinjaman koperasi. Tidak ada
 * kolom rate/metode default di sini: rate resmi SHM memang 2%, tetapi
 * periodisasi (per bulan/per tahun) dan formula angsuran (flat/efektif/
 * anuitas) masih PENDING BUSINESS CONFIRMATION sehingga tidak boleh dikunci
 * sebagai default master.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property bool $is_active
 */
class ShmLoanProduct extends Model
{
    /** @use HasFactory<ShmLoanProductFactory> */
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
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
