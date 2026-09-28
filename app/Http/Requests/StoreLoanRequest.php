<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreLoanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'member_id' => ['required', 'integer', 'exists:members,id'],
            'amount' => ['required', 'numeric', 'min:10000'],
            'tenor_months' => [
                'required',
                'integer',
                'min:'.(int) config('business-rules.loan.min_tenor_months', 1),
                'max:'.(int) config('business-rules.loan.max_tenor_months', 120),
            ],
            'submission_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'member_id.exists' => 'Anggota tidak ditemukan.',
            'amount.min' => 'Nominal pinjaman minimal Rp 10.000.',
            'tenor_months.min' => 'Tenor minimal 1 bulan.',
        ];
    }
}
