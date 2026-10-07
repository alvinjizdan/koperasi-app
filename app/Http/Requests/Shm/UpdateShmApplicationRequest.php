<?php

namespace App\Http\Requests\Shm;

use App\Models\MarketingActivity;
use App\Models\ShmApplication;
use App\Models\ShmCustomer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validasi perubahan data pengajuan SHM yang masih draft.
 *
 * Nasabah pengajuan adalah Nasabah SHM (`shm_customers`), bukan anggota
 * koperasi. Aturan transisi tidak di sini: perpindahan status hanya boleh
 * lewat ShmWorkflowService.
 */
class UpdateShmApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('shm.update') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'marketing_activity_id' => [
                'nullable',
                'integer',
                Rule::exists('marketing_activities', 'id'),
            ],
            'shm_customer_id' => [
                'nullable',
                'integer',
                Rule::exists('shm_customers', 'id'),
            ],
            'notes' => [
                'nullable',
                'string',
                'max:2000',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'shm_customer_id.exists' => 'Nasabah SHM yang dipilih tidak ditemukan.',
            'marketing_activity_id.exists' => 'Aktivitas Marketing tidak dapat dipilih untuk pengajuan ini.',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $this->validateCustomerChange($validator);
                $this->validateActivityOwnership($validator);
            },
        ];
    }

    /**
     * @return array{shm_customer_id: int|null, notes: string|null, marketing_activity_id: int|null}
     */
    public function payload(): array
    {
        return [
            'shm_customer_id' => $this->input('shm_customer_id') !== null
                ? (int) $this->input('shm_customer_id')
                : null,
            'notes' => $this->input('notes'),
            'marketing_activity_id' => $this->input('marketing_activity_id') !== null
                ? (int) $this->input('marketing_activity_id')
                : null,
        ];
    }

    /**
     * Pengajuan wajib tetap menunjuk ke Nasabah SHM.
     *
     * Nasabah nonaktif hanya ditolak bila pengajuan DIPINDAHKAN ke nasabah
     * tersebut. Nasabah yang sudah terpasang sebelum dinonaktifkan tetap boleh
     * dipertahankan agar draft lama tidak terkunci.
     */
    private function validateCustomerChange(Validator $validator): void
    {
        $application = $this->route('application');
        $currentId = $application instanceof ShmApplication ? $application->shm_customer_id : null;

        $submittedId = $this->exists('shm_customer_id')
            ? ($this->input('shm_customer_id') !== null ? (int) $this->input('shm_customer_id') : null)
            : $currentId;

        if ($submittedId === null) {
            $validator->errors()->add(
                'shm_customer_id',
                'Pengajuan harus tetap menunjuk ke Nasabah SHM.',
            );

            return;
        }

        if ($submittedId === $currentId) {
            return;
        }

        $customer = ShmCustomer::query()->find($submittedId);

        if ($customer !== null && ! $customer->is_active) {
            $validator->errors()->add(
                'shm_customer_id',
                'Nasabah SHM tersebut sedang nonaktif dan tidak dapat dipakai untuk pengajuan baru.',
            );
        }
    }

    /**
     * Aktivitas Marketing hanya boleh yang milik pengguna.
     *
     * Holder shm.view_all boleh memasang aktivitas mana saja.
     */
    private function validateActivityOwnership(Validator $validator): void
    {
        $activityId = $this->input('marketing_activity_id');

        if ($activityId === null || $activityId === '') {
            return;
        }

        $user = $this->user();

        if ($user === null || Gate::allows('shm.view_all')) {
            return;
        }

        $owned = MarketingActivity::query()
            ->whereKey((int) $activityId)
            ->where('marketing_id', $user->id)
            ->exists();

        if (! $owned) {
            $validator->errors()->add(
                'marketing_activity_id',
                'Aktivitas Marketing tersebut bukan milik Anda.',
            );
        }
    }
}
