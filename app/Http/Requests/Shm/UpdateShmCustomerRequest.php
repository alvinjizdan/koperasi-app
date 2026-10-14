<?php

namespace App\Http\Requests\Shm;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validasi perubahan data Nasabah SHM.
 *
 * Nomor nasabah tidak dapat diubah dan tidak pernah diterima dari klien.
 * Status aktif/nonaktif punya endpoint tersendiri, bukan lewat form ini.
 */
class UpdateShmCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('shm.customer.update') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
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
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'name' => (string) $this->input('name'),
            'nik' => $this->input('nik'),
            'phone' => $this->input('phone'),
            'email' => $this->input('email'),
            'address' => $this->input('address'),
            'latitude' => $this->input('latitude'),
            'longitude' => $this->input('longitude'),
        ];
    }

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
