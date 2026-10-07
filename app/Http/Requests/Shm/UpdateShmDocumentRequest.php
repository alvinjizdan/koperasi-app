<?php

namespace App\Http\Requests\Shm;

use App\Models\ShmDocument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validasi update item kelengkapan dokumen dari halaman detail Admin.
 *
 * Tidak ada daftar dokumen legal yang dikunci, dan tidak ada upload berkas.
 */
class UpdateShmDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('shm.document.update') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'document_name' => [
                'required',
                'string',
                'max:150',
            ],
            'status' => [
                'required',
                'string',
                Rule::in(ShmDocument::statuses()),
            ],
            'notes' => [
                'nullable',
                'string',
                'max:2000',
            ],
            'document_id' => [
                'nullable',
                'integer',
                'exists:shm_documents,id',
            ],
        ];
    }

    public function documentIdValue(): ?int
    {
        $id = $this->input('document_id');

        return $id === null || $id === '' ? null : (int) $id;
    }

    public function nameValue(): string
    {
        return (string) $this->input('document_name');
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
