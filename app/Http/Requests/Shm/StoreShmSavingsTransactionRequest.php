<?php

namespace App\Http\Requests\Shm;

use Illuminate\Foundation\Http\FormRequest;

class StoreShmSavingsTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'string', 'in:deposit,withdrawal'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'transaction_date' => ['required', 'date', 'before_or_equal:today'],
            'description' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'type.in' => 'Tipe transaksi harus deposit atau withdrawal.',
            'amount.min' => 'Nominal transaksi harus lebih dari 0.',
            'transaction_date.before_or_equal' => 'Tanggal transaksi tidak boleh melebihi tanggal hari ini.',
        ];
    }
}
