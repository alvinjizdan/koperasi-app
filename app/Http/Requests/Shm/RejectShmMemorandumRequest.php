<?php

namespace App\Http\Requests\Shm;

use Illuminate\Foundation\Http\FormRequest;

class RejectShmMemorandumRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('shm.memorandum.reject') ?? false;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Alasan penolakan wajib diisi.',
            'reason.min' => 'Alasan penolakan minimal 3 karakter.',
            'reason.max' => 'Alasan penolakan maksimal 2000 karakter.',
        ];
    }

    public function reasonValue(): string
    {
        return (string) $this->input('reason');
    }
}
