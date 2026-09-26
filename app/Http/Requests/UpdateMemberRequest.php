<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $memberId = $this->route('member')?->id ?? $this->route('member');

        return [
            'member_number' => [
                'required',
                'string',
                'max:50',
                Rule::unique('members', 'member_number')->ignore($memberId),
            ],
            'full_name' => ['required', 'string', 'max:255'],
            'identity_number' => ['nullable', 'string', 'max:50'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:1000'],
            'joined_at' => ['required', 'date'],
            'status' => ['required', 'in:active,inactive'],
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
            'member_number.required' => 'Nomor anggota wajib diisi.',
            'member_number.unique' => 'Nomor anggota ini sudah terdaftar untuk anggota lain.',
            'member_number.max' => 'Nomor anggota tidak boleh melebihi 50 karakter.',
            'full_name.required' => 'Nama lengkap wajib diisi.',
            'full_name.max' => 'Nama lengkap tidak boleh melebihi 255 karakter.',
            'joined_at.required' => 'Tanggal bergabung wajib dipilih.',
            'joined_at.date' => 'Format tanggal bergabung tidak valid.',
            'status.required' => 'Status anggota wajib dipilih.',
            'status.in' => 'Status anggota harus active atau inactive.',
        ];
    }
}
