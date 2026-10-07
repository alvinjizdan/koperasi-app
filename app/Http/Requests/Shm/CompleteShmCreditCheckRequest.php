<?php

namespace App\Http\Requests\Shm;

use App\Enums\ShmCreditCheckOutcome;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validasi penyelesaian credit checking.
 *
 * Outcome dan ringkasan hasil WAJIB diisi di sini: hasil final tidak boleh
 * kosong. Waktu penyelesaian (checked_at) dan pelakunya tidak diterima dari
 * klien — keduanya diisi server oleh ShmWorkflowService.
 *
 * Outcome hanya hasil pencatatan manual, bukan keputusan kelayakan kredit.
 */
class CompleteShmCreditCheckRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('shm.credit_checking.update') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'outcome' => [
                'required',
                'string',
                Rule::in(ShmCreditCheckOutcome::values()),
            ],
            'result' => [
                'required',
                'string',
                'max:2000',
            ],
            'notes' => [
                'nullable',
                'string',
                'max:2000',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'outcome.required' => 'Hasil credit checking wajib dipilih sebelum diselesaikan.',
            'outcome.in' => 'Hasil credit checking hanya boleh PASS, REVIEW, atau FAIL.',
            'result.required' => 'Ringkasan hasil wajib diisi sebelum credit checking diselesaikan.',
            'result.max' => 'Ringkasan hasil tidak boleh melebihi 2000 karakter.',
            'notes.max' => 'Catatan tidak boleh melebihi 2000 karakter.',
        ];
    }

    public function outcomeValue(): ShmCreditCheckOutcome
    {
        return ShmCreditCheckOutcome::from((string) $this->input('outcome'));
    }

    public function resultValue(): string
    {
        return (string) $this->input('result');
    }

    public function notesValue(): ?string
    {
        $notes = $this->input('notes');

        return $notes === null ? null : (string) $notes;
    }
}
