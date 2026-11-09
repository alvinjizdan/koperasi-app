<?php

namespace App\Http\Requests\Shm;

use App\Models\ShmBuybackDocument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validasi upload dokumen legalitas Buyback (Ticket 7).
 *
 * Rules:
 * - document_type wajib salah satu dari: shm, akta_buyback, kuasa_awal, ppjb, pengosongan.
 * - file wajib berformat PDF (mimes:pdf).
 * - ukuran maksimal 10 MB (10240 KB).
 */
class UploadShmBuybackDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('shm.document.update')
            || $this->user()?->can('shm.memorandum.update')
            || false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'document_type' => [
                'required',
                'string',
                Rule::in(ShmBuybackDocument::REQUIRED_TYPES),
            ],
            'file' => [
                'required',
                'file',
                'mimes:pdf',
                'max:10240',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'document_type.required' => 'Jenis dokumen legalitas wajib dipilih.',
            'document_type.in' => 'Jenis dokumen legalitas tidak valid.',
            'file.required' => 'Berkas PDF dokumen wajib diunggah.',
            'file.file' => 'Berkas yang diunggah tidak valid.',
            'file.mimes' => 'Dokumen legalitas harus berupa berkas PDF.',
            'file.max' => 'Ukuran berkas PDF maksimal 10 MB.',
        ];
    }

    public function documentTypeValue(): string
    {
        return (string) $this->validated('document_type');
    }
}
