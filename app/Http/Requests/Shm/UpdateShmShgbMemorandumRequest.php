<?php

namespace App\Http\Requests\Shm;

use App\Models\ShmMemorandum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validasi pembaruan berkas Memorandum SHM/SHGB (Ticket 2).
 *
 * Rules:
 * - Data sumber pengajuan (shm_application_id) tidak diizinkan diubah.
 * - Hanya memperbarui status memorandum (draft/submitted) dan catatan tambahan.
 */
class UpdateShmShgbMemorandumRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('shm.memorandum.update') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => [
                'required',
                'string',
                Rule::in(ShmMemorandum::statuses()),
            ],
            'notes' => [
                'nullable',
                'string',
                'max:5000',
            ],
            'shgb_start_date' => [
                'nullable',
                'date',
            ],
            'shgb_end_date' => [
                'nullable',
                'date',
                'after_or_equal:shgb_start_date',
            ],
        ];
    }

    public function statusValue(): string
    {
        return (string) $this->validated('status');
    }

    public function notesValue(): ?string
    {
        $notes = $this->validated('notes');

        return $notes !== null ? (string) $notes : null;
    }

    public function shgbStartDateValue(): ?string
    {
        $val = $this->validated('shgb_start_date');

        return $val !== null ? (string) $val : null;
    }

    public function shgbEndDateValue(): ?string
    {
        $val = $this->validated('shgb_end_date');

        return $val !== null ? (string) $val : null;
    }
}
