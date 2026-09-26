<?php

namespace App\Http\Requests;

use App\Models\ReconciliationFinding;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFindingStatusRequest extends FormRequest
{
    /**
     * Menindaklanjuti temuan memerlukan permission rekonsiliasi.resolve,
     * terpisah dari melihat hasil dan menjalankan proses.
     */
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->can('rekonsiliasi.resolve');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'to_status' => ['required', 'string', Rule::in([
                ReconciliationFinding::STATUS_OPEN,
                ReconciliationFinding::STATUS_UNDER_REVIEW,
                ReconciliationFinding::STATUS_RESOLVED,
                ReconciliationFinding::STATUS_ACCEPTED_WITH_REASON,
            ])],
            'note' => ['required', 'string', 'min:5', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'to_status.required' => 'Status tindak lanjut wajib dipilih.',
            'to_status.in' => 'Status tindak lanjut tidak valid.',
            'note.required' => 'Catatan tindak lanjut wajib diisi.',
            'note.min' => 'Catatan tindak lanjut minimal 5 karakter.',
            'note.max' => 'Catatan tindak lanjut maksimal 2000 karakter.',
        ];
    }

    /**
     * Persiapan data validasi: rapikan catatan.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'note' => trim((string) $this->input('note')),
        ]);
    }
}
