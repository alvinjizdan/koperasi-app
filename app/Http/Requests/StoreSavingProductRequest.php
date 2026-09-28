<?php

namespace App\Http\Requests;

use App\Models\SavingProduct;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSavingProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:50', 'unique:saving_products,code'],
            'name' => ['required', 'string', 'max:255'],
            // Ticket 20: kategori produk divalidasi terhadap daftar resmi model.
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
