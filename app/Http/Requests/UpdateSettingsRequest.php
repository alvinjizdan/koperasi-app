<?php

namespace App\Http\Requests;

use App\Services\SettingsService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSettingsRequest extends FormRequest
{
    /**
     * Aturan dinamis berdasarkan definisi pengaturan pada config/settings.php.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $service = app(SettingsService::class);
        $rules = [];

        foreach ($service->definitions() as $key => $definition) {
            $fieldRules = ['nullable', 'string', 'max:1000'];

            if (($definition['type'] ?? '') === 'email') {
                $fieldRules[] = 'email';
            }

            if (($definition['type'] ?? '') === 'select') {
                $fieldRules[] = Rule::in(array_keys($definition['options'] ?? []));
            }

            if ($definition['required'] ?? false) {
                array_unshift($fieldRules, 'required');
            }

            $rules[$key] = $fieldRules;
        }

        return $rules;
    }

    /**
     * Pesan kesalahan validasi berbahasa Indonesia.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $messages = [];

        foreach (app(SettingsService::class)->definitions() as $key => $definition) {
            $label = (string) ($definition['label'] ?? $key);

            if ($definition['required'] ?? false) {
                $messages[$key.'.required'] = $label.' wajib diisi.';
            }

            if (($definition['type'] ?? '') === 'email') {
                $messages[$key.'.email'] = $label.' harus berupa alamat email yang valid.';
            }

            if (($definition['type'] ?? '') === 'select') {
                $messages[$key.'.in'] = 'Nilai untuk '.$label.' tidak valid.';
            }
        }

        return $messages;
    }
}
