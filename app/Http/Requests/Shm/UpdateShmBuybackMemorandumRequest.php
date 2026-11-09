<?php

namespace App\Http\Requests\Shm;

use App\Models\ShmMemorandum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validasi pembaruan berkas Memorandum Buyback (Ticket 4).
 *
 * Rules:
 * - Data sumber pengajuan (shm_application_id) tidak diizinkan diubah.
 * - Tipe memorandum terkunci pada 'buyback'.
 * - Hanya memperbarui status memorandum (draft/submitted) dan catatan tambahan.
 */
class UpdateShmBuybackMemorandumRequest extends FormRequest
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
}
