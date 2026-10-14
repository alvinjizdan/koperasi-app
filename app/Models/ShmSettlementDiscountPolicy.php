<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Master Policy Diskon Bunga Pelunasan SHM (T89B).
 *
 * DATA-DRIVEN (AC1): admin mengatur persentase diskon per rentang sisa
 * bulan TANPA mengubah source code, dan TIDAK ada default percentage yang
 * di-seed (AC7). Hanya berlaku untuk settlement pinjaman SHM dengan
 * plafon awal > Rp50.000.000 (AC3/AC4).
 *
 * Rentang wajib valid (min <= max); overlap policy aktif untuk rentang
 * bulan + effective period yang sama DITOLAK saat penyimpanan sehingga
 * pemilihan policy selalu deterministik.
 *
 * @property int $id
 * @property int $min_remaining_months
 * @property int $max_remaining_months
 * @property string $discount_percent
 * @property bool $is_active
 * @property Carbon|null $effective_from
 * @property Carbon|null $effective_until
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ShmSettlementDiscountPolicy extends Model
{
    use HasFactory;

    protected $fillable = [
        'min_remaining_months',
        'max_remaining_months',
        'discount_percent',
        'is_active',
        'effective_from',
        'effective_until',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'min_remaining_months' => 'integer',
            'max_remaining_months' => 'integer',
            'discount_percent' => 'decimal:2',
            'is_active' => 'boolean',
            'effective_from' => 'date',
            'effective_until' => 'date',
        ];
    }

    /**
     * Settlement yang memakai policy ini (snapshot immutable — policy lama
     * tidak boleh dihapus selama dipakai).
     *
     * @return HasMany<ShmLoanSettlement, $this>
     */
    public function settlements(): HasMany
    {
        return $this->hasMany(ShmLoanSettlement::class, 'discount_policy_id');
    }

    /**
     * Scope: policy aktif.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Apakah policy ini berlaku pada tanggal settlement tertentu
     * (effective_from/effective_until nullable = tanpa batas)?
     */
    public function effectiveOn(Carbon $date): bool
    {
        if ($this->effective_from !== null && $date->lt($this->effective_from)) {
            return false;
        }

        if ($this->effective_until !== null && $date->gt($this->effective_until)) {
            return false;
        }

        return true;
    }

    /**
     * Apakah sisa bulan masuk rentang policy ini?
     */
    public function matchesRemainingMonths(int $remainingMonths): bool
    {
        return $remainingMonths >= $this->min_remaining_months
            && $remainingMonths <= $this->max_remaining_months;
    }

    /**
     * Format label ringkas untuk UI.
     */
    public function rangeLabel(): string
    {
        return sprintf(
            '%d–%d bulan @ %s%%',
            $this->min_remaining_months,
            $this->max_remaining_months,
            rtrim(rtrim(number_format((float) $this->discount_percent, 2, '.', ''), '0'), '.'),
        );
    }
}
