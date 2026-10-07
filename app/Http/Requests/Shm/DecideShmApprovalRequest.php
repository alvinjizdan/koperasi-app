<?php

namespace App\Http\Requests\Shm;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validasi keputusan approval atau penolakan.
 *
 * Endpoint ini terpisah untuk approve dan reject sehingga arah keputusan
 * tidak pernah berasal dari field yang dikirim klien. decided_by, decided_at,
 * dan status pengajuan semuanya diisi server.
 *
 * Batas kewenangan approval masih PENDING BUSINESS DECISION; saat ini tidak
 * ada pagu nominal dan tidak ada daftar dokumen wajib yang diperiksa.
 */
class DecideShmApprovalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('shm.approval.decide') ?? false;
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
