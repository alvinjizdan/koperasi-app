<?php

namespace App\Http\Requests\Marketing;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Check-in kunjungan Marketing (Ticket 71 — subjek dari `shm_customers`).
 *
 * Tujuan kunjungan HANYA nasabah SHM: `shm_customer_id` wajib menunjuk baris
 * yang benar-benar ada di `shm_customers`. Karena `members` dan
 * `shm_customers` adalah dua master terpisah dengan id masing-masing, id
 * anggota koperasi otomatis ditolak oleh aturan exists ini — tanpa fallback
 * dan tanpa konversi apa pun ke nasabah SHM.
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
            'shm_customer_id' => ['required', 'integer', 'exists:shm_customers,id'],
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
            'shm_customer_id.required' => 'Nasabah SHM tujuan kunjungan wajib dipilih.',
            'shm_customer_id.exists' => 'Nasabah SHM tujuan kunjungan tidak ditemukan.',
            'latitude.required' => 'Lokasi check-in tidak ditemukan. Izinkan akses lokasi pada browser lalu coba lagi.',
            'longitude.required' => 'Lokasi check-in tidak ditemukan. Izinkan akses lokasi pada browser lalu coba lagi.',
            'latitude.between' => 'Koordinat lintang di luar rentang yang valid.',
            'longitude.between' => 'Koordinat bujur di luar rentang yang valid.',
            'result.max' => 'Hasil kunjungan terlalu panjang.',
            'notes.max' => 'Catatan kunjungan terlalu panjang.',
        ];
    }
}
