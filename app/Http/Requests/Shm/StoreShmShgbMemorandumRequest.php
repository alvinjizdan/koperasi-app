<?php

namespace App\Http\Requests\Shm;

use App\Enums\ShmApplicationStatus;
use App\Models\ShmApplication;
use App\Models\ShmMemorandum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validasi pembuatan berkas Memorandum SHM/SHGB (Ticket 2).
 *
 * Rules:
 * - Sumber data harus berasal dari ShmApplication.
 * - ShmApplication harus berstatus Completed (ShmApplicationStatus::Completed).
 * - Hubungan 1:1 — satu pengajuan hanya boleh memiliki satu record Memorandum SHM/SHGB.
 * - Status memorandum wajib salah satu dari: draft, submitted.
 */
class StoreShmShgbMemorandumRequest extends FormRequest
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

    /**
     * Tambahkan validasi kelayakan pengajuan:
     * 1. Status pengajuan WAJIB 'completed'.
     * 2. Pengajuan belum pernah dikonversi ke Memorandum tipe 'shm_shgb'.
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

                // Cek eligibility: HANYA pengajuan Completed yang eligible
                if ($application->status !== ShmApplicationStatus::Completed) {
                    $validator->errors()->add(
                        'shm_application_id',
                        "Pengajuan tidak eligible. Hanya pengajuan dengan status 'Selesai' (Completed) yang dapat dikonversi menjadi data SHM/SHGB.",
                    );

                    return;
                }

                // Cek tipe pengajuan: HANYA pengajuan bertipe 'shgb' yang dapat dikonversi menjadi SHM/SHGB
                if (($application->type ?? ShmApplication::TYPE_SHGB) !== ShmApplication::TYPE_SHGB) {
                    $validator->errors()->add(
                        'shm_application_id',
                        'Pengajuan ini bertipe Buyback. Hanya pengajuan bertipe SHGB yang dapat dikonversi menjadi data SHM/SHGB.',
                    );

                    return;
                }

                // Cek relasi 1:1 — perlindungan duplikasi
                $alreadyExists = ShmMemorandum::query()
                    ->where('shm_application_id', $applicationId)
                    ->where('type', ShmMemorandum::TYPE_SHM_SHGB)
                    ->exists();

                if ($alreadyExists) {
                    $validator->errors()->add(
                        'shm_application_id',
                        'Pengajuan ini sudah pernah dikonversi menjadi data SHM/SHGB (Hubungan 1:1).',
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
