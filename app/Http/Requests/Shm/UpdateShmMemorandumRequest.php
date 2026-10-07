<?php

namespace App\Http\Requests\Shm;

use App\Models\ShmMemorandum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validasi modul Memorandum (Ticket 49).
 *
 * Pada modul ini kategori WAJIB: memorandum yang dibuat lewat menu
 * Memorandum harus selalu teridentifikasi sebagai SHM/SHGB atau Buyback.
 * Status tetap status workflow sederhana (draft/submitted) yang sudah ada
 * sejak Ticket 44; tidak ada status baru yang mengandung konsekuensi
 * legal/finansial.
 */
class UpdateShmMemorandumRequest extends FormRequest
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
            'shm_application_id' => [
                'required',
                'integer',
                Rule::exists('shm_applications', 'id'),
            ],
            'type' => [
                'required',
                'string',
                Rule::in(ShmMemorandum::types()),
            ],
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

    public function applicationIdValue(): int
    {
        return (int) $this->input('shm_application_id');
    }

    public function typeValue(): string
    {
        return (string) $this->input('type');
    }

    public function statusValue(): string
    {
        return (string) $this->input('status');
    }

    public function notesValue(): ?string
    {
        $notes = $this->input('notes');

        return $notes === null ? null : (string) $notes;
    }
}
