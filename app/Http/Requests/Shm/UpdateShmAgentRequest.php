<?php

namespace App\Http\Requests\Shm;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateShmAgentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('shm.agent.update') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $agentId = $this->route('agent')?->id ?? $this->route('agent');

        return [
            'name' => ['required', 'string', 'max:150'],
            'nik' => [
                'required',
                'string',
                'regex:/^\d{16}$/',
                Rule::unique('shm_agents', 'nik')->ignore($agentId),
            ],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Nama lengkap agen wajib diisi.',
            'nik.required' => 'NIK wajib diisi.',
            'nik.regex' => 'NIK harus berupa 16 digit angka.',
            'nik.unique' => 'NIK sudah terdaftar untuk agen lain.',
        ];
    }
}
