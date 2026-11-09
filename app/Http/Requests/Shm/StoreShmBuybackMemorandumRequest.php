<?php

namespace App\Http\Requests\Shm;

use App\Enums\ShmApplicationStatus;
use App\Models\ShmApplication;
use App\Models\ShmMemorandum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validasi pembuatan berkas Memorandum Buyback (Ticket 4).
 *
 * Rules:
 * - Sumber data harus berasal dari ShmApplication.
 * - Tipe pengajuan WAJIB 'buyback'.
 * - ShmApplication harus berstatus Completed (ShmApplicationStatus::Completed).
 * - Hubungan 1:1 — satu pengajuan Buyback hanya boleh memiliki satu record Memorandum Buyback.
 * - Status memorandum wajib salah satu dari: draft, submitted.
 */
class StoreShmBuybackMemorandumRequest extends FormRequest
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
            'status' => [
                'required',
                'string',
                Rule::in([ShmMemorandum::STATUS_DRAFT, ShmMemorandum::STATUS_SUBMITTED]),
            ],
            'notes' => [
                'nullable',
                'string',
                'max:5000',
            ],
        ];
    }

    /**
     * Validasi kelayakan pengajuan Buyback:
     * 1. Status pengajuan WAJIB 'completed'.
     * 2. Tipe pengajuan WAJIB 'buyback' (menolak SHGB).
     * 3. Pengajuan belum pernah dikonversi ke Memorandum tipe 'buyback'.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('shm_application_id')) {
                    return;
                }

                $applicationId = (int) $this->input('shm_application_id');
                $application = ShmApplication::find($applicationId);

                if (! $application) {
                    return;
                }

                // Cek status eligibility: HANYA pengajuan Completed yang eligible
                if ($application->status !== ShmApplicationStatus::Completed) {
                    $validator->errors()->add(
                        'shm_application_id',
                        "Pengajuan tidak eligible. Hanya pengajuan Buyback dengan status 'Selesai' (Completed) yang dapat dikonversi menjadi data Buyback.",
                    );

                    return;
                }

                // Cek tipe pengajuan: HANYA pengajuan bertipe 'buyback' yang dapat dikonversi menjadi Buyback
                if (($application->type ?? ShmApplication::TYPE_SHGB) !== ShmApplication::TYPE_BUYBACK) {
                    $validator->errors()->add(
                        'shm_application_id',
                        'Pengajuan ini bertipe SHGB. Hanya pengajuan bertipe Buyback yang dapat dikonversi menjadi data Buyback.',
                    );

                    return;
                }

                // Cek relasi 1:1 — perlindungan duplikasi
                $alreadyExists = ShmMemorandum::query()
                    ->where('shm_application_id', $applicationId)
                    ->where('type', ShmMemorandum::TYPE_BUYBACK)
                    ->exists();

                if ($alreadyExists) {
                    $validator->errors()->add(
                        'shm_application_id',
                        'Pengajuan ini sudah pernah dikonversi menjadi data Buyback (Hubungan 1:1).',
                    );
                }
            },
        ];
    }

    public function applicationIdValue(): int
    {
        return (int) $this->validated('shm_application_id');
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
