<?php

namespace App\Http\Requests\Shm;

use Illuminate\Foundation\Http\FormRequest;

/**
 * T005 — Validasi request Koreksi Settlement Pinjaman SHM.
 */
class CorrectShmLoanSettlementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'penalty' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Alasan koreksi pelunasan wajib diisi.',
            'reason.min' => 'Alasan koreksi pelunasan minimal 5 karakter.',
            'reason.max' => 'Alasan koreksi pelunasan maksimal 1000 karakter.',
            'penalty.numeric' => 'Penalty harus berupa angka.',
            'penalty.min' => 'Penalty tidak boleh bernilai negatif.',
        ];
    }
}
