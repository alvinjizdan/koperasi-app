<?php

namespace App\Http\Requests\Shm;

use App\Models\ShmLoanProduct;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Ticket 98-FIX — edit master produk Pinjaman SHM.
 *
 * Aturan sama dengan StoreShmLoanProductRequest dengan pengecualian kode
 * UNIQUE mengabaikan produk itu sendiri. Edit hanya menyentuh baris master:
 * histori pengajuan/pinjaman TIDAK berubah dan rate pinjaman lama tetap
 * snapshot di `shm_loans.rate_percent` (resolusi T82 saat pencairan).
 */
class UpdateShmLoanProductRequest extends FormRequest
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
            'code' => [
                'required',
                'string',
                'max:30',
                Rule::unique('shm_loan_products', 'code')->ignore($this->route('product')),
            ],
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
