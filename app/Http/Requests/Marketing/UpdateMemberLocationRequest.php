<?php

namespace App\Http\Requests\Marketing;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Perubahan titik lokasi anggota/nasabah.
 *
 * Koordinat boleh kosong (lokasi belum diketahui). Bila diisi, keduanya harus
 * terisi berpasangan: latitude tanpa longitude atau sebaliknya ditolak agar tidak
 * ada titik lokasi yang tidak lengkap.
 *
 * Berbeda dengan alamat, titik lokasi tidak menghapuskan atau mengganti
 * informasi alamat yang sudah tersimpan.
 */
class UpdateMemberLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ];
    }

    /**
     * Latitude dan longitude harus diisi berpasangan.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $latitude = $this->input('latitude');
                $longitude = $this->input('longitude');

                $hasLatitude = $latitude !== null && $latitude !== '';
                $hasLongitude = $longitude !== null && $longitude !== '';

                if ($hasLatitude !== $hasLongitude) {
                    $validator->errors()->add('longitude', 'Latitude dan longitude harus diisi bersama atau dikosongkan bersama.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'latitude.between' => 'Koordinat lintang di luar rentang yang valid.',
            'longitude.between' => 'Koordinat bujur di luar rentang yang valid.',
        ];
    }
}
