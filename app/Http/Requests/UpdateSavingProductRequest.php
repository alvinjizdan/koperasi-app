<?php

namespace App\Http\Requests;

use App\Models\SavingProduct;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSavingProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $productId = $this->route('saving_product')->id;

        return [
            'code' => ['required', 'string', 'max:50', Rule::unique('saving_products', 'code')->ignore($productId)],
            'name' => ['required', 'string', 'max:255'],
            // Ticket 20: kategori produk divalidasi; perubahan kategori produk
            // yang sudah punya rekening tidak diblokir (kategori adalah atribut
            // produk, bukan data historis rekening/transaksi).
            'category' => ['required', 'string', Rule::in(array_keys(SavingProduct::CATEGORIES))],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'category.in' => 'Kategori produk tidak valid. Gunakan: principal (Simpanan Pokok), mandatory (Simpanan Wajib), atau voluntary (Simpanan Sukarela).',
        ];
    }
}
