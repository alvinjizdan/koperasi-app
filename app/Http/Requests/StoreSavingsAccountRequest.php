<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSavingsAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'member_id' => ['required', 'integer', 'exists:members,id'],
            'saving_product_id' => ['required', 'integer', 'exists:saving_products,id'],
            'opened_at' => ['required', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'member_id.exists' => 'Anggota tidak ditemukan.',
            'saving_product_id.exists' => 'Produk simpanan tidak ditemukan atau tidak valid.',
        ];
    }
}
