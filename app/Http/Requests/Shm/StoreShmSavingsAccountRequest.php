<?php

namespace App\Http\Requests\Shm;

use Illuminate\Foundation\Http\FormRequest;

class StoreShmSavingsAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'shm_customer_id' => ['required', 'integer', 'exists:shm_customers,id'],
            'shm_saving_product_id' => ['required', 'integer', 'exists:shm_saving_products,id'],
            'opened_at' => ['required', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'shm_customer_id.required' => 'Nasabah SHM wajib dipilih.',
            'shm_customer_id.exists' => 'Nasabah SHM tidak ditemukan.',
            'shm_saving_product_id.required' => 'Produk simpanan SHM wajib dipilih.',
            'shm_saving_product_id.exists' => 'Produk simpanan SHM tidak ditemukan atau tidak valid.',
            'opened_at.required' => 'Tanggal pembukaan rekening wajib diisi.',
        ];
    }
}
