<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Ticket 82 — Tier rate Pinjaman SHM per rentang tenor EXAKT.
 *
 * Rate SHM = f(product, tenor). Setiap baris merepresentasikan satu tier:
 * tenor valid HANYA bila `min_tenor_months <= tenor <= max_tenor_months`
 * pada satu tier produk tersebut. Gap antar tier SAH dan tetap INVALID —
 * tidak ada interpolasi, fallback, nearest range, maupun default rate.
 *
 * Seluruh tier memakai kontrak baseline T79 yang tetap berlaku pasca T82:
 * rate_period = 'monthly' dan calculation_method = 'flat'.
 *
 * @property int $id
 * @property int $shm_loan_product_id
 * @property int $min_tenor_months
 * @property int $max_tenor_months
 * @property string $rate_percent
 * @property string $rate_period
 * @property string $calculation_method
 */
class ShmLoanProductRateTier extends Model
{
    protected $fillable = [
        'shm_loan_product_id',
        'min_tenor_months',
        'max_tenor_months',
        'rate_percent',
        'rate_period',
        'calculation_method',
    ];

    /**
     * Integritas tier (T82) di level model — berlaku untuk seeder, factory,
     * dan kode mana pun (constraint DB eksklusif tidak portabel ke SQLite):
     * - min <= max; rate > 0; periode monthly; metode flat;
     * - TIDAK boleh ada range yang tumpang tindih dalam produk yang sama
     *   (gap antar range SAH dan tetap invalid untuk lookup).
     */
    protected static function booted(): void
    {
        static::saving(function (self $tier): void {
            if ($tier->min_tenor_months < 1 || $tier->min_tenor_months > $tier->max_tenor_months) {
                throw new RuntimeException(
                    'Tier rate tidak valid: min_tenor_months harus >= 1 dan <= max_tenor_months.'
                );
            }

            if ((float) $tier->rate_percent <= 0) {
                throw new RuntimeException('Tier rate tidak valid: rate_percent harus > 0.');
            }

            if ($tier->rate_period !== 'monthly' || $tier->calculation_method !== 'flat') {
                throw new RuntimeException(
                    'Tier rate tidak valid: kontrak T79 mensyaratkan rate_period monthly dan calculation_method flat.'
                );
            }

            $overlap = self::query()
                ->where('shm_loan_product_id', $tier->shm_loan_product_id)
                ->when($tier->exists, fn ($query) => $query->whereKeyNot($tier->id))
                ->where('min_tenor_months', '<=', $tier->max_tenor_months)
                ->where('max_tenor_months', '>=', $tier->min_tenor_months)
                ->exists();

            if ($overlap) {
                throw new RuntimeException(sprintf(
                    'Tier rate tumpang tindih pada produk (rentang %d–%d bulan). Rentang tidak boleh overlap; gap antar range diperbolehkan.',
                    $tier->min_tenor_months,
                    $tier->max_tenor_months,
                ));
            }
        });
    }

    protected function casts(): array
    {
        return [
            'shm_loan_product_id' => 'integer',
            'min_tenor_months' => 'integer',
            'max_tenor_months' => 'integer',
            'rate_percent' => 'decimal:4',
        ];
    }

    /**
     * Produk pemilik tier ini.
     *
     * @return BelongsTo<ShmLoanProduct, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(ShmLoanProduct::class, 'shm_loan_product_id');
    }

    /**
     * Apakah tenor ini berada di dalam rentang EXAKT tier ini?
     * (Inclusive di kedua ujung; gap antar tier tidak cocok tier mana pun.)
     */
    public function covers(int $tenorMonths): bool
    {
        return $tenorMonths >= $this->min_tenor_months
            && $tenorMonths <= $this->max_tenor_months;
    }
}
