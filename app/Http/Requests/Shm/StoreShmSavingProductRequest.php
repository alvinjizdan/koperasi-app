<?php

namespace App\Http\Requests\Shm;

use App\Models\Shm\ShmSavingProduct;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreShmSavingProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:50', 'unique:shm_saving_products,code'],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', Rule::in(array_keys(ShmSavingProduct::CATEGORIES))],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.unique' => 'Kode produk simpanan SHM sudah digunakan.',
            'category.in' => 'Kategori produk tidak valid. Gunakan: principal (Simpanan Pokok), mandatory (Simpanan Wajib), atau voluntary (Simpanan Sukarela).',
        ];
    }
}
