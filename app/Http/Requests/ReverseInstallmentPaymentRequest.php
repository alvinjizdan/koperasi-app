<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Ticket 21 — request reversal pembayaran angsuran.
 *
 * Mengikuti pola ReverseSavingsTransactionRequest (Ticket 15): alasan wajib
 * untuk jejak audit; otorisasi via permission route (angsuran.reverse).
 */
class ReverseInstallmentPaymentRequest extends FormRequest
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
            'reason.max' => 'Alasan reversal maksimal 500 karakter.',
        ];
    }
}
