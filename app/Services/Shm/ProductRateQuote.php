<?php

namespace App\Services\Shm;

use App\Models\ShmLoanProduct;
use App\Models\ShmLoanProductRateTier;

/**
 * Ticket 82 — Hasil resolusi rate Pinjaman SHM (product + tenor).
 *
 * Value object READ-ONLY: tidak ada kolom rate pada pengajuan, sehingga
 * nilai ini tidak pernah ditulis dari payload klien — hanya dipakai untuk
 * validasi/penyimpanan saat pencairan dan penyajian tampilan.
 */
final readonly class ProductRateQuote
{
    public function __construct(
        public ShmLoanProduct $product,
        public ShmLoanProductRateTier $tier,
        public int $tenorMonths,
        public float $ratePercent,
        public string $ratePeriod,
        public string $calculationMethod,
    ) {}

    /**
     * @return array<string, int|string|float>
     */
    public function toArray(): array
    {
        return [
            'product_id' => $this->product->id,
            'product_name' => $this->product->name,
            'tenor_months' => $this->tenorMonths,
            'rate_percent' => $this->ratePercent,
            'rate_period' => $this->ratePeriod,
            'calculation_method' => $this->calculationMethod,
            'min_tenor_months' => $this->tier->min_tenor_months,
            'max_tenor_months' => $this->tier->max_tenor_months,
        ];
    }
}
