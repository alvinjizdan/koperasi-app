<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CloseCashierSessionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'physical_closing_balance' => ['required', 'numeric', 'min:0', 'max:9999999999999.99'],
            'closing_notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'physical_closing_balance.required' => 'Saldo fisik hasil penghitungan wajib diisi.',
            'physical_closing_balance.min' => 'Saldo fisik tidak boleh negatif.',
        ];
    }
}
