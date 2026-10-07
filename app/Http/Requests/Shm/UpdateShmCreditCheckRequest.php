<?php

namespace App\Http\Requests\Shm;

use App\Enums\ShmCreditCheckOutcome;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validasi pembaruan hasil sementara credit checking.
 *
 * Outcome boleh dikosongkan selama pengerjaan masih berjalan; hasil final baru
 * wajib pada saat penyelesaian (lihat CompleteShmCreditCheckRequest).
 *
 * Status workflow dan pelakunya TIDAK diterima dari klien: status hanya berubah
 * melalui ShmWorkflowService, dan aktor diambil dari pengguna yang login.
 *
 * Tidak ada skor, ambang angka, atau aturan kelayakan yang divalidasi di sini.
 */
class UpdateShmCreditCheckRequest extends FormRequest
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
                'nullable',
                'string',
                Rule::in(ShmCreditCheckOutcome::values()),
            ],
            'result' => [
                'nullable',
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
            'outcome.in' => 'Hasil credit checking hanya boleh PASS, REVIEW, atau FAIL.',
            'result.max' => 'Ringkasan hasil tidak boleh melebihi 2000 karakter.',
            'notes.max' => 'Catatan tidak boleh melebihi 2000 karakter.',
        ];
    }

    public function outcomeValue(): ?ShmCreditCheckOutcome
    {
        $outcome = $this->input('outcome');

        return $outcome === null || $outcome === ''
            ? null
            : ShmCreditCheckOutcome::tryFrom((string) $outcome);
    }

    public function resultValue(): ?string
    {
        $result = $this->input('result');

        return $result === null ? null : (string) $result;
    }

    public function notesValue(): ?string
    {
        $notes = $this->input('notes');

        return $notes === null ? null : (string) $notes;
    }
}
