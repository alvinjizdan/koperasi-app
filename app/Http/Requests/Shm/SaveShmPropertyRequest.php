<?php

namespace App\Http\Requests\Shm;

use App\Models\ShmProperty;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validasi objek SHM/SHGB.
 *
 * certificate_type hanya menerima SHM atau SHGB. Itu pembeda jenis sertifikat,
 * bukan aturan kelayakan.
 *
 * Koordinat hanya divalidasi rentangnya kalau diisi. Koordinat tidak pernah
 * dipakai sebagai radius validasi dan tidak dianggap bukti kepemilikan.
 */
class SaveShmPropertyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('shm.property.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'certificate_type' => [
                'required',
                'string',
                Rule::in(ShmProperty::certificateTypes()),
            ],
            'certificate_number' => [
                'nullable',
                'string',
                'max:100',
            ],
            'owner_name' => [
                'nullable',
                'string',
                'max:150',
            ],
            'address' => [
                'required',
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
            'notes' => [
                'nullable',
                'string',
                'max:2000',
            ],
        ];
    }

    /**
     * @return array{certificate_type: string, certificate_number: string|null, owner_name: string|null, address: string, latitude: string|null, longitude: string|null, notes: string|null}
     */
    public function payload(): array
    {
        return [
            'certificate_type' => (string) $this->input('certificate_type'),
            'certificate_number' => $this->input('certificate_number'),
            'owner_name' => $this->input('owner_name'),
            'address' => (string) $this->input('address'),
            'latitude' => $this->input('latitude'),
            'longitude' => $this->input('longitude'),
            'notes' => $this->input('notes'),
        ];
    }

    /**
     * Latitude dan longitude harus diisi bersamaan atau dikosongkan bersamaan.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $hasLatitude = $this->filled('latitude');
                $hasLongitude = $this->filled('longitude');

                if ($hasLatitude !== $hasLongitude) {
                    $validator->errors()->add(
                        'latitude',
                        'Llatitude dan longitude harus diisi bersamaan atau dikosongkan.',
                    );
                }
            },
        ];
    }
}
