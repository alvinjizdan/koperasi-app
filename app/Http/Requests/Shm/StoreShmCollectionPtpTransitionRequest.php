<?php

namespace App\Http\Requests\Shm;

use App\Models\ShmCollectionPtp;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Ticket 97 — validasi transisi status Promise to Pay (PTP).
 *
 * Satu-satunya field yang diterima = `status` (target transisi), dibatasi
 * ke tiga status terminal (`TERMINAL_STATUSES` — target valid satu-satunya
 * dari OPEN menurut graph `ShmCollectionPtp::ALLOWED_TRANSITIONS`). Status
 * OPEN sendiri TIDAK bisa menjadi target (tidak ada reopen/aktivasi
 * ganda), dan string arbitrer ditolak; validasi graph transisi (status
 * saat ini → target) tetap mutlak di `ShmCollectionPtpService::transition()`.
 *
 * YANG SENGAJA TIDAK DIKENALI: actor (session), pemilik case induk,
 * pembayaran — TIDAK PERNAH ada keputusan FULFILLED/MISSED otomatis dari
 * nominal/tanggal pembayaran di ticket ini (payment matching = PENDING).
 * Frontend tidak pernah memasok identitas maupun alasan.
 */
class StoreShmCollectionPtpTransitionRequest extends FormRequest
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
            'status' => ['required', 'string', Rule::in(ShmCollectionPtp::TERMINAL_STATUSES)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.required' => 'Status PTP tujuan wajib dipilih.',
            'status.in' => 'Status PTP tujuan harus salah satu dari FULFILLED, MISSED, atau CANCELLED.',
        ];
    }
}
