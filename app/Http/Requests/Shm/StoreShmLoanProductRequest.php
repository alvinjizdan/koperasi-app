<?php

namespace App\Http\Requests\Shm;

use App\Models\ShmLoanProduct;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Ticket 98-FIX — pembuatan master produk Pinjaman SHM.
 *
 * Validasi server-side terhadap skema `shm_loan_products` yang SUDAH ada
 * (tanpa migrasi baru): kode UNIQUE server-side, kategori dibatasi pada
 * tiga kategori resmi T82 (PERUMAHAN/PERKAMPUNGAN/RO). Tidak ada kolom rate
 * dalam payload: rate resmi selalu diresolusi dari tier produk
 * (ShmProductRateService) sehingga fallback 2% tidak pernah terjadi.
 *
 * Produk baru TANPA tier rate: pengajuan akan ditolak resolver sampai tier
 * diisi (seed-driven, T82) — CRUD ini tidak membuat tier otomatis.
 */
class StoreShmLoanProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Otorisasi route dilakukan middleware `permission:shm.product.manage`.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:30', Rule::unique('shm_loan_products', 'code')],
            'name' => ['required', 'string', 'max:120'],
            'category' => ['required', Rule::in([
                ShmLoanProduct::CATEGORY_PERUMAHAN,
                ShmLoanProduct::CATEGORY_PERKAMPUNGAN,
                ShmLoanProduct::CATEGORY_RO,
            ])],
            'description' => ['nullable', 'string', 'max:1000'],
            'eligibility_summary' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
