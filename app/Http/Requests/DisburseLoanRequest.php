<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DisburseLoanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'disbursement_date' => ['required', 'date'],
            'first_due_date' => ['required', 'date', 'after_or_equal:disbursement_date'],
            'method' => ['required', 'string', 'in:CASH,TRANSFER'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
