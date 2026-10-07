<?php

namespace App\Http\Requests\Marketing;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Check-in kunjungan Marketing.
 *
 * Yang SENGAJA tidak ada di aturan ini:
 * - marketing_id: selalu berasal dari pengguna yang login, tidak pernah dari
 *   input. Marketing tidak dapat membuat aktivitas atas nama Marketing lain.
 * - checked_in_at: selalu dari waktu sistem, tidak pernah dari input.
 *
 * Koordinat wajib diisi karena posisi check-in hanya boleh berasal dari lokasi
 * yang benar-benar diberikan perangkat. Bila browser tidak memberikan izin,
 * frontend tidak boleh mengirim request ini sama sekali.
 */
class StoreMarketingActivityRequest extends FormRequest
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
            'member_id' => ['required', 'integer', 'exists:members,id'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'result' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'member_id.required' => 'Anggota tujuan kunjungan wajib dipilih.',
            'member_id.exists' => 'Anggota tujuan kunjungan tidak ditemukan.',
            'latitude.required' => 'Lokasi check-in tidak ditemukan. Izinkan akses lokasi pada browser lalu coba lagi.',
            'longitude.required' => 'Lokasi check-in tidak ditemukan. Izinkan akses lokasi pada browser lalu coba lagi.',
            'latitude.between' => 'Koordinat lintang di luar rentang yang valid.',
            'longitude.between' => 'Koordinat bujur di luar rentang yang valid.',
            'result.max' => 'Hasil kunjungan terlalu panjang.',
            'notes.max' => 'Catatan kunjungan terlalu panjang.',
        ];
    }
}
