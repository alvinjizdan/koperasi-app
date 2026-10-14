<?php

namespace App\Http\Requests\Shm;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validasi pembuatan Nasabah SHM.
 *
 * Yang SENGAJA tidak divalidasi karena belum ada keputusan KSP dan tidak
 * diminta ticket ini:
 * - umur minimum;
 * - penghasilan minimum;
 * - pekerjaan, pasangan, NPWP, status perkawinan;
 * - format NIK resmi tertentu (kolom NIK masih nullable dan bebas).
 *
 * customer_number dan is_active tidak pernah diterima dari klien:
 * nomor dibuat server, status awal selalu aktif.
 */
class StoreShmCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('shm.customer.create') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'member_id' => [
                'required',
                'integer',
                'exists:members,id',
            ],
            'name' => [
                'required',
                'string',
                'max:150',
            ],
            'nik' => [
                'nullable',
                'string',
                'max:50',
            ],
            'phone' => [
                'nullable',
                'string',
                'max:30',
                // Hanya menolak karakter yang jelas bukan bagian nomor telepon.
                // Format resmi nasional belum ditetapkan KSP.
                'regex:/^[0-9+\-\s()]+$/',
            ],
            'email' => [
                'nullable',
                'email',
                'max:150',
            ],
            'address' => [
                'nullable',
                'string',
                'max:1000',
            ],
            'latitude' => [
                'nullable',
                'numeric',
                'between:-90,90',
            ],
            'longitude' => [
                'nullable',
                'numeric',
                'between:-180,180',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'member_id.required' => 'Anggota koperasi sumber wajib dipilih.',
            'member_id.exists' => 'Anggota koperasi yang dipilih tidak ditemukan.',
            'name.required' => 'Nama nasabah wajib diisi.',
            'name.max' => 'Nama nasabah tidak boleh melebihi 150 karakter.',
            'phone.regex' => 'Nomor telepon hanya boleh berisi angka dan tanda +, -, spasi, atau tanda kurung.',
            'email.email' => 'Format email tidak valid.',
            'latitude.between' => 'Latitude harus berada di antara -90 dan 90.',
            'longitude.between' => 'Longitude harus berada di antara -180 dan 180.',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->input('__forbidden_fields') !== null) {
                    $validator->errors()->add(
                        'customer_number',
                        'Kolom ini tidak dapat dikirim dari klien.',
                    );
                }

                $hasLatitude = $this->filled('latitude');
                $hasLongitude = $this->filled('longitude');

                if ($hasLatitude !== $hasLongitude) {
                    $validator->errors()->add(
                        'latitude',
                        'Latitude dan longitude harus diisi bersamaan atau dikosongkan.',
                    );
                }
            },
        ];
    }

    /**
     * Data yang diteruskan ke service dalam bentuk ternormalisasi.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'member_id' => (int) $this->input('member_id'),
            'name' => (string) $this->input('name'),
            'nik' => $this->input('nik'),
            'phone' => $this->input('phone'),
            'email' => $this->input('email'),
            'address' => $this->input('address'),
            'latitude' => $this->input('latitude'),
            'longitude' => $this->input('longitude'),
        ];
    }

    /**
     * Server menolak request yang mencoba mengirim kolom milik server.
     * Nomor nasabah selalu dibuat server, bukan dari input klien.
     */
    protected function prepareForValidation(): void
    {
        $forbidden = array_values(array_filter(
            ['customer_number', 'is_active'],
            fn (string $field): bool => $this->exists($field) || $this->filled($field),
        ));

        if ($forbidden !== []) {
            $this->merge(['__forbidden_fields' => implode(',', $forbidden)]);
        }
    }
}
