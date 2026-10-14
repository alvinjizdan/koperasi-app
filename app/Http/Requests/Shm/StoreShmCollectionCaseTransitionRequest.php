<?php

namespace App\Http\Requests\Shm;

use App\Models\ShmCollectionCase;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Ticket 94 — validasi transisi workflow Collection Case.
 *
 * Satu-satunya field yang diterima = `status` (target transisi), dibatasi ke
 * status kontrak T91 yang ada pada `ShmCollectionCase::ALLOWED_TRANSITIONS`
 * (source of truth T92; contract T91 menyerahkan transition table ke ticket
 * implementasi). String arbitrer ditolak; validasi graph transisi (status
 * saat ini → target) tetap mutlak di `ShmCollectionCaseService::transition()`.
 *
 * YANG SENGAJA TIDAK DIKENALI (masuk validated() hanya `status`):
 * actor/owner/loan diambil dari session & case di server; `resolved_at`
 * dikontrol service saat memasuki RESOLVED; detail PTP (amount/tanggal/
 * fulfillment) adalah scope ticket PTP berikutnya. Frontend TIDAK pernah
 * memasok identitas maupun timestamp penyelesaian.
 */
class StoreShmCollectionCaseTransitionRequest extends FormRequest
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
            'status' => ['required', 'string', Rule::in(array_keys(ShmCollectionCase::ALLOWED_TRANSITIONS))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.required' => 'Status Collection tujuan wajib dipilih.',
            'status.in' => 'Status Collection tujuan bukan merupakan status lifecycle Collection yang dikenal.',
        ];
    }
}
