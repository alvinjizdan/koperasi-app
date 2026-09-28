<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Ticket 15 — request reversal transaksi simpanan (aturan A.7/A.8).
 */
class ReverseSavingsTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Alasan reversal wajib diisi.',
            'reason.min' => 'Alasan reversal minimal 5 karakter.',
        ];
    }
}
