<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateMemberRequest extends FormRequest
{
    /**
     * Tentukan apakah pengguna diizinkan membuat permintaan ini.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Aturan validasi yang berlaku untuk permintaan update.
     *
     * `member_number` immutable (T102-FIX): kode anggota dibuat server saat
     * pendaftaran dan tidak dapat diubah lewat update.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:255'],
            'identity_number' => ['nullable', 'string', 'max:50'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:1000'],
            'joined_at' => ['required', 'date'],
            'status' => ['required', 'in:active,inactive'],
        ];
    }

    /**
     * Tolak `member_number` yang dikirim klien — kode anggota immutable.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->filled('member_number')) {
                    $validator->errors()->add('member_number', 'Kode anggota dibuat oleh sistem dan tidak dapat diubah.');
                }
            },
        ];
    }

    /**
     * Pesan kustom untuk error validasi.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'full_name.required' => 'Nama lengkap wajib diisi.',
            'full_name.max' => 'Nama lengkap tidak boleh melebihi 255 karakter.',
            'joined_at.required' => 'Tanggal bergabung wajib dipilih.',
            'joined_at.date' => 'Format tanggal bergabung tidak valid.',
            'status.required' => 'Status anggota wajib dipilih.',
            'status.in' => 'Status anggota harus active atau inactive.',
        ];
    }
}
