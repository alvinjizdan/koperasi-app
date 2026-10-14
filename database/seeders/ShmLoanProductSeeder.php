<?php

namespace Database\Seeders;

use App\Models\ShmLoanProduct;
use Illuminate\Database\Seeder;

/**
 * Ticket 82 — Matriks produk & rate resmi Pinjaman SHM.
 *
 * Sumber: keputusan bisnis T82 (OFFICIAL PRODUCT MATRIX). Deterministik:
 * updateOrCreate berbasis `code` sehingga aman dijalankan berulang tanpa
 * menggandakan master maupun tier. TIDAK ada produk tambahan di luar lima
 * produk resmi, dan nama/kategori tidak boleh diubah spelling-nya.
 *
 * Kontrak data:
 * - Kategori: PERUMAHAN (BSM Prioritas, BSM Flexibel), PERKAMPUNGAN
 *   (BSM Perkampungan), RO (BSM RO Exelent, BSM RO Flexibel).
 * - Rate per bulan (percent) pada tenor EXAKT; gap antar range tetap
 *   invalid (tidak diisi oleh seeder mana pun).
 * - Seluruh tier: rate_period 'monthly', calculation_method 'flat'
 *   (baseline T79 yang tetap berlaku pasca T82).
 * - Eligibility dicatat sebagai ringkasan teks; enforcement-nya tetap
 *   PENDING BUSINESS CONFIRMATION (data kolektabilitas/pastdue/plafon
 *   tidak ada di sistem) — T82 tidak menebak perhitungannya.
 */
class ShmLoanProductSeeder extends Seeder
{
    /**
     * Matriks resmi: code, name, category, eligibility, tiers [min, max, rate].
     *
     * @var array<int, array<string, mixed>>
     */
    private const OFFICIAL_MATRIX = [
        [
            'code' => 'BSM-PRIORITAS',
            'name' => 'BSM Prioritas',
            'category' => ShmLoanProduct::CATEGORY_PERUMAHAN,
            'eligibility_summary' => 'Kolektabilitas seluruh kredit aktif harus Kol 1; wajib karyawan (suami atau istri).',
            'tiers' => [
                [12, 18, 1.30],
                [24, 30, 1.40],
                [36, 42, 1.50],
                [48, 52, 1.60],
                [60, 60, 1.70],
            ],
        ],
        [
            'code' => 'BSM-FLEXIBEL',
            'name' => 'BSM Flexibel',
            'category' => ShmLoanProduct::CATEGORY_PERUMAHAN,
            'eligibility_summary' => 'Plafon maksimal Rp100.000.000; kolektabilitas boleh Kol 2–Kol 5; maksimal BD Rp10.000.000.',
            'tiers' => [
                [12, 18, 1.40],
                [24, 30, 1.50],
                [36, 48, 1.60],
            ],
        ],
        [
            'code' => 'BSM-PERKAMPUNGAN',
            'name' => 'BSM Perkampungan',
            'category' => ShmLoanProduct::CATEGORY_PERKAMPUNGAN,
            'eligibility_summary' => null,
            'tiers' => [
                [12, 18, 1.40],
                [24, 30, 1.50],
                [36, 36, 1.60],
            ],
        ],
        [
            'code' => 'BSM-RO-EXELENT',
            'name' => 'BSM RO Exelent',
            'category' => ShmLoanProduct::CATEGORY_RO,
            'eligibility_summary' => 'Pastdue 1–7 hari.',
            'tiers' => [
                [12, 18, 1.10],
                [24, 30, 1.20],
                [36, 42, 1.30],
                [48, 48, 1.40],
            ],
        ],
        [
            'code' => 'BSM-RO-FLEXIBEL',
            'name' => 'BSM RO Flexibel',
            'category' => ShmLoanProduct::CATEGORY_RO,
            'eligibility_summary' => 'Pastdue 8–30 hari.',
            'tiers' => [
                [12, 18, 1.20],
                [24, 30, 1.30],
                [36, 42, 1.40],
                [48, 48, 1.50],
            ],
        ],
    ];

    public function run(): void
    {
        foreach (self::OFFICIAL_MATRIX as $product) {
            $record = ShmLoanProduct::query()->updateOrCreate(
                ['code' => $product['code']],
                [
                    'name' => $product['name'],
                    'category' => $product['category'],
                    'eligibility_summary' => $product['eligibility_summary'],
                    'is_active' => true,
                ],
            );

            foreach ($product['tiers'] as [$min, $max, $rate]) {
                // Keyed by min/max → idempoten per tier (aman dijalankan
                // ulang tanpa menggandakan atau mengubah tier lain).
                $record->rateTiers()->updateOrCreate(
                    [
                        'min_tenor_months' => $min,
                        'max_tenor_months' => $max,
                    ],
                    [
                        'rate_percent' => $rate,
                        'rate_period' => 'monthly',
                        'calculation_method' => 'flat',
                    ],
                );
            }
        }
    }
}
