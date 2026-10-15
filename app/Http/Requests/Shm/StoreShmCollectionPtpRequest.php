<?php

namespace App\Http\Requests\Shm;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Ticket 97 — validasi server-side pencatatan Promise to Pay (PTP).
 *
 * Yang dijaga:
 * - `promised_date` wajib tanggal valid — TIDAK dibatasi backdate/masa
 *   depan (contract T91 Rule 8 tidak mendefinisikan kebijakan tanggal);
 * - `promised_amount` wajib numerik > 0 (min 0.01 menolak nol & negatif;
 *   desimal mengikuti konvensi finansial SHM, tanpa batas atas yang
 *   dikarang — contract tidak menetapkan nominal maksimum);
 * - `notes` opsional, teks biasa maks 500 karakter (konvensi notes
 *   existing modul SHM).
 *
 * YANG SENGAJA TIDAK DIKENALI (masuk validated() hanya tiga field di
 * atas): actor/pemilik/case status/duplikat OPEN divalidasi mutlak di
 * `ShmCollectionPtpService` (status case & PTP OPEN tidak pernah bisa
 * dipalsukan lewat payload). Frontend TIDAK pernah memasok `created_by`,
 * `status`, maupun pembayaran apa pun — PTP tidak membuat pembayaran.
 *
 * Otorisasi mutation mutlak = pemilik case, diverifikasi di service (route
 * middleware `shm.view` hanya gate modul; `authorize()` cukup memastikan
 * ada user yang masuk — pola T93).
 */
class StoreShmCollectionPtpRequest extends FormRequest
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
            'promised_date' => ['required', 'date'],
            'promised_amount' => ['required', 'numeric', 'min:0.01'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'promised_date.required' => 'Tanggal janji pembayaran wajib diisi.',
            'promised_date.date' => 'Tanggal janji pembayaran harus berupa tanggal yang valid.',
            'promised_amount.required' => 'Nominal janji pembayaran wajib diisi.',
            'promised_amount.numeric' => 'Nominal janji pembayaran harus berupa angka.',
            'promised_amount.min' => 'Nominal janji pembayaran harus lebih dari 0.',
            'notes.max' => 'Catatan PTP maksimal 500 karakter.',
        ];
    }
}
