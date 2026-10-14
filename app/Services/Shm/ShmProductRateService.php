<?php

namespace App\Services\Shm;

use App\Models\ShmLoanProduct;
use Illuminate\Validation\ValidationException;

/**
 * Ticket 82 — Resolver rate Pinjaman SHM: RATE = f(PRODUCT, TENOR).
 *
 * Source of truth tunggal resolusi rate. Universal 2%
 * (ShmLoan::OFFICIAL_RATE_PERCENT) TIDAK dipakai lagi di sini maupun di
 * pencairan/pembangkitan jadwal — rate sepenuhnya berasal dari tier rate
 * produk (shm_loan_product_rate_tiers).
 *
 * Kontrak EXAKT (T82):
 * - Tenor valid HANYA bila `min_tenor_months <= tenor <= max_tenor_months`
 *   pada tepat satu tier produk (inclusive di kedua ujung).
 * - Gap antar range INVALID — tanpa interpolasi, tanpa fallback ke range
 *   sebelumnya/berikutnya, tanpa nearest, tanpa default rate.
 * - Produk nonaktif/tanpa tier ditolak; rate produk lain tidak pernah
 *   dipakai.
 * - Seluruh hasil berperiode 'monthly', calculation_method 'flat'
 *   (baseline T79 yang tetap berlaku).
 *
 * Service ini TIDAK menulis apa pun; hanya membaca tier dan menghasilkan
 * ProductRateQuote. Backend adalah satu-satunya sumber kebenaran — frontend
 * tidak pernah mengirim rate authoritative.
 */
class ShmProductRateService
{
    /**
     * Resolve rate resmi untuk produk + tenor EXAKT.
     *
     * @throws ValidationException bila produk nonaktif atau tenor di luar
     *                             seluruh range produk (termasuk gap)
     */
    public function resolve(ShmLoanProduct $product, int $tenorMonths): ProductRateQuote
    {
        if (! $product->is_active) {
            throw ValidationException::withMessages([
                'pengajuan.tenor_months' => sprintf(
                    'Produk pinjaman %s tidak aktif sehingga rate tidak dapat ditentukan.',
                    $product->name,
                ),
            ]);
        }

        $tier = $product->rateTierFor($tenorMonths);

        if ($tier === null) {
            throw ValidationException::withMessages([
                'pengajuan.tenor_months' => sprintf(
                    'Tenor %d bulan tidak tersedia untuk %s.',
                    $tenorMonths,
                    $product->name,
                ),
            ]);
        }

        return new ProductRateQuote(
            product: $product,
            tier: $tier,
            tenorMonths: $tenorMonths,
            ratePercent: (float) $tier->rate_percent,
            ratePeriod: $tier->rate_period,
            calculationMethod: $tier->calculation_method,
        );
    }
}
