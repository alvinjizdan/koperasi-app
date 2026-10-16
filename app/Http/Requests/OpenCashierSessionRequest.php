<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class OpenCashierSessionRequest extends FormRequest
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
            'operational_date' => ['required', 'date', 'before_or_equal:today'],
            'opening_balance' => ['required', 'numeric', 'min:0', 'max:9999999999999.99'],
            'opening_notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'operational_date.before_or_equal' => 'Tanggal operasional tidak boleh melebihi tanggal hari ini.',
            'opening_balance.min' => 'Saldo awal kas tidak boleh negatif.',
            'opening_balance.required' => 'Saldo awal kas wajib diisi.',
        ];
    }
}
