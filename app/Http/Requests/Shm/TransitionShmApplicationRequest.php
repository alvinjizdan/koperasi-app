<?php

namespace App\Http\Requests\Shm;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validasi permintaan perpindahan tahap.
 *
 * Kolom tujuan transisi TIDAK dikirim dari klien. Endpoint dipisah per
 * tujuan (submit, approve, reject, disburse, selesai, batal) sehingga arah
 * transisi ditentukan server dan tidak dapat dipalsukan.
 *
 * Perpindahan status hanya dijalankan oleh ShmWorkflowService.
 */
class TransitionShmApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'notes' => [
                'nullable',
                'string',
                'max:2000',
            ],
        ];
    }

    public function notesValue(): ?string
    {
        $notes = $this->input('notes');

        return $notes === null ? null : (string) $notes;
    }
}
