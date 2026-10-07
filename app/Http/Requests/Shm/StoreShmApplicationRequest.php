<?php

namespace App\Http\Requests\Shm;

use App\Models\MarketingActivity;
use App\Models\ShmCustomer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validasi pembuatan pengajuan SHM.
 *
 * Nasabah pengajuan adalah Nasabah SHM (`shm_customers`), BUKAN anggota
 * koperasi. Pengajuan selalu memilih nasabah yang sudah terdaftar, atau
 * membuat nasabah baru langsung dari flow ini.
 *
 * Yang SENGAJA tidak divalidasi karena belum ada keputusan KSP:
 * - minimal luas tanah;
 * - maksimal atau minimal nominal;
 * - batas usia sertifikat;
 * - jenis sertifikat tertentu yang wajib;
 * - batas nominal untuk approval.
 *
 * marketing_id, status, dan seluruh timestamp workflow tidak pernah
 * divalidasi dari sini karena tidak boleh diterima dari klien sama sekali.
 */
class StoreShmApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('shm.create') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'shm_customer_id' => [
                'nullable',
                'integer',
                Rule::exists('shm_customers', 'id'),
            ],
            // Nasabah baru dari flow pengajuan. Dibuat sebagai ShmCustomer,
            // tidak pernah sebagai anggota koperasi.
            'new_customer' => [
                'nullable',
                'array',
            ],
            'new_customer.name' => [
                'nullable',
                'string',
                'max:150',
            ],
            'new_customer.nik' => [
                'nullable',
                'string',
                'max:50',
            ],
            'new_customer.phone' => [
                'nullable',
                'string',
                'max:30',
                'regex:/^[0-9+\-\s()]+$/',
            ],
            'notes' => [
                'nullable',
                'string',
                'max:2000',
            ],
            'marketing_activity_id' => [
                'nullable',
                'integer',
                // Exist saja tidak cukup: aktivitas milik Marketing lain juga
                // harus ditolak. Ownership dicek di after() di bawah.
                Rule::exists('marketing_activities', 'id'),
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
            'new_customer.phone.regex' => 'Nomor telepon hanya boleh berisi angka dan tanda +, -, spasi, atau tanda kurung.',
            'marketing_activity_id.exists' => 'Aktivitas Marketing tidak dapat dipilih untuk pengajuan ini.',
        ];
    }

    /**
     * Setelah validasi dasar: pengajuan harus menunjuk ke Nasabah SHM.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->input('__forbidden_fields') !== null) {
                    $validator->errors()->add(
                        'application_number',
                        'Kolom ini tidak dapat dikirim dari klien.',
                    );
                }

                $this->validateCustomerChoice($validator);
                $this->validateActivityOwnership($validator);
            },
        ];
    }

    /**
     * Data yang diteruskan ke service dalam bentuk ternormalisasi.
     *
     * @return array{shm_customer_id: int|null, new_customer: array{name: string, nik: string|null, phone: string|null}|null, notes: string|null, marketing_activity_id: int|null}
     */
    public function payload(): array
    {
        $newCustomerName = trim((string) $this->input('new_customer.name', ''));

        return [
            'shm_customer_id' => $this->input('shm_customer_id') !== null
                ? (int) $this->input('shm_customer_id')
                : null,
            'new_customer' => $newCustomerName === ''
                ? null
                : [
                    'name' => $newCustomerName,
                    'nik' => $this->input('new_customer.nik'),
                    'phone' => $this->input('new_customer.phone'),
                ],
            'notes' => $this->input('notes'),
            'marketing_activity_id' => $this->input('marketing_activity_id') !== null
                ? (int) $this->input('marketing_activity_id')
                : null,
        ];
    }

    /**
     * Pengajuan harus menunjuk tepat satu nasabah.
     */
    private function validateCustomerChoice(Validator $validator): void
    {
        $hasExisting = $this->filled('shm_customer_id');
        $hasNew = trim((string) $this->input('new_customer.name', '')) !== '';

        if ($hasExisting && $hasNew) {
            $validator->errors()->add(
                'shm_customer_id',
                'Pilih nasabah yang sudah terdaftar atau isi nasabah baru, tidak keduanya.',
            );

            return;
        }

        if (! $hasExisting && ! $hasNew) {
            $validator->errors()->add(
                'shm_customer_id',
                'Pengajuan harus menunjuk ke Nasabah SHM yang sudah terdaftar atau mengisi nasabah baru.',
            );

            return;
        }

        if ($hasNew && ! Gate::allows('shm.customer.create')) {
            $validator->errors()->add(
                'new_customer.name',
                'Anda tidak memiliki hak untuk menambah nasabah SHM baru.',
            );

            return;
        }

        if (! $hasExisting) {
            return;
        }

        // Nasabah nonaktif tidak boleh dipakai untuk pengajuan baru. Nasabah
        // dinonaktifkan berarti tidak lagi menerima pengajuan, bukan dihapus.
        $customer = ShmCustomer::query()->find((int) $this->input('shm_customer_id'));

        if ($customer !== null && ! $customer->is_active) {
            $validator->errors()->add(
                'shm_customer_id',
                'Nasabah SHM tersebut sedang nonaktif dan tidak dapat dipakai untuk pengajuan baru.',
            );
        }
    }

    /**
     * Aktivitas Marketing hanya boleh yang memang dimiliki pengguna.
     *
     * Holder shm.view_all (admin/superadmin) boleh memasang aktivitas mana saja
     * karena mereka sudah berwenang melihat seluruh pengajuan. Marketing biasa
     * hanya boleh memasang aktivitas miliknya sendiri.
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

    /**
     * Keamanan tambahan: server menolak request yang mencoba mengirim kolom
     * yang harusnya berasal dari server.
     */
    protected function prepareForValidation(): void
    {
        $forbidden = array_values(array_filter(
            ['application_number', 'status', 'marketing_id', 'submitted_at', 'approved_at'],
            fn (string $field): bool => $this->exists($field) || $this->filled($field),
        ));

        if ($forbidden !== []) {
            $this->merge(['__forbidden_fields' => implode(',', $forbidden)]);
        }
    }
}
