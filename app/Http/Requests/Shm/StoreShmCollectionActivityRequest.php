<?php

namespace App\Http\Requests\Shm;

use App\Models\ShmCollectionActivity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Ticket 93 — validasi server-side pencatatan Collection Activity.
 *
 * Yang dijaga:
 * - `activity_type` & `result` wajib salah satu nilai kontrak T91
 *   (single source of truth `ShmCollectionActivity::TYPES`/`RESULTS`);
 * - `activity_at` wajib tanggal/waktu valid — TIDAK diganti paksa dengan
 *   waktu server dan TIDAK dibatasi backdate/masa depan (contract tidak
 *   mendefinisikan kebijakan tanggal);
 * - `notes` & `next_action` opsional, teks biasa (maks 500 karakter mengikuti
 *   konvensi notes existing modul SHM) — bukan editor kaya, tanpa field
 *   PTP/escalation/payment.
 *
 * Otorisasi mutation mutlak = pemilik case, diverifikasi di
 * `ShmCollectionActivityService` (route middleware `shm.view` hanya gate
 * modul; `authorize()` di sini cukup memastikan ada user yang masuk).
 */
class StoreShmCollectionActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>|string>
     */
    public function rules(): array
    {
        return [
            'activity_type' => ['required', 'string', Rule::in(ShmCollectionActivity::TYPES)],
            'result' => ['required', 'string', Rule::in(ShmCollectionActivity::RESULTS)],
            'activity_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
            'next_action' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'activity_type.required' => 'Tipe aktivitas wajib dipilih.',
            'activity_type.in' => 'Tipe aktivitas harus salah satu nilai kontrak Collection.',
            'result.required' => 'Hasil aktivitas wajib dipilih.',
            'result.in' => 'Hasil aktivitas harus salah satu nilai kontrak Collection.',
            'activity_at.required' => 'Waktu aktivitas wajib diisi.',
            'activity_at.date' => 'Waktu aktivitas harus berupa tanggal/waktu yang valid.',
            'notes.max' => 'Catatan maksimal 500 karakter.',
            'next_action.max' => 'Tindak lanjut maksimal 500 karakter.',
        ];
    }
}
