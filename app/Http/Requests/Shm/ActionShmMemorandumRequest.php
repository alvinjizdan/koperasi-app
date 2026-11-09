<?php

namespace App\Http\Requests\Shm;

use Illuminate\Foundation\Http\FormRequest;

class ActionShmMemorandumRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function notesValue(): ?string
    {
        $notes = $this->input('notes');

        return $notes !== null ? trim((string) $notes) : null;
    }
}
