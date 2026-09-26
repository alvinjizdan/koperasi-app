<?php

namespace App\Http\Requests;

use App\Models\Role;
use App\Services\PermissionRegistry;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRoleRequest extends FormRequest
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
        $role = $this->route('role');
        $roleId = $role instanceof Role ? $role->id : $role;
        $validPermissions = app(PermissionRegistry::class)->allNames();

        return [
            // Kode role tidak diubah agar referensi programatik tetap stabil.
            'display_name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => [Rule::in($validPermissions)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'display_name.required' => 'Nama tampilan role wajib diisi.',
            'permissions.*.in' => 'Terdapat permission yang tidak dikenal.',
        ];
    }
}
