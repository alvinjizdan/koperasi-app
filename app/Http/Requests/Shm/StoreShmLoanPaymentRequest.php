<?php

namespace App\Http\Requests\Shm;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Ticket 86 — validasi server-side pencatatan Pembayaran Pinjaman SHM.
 *
 * Yang dijaga:
 * - `loan_id` WAJIB merujuk baris `shm_loans` yang ada (pembayaran tidak
 *   pernah dibuat terhadap ShmLoanApplication maupun pinjaman koperasi);
 * - `payment_date` wajib tanggal valid — TIDAK diganti paksa dengan tanggal
 *   server (backdate/backdate-limit masih PENDING BUSINESS CONFIRMATION,
 *   sehingga batas waktu tidak dikarang di sini);
 * - `amount` wajib numerik dan lebih besar dari 0. TIDAK divalidasi terhadap
 *   angsuran/sisa pokok: overpayment disimpan apa adanya (kontrak T87);
 * - `payment_channel` hanya string pendek tanpa enum: daftar kanal resmi
 *   masih PENDING BUSINESS CONFIRMATION (T84) sehingga tidak ada nilai resmi
 *   yang boleh dipaksakan;
 * - `idempotency_key` mengikuti pola existing koperasi (nullable, ≤100 char);
 *   penanganan duplikat dilakukan di service (retry mengembalikan baris yang
 *   sama), sehingga TIDAK memakai rule `unique` yang akan menolak replay.
 *
 * Otorisasi route (permission `shm.payment.create`) dan scope kepemilikan
 * pinjaman ditegakkan di controller/service — bukan hanya di sini.
 */
class StoreShmLoanPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Otorisasi route dilakukan middleware `permission:shm.payment.create`;
        // tidak ada permission baru di luar yang terdaftar di config.
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Batas teknis yang SAMA dengan batas moneter existing pada form
        // pengajuan (bukan batas bisnis baru) — mencegah overflow kolom
        // decimal(18,2).
        $maxAmount = 1_000_000_000_000;

        return [
            'loan_id' => ['required', 'integer', Rule::exists('shm_loans', 'id')],
            'payment_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:'.$maxAmount],
            'payment_channel' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'loan_id.required' => 'Pinjaman SHM wajib dipilih.',
            'loan_id.exists' => 'Pinjaman SHM tidak ditemukan.',
            'payment_date.required' => 'Tanggal pembayaran wajib diisi.',
            'payment_date.date' => 'Tanggal pembayaran tidak valid.',
            'amount.required' => 'Nominal pembayaran wajib diisi.',
            'amount.numeric' => 'Nominal pembayaran harus berupa angka.',
            'amount.min' => 'Nominal pembayaran harus lebih besar dari 0.',
            'payment_channel.max' => 'Kanal pembayaran terlalu panjang.',
            'idempotency_key.max' => 'Kunci idempotensi terlalu panjang.',
        ];
    }
}
