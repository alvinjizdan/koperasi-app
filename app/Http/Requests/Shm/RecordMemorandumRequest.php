<?php

namespace App\Http\Requests\Shm;

use App\Models\ShmMemorandum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validasi pencatatan memorandum pengajuan.
 *
 * TIDAK ada field nominal, margin, bunga, atau perhitungan keuangan apa pun
 * karena seluruh aturan finansial SHM masih PENDING BUSINESS DECISION.
 *
 * `type` bersifat opsional pada endpoint pengajuan lama (PUT
 * /pengajuan/{application}/memorandum) supaya perilaku Ticket 44 tidak berubah
 * untuk klien yang belum mengirim kategori. Kategori WAJIB pada modul
 * Memorandum (Ticket 49) lewat UpdateShmMemorandumRequest.
 */
class RecordMemorandumRequest extends FormRequest
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
            'type' => [
                'nullable',
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

    /**
     * Kategori memorandum, atau null bila klien tidak mengirimkannya.
     */
    public function typeValue(): ?string
    {
        $type = $this->input('type');

        return $type === null || $type === '' ? null : (string) $type;
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
