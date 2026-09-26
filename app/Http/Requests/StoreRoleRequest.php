<?php

namespace App\Http\Requests;

use App\Services\PermissionRegistry;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRoleRequest extends FormRequest
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
        $validPermissions = app(PermissionRegistry::class)->allNames();

        return [
            'name' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/', 'unique:roles,name'],
            'display_name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'permissions' => ['nullable', 'array'],
            // Hanya permission pada registry yang dapat ditetapkan.
            'permissions.*' => [Rule::in($validPermissions)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Kode role wajib diisi.',
            'name.unique' => 'Kode role ini sudah digunakan.',
            'name.regex' => 'Kode role hanya boleh berisi huruf kecil, angka, dan underscore.',
            'display_name.required' => 'Nama tampilan role wajib diisi.',
            'permissions.*.in' => 'Terdapat permission yang tidak dikenal.',
        ];
    }
}
