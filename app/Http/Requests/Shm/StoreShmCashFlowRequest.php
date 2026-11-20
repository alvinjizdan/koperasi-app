<?php

namespace App\Http\Requests\Shm;

use App\Models\ShmCashTransaction;
use App\Support\AccountingContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreShmCashFlowRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('accounting.shm.journals.create') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in([ShmCashTransaction::TYPE_INFLOW, ShmCashTransaction::TYPE_OUTFLOW])],
            'transaction_date' => ['required', 'date'],
            'cash_account_id' => [
                'required',
                'integer',
                Rule::exists('chart_of_accounts', 'id')->where(function ($query) {
                    $query->where('accounting_context', AccountingContext::SHM)
                        ->where('is_active', true);
                }),
            ],
            'account_id' => [
                'required',
                'integer',
                'different:cash_account_id',
                Rule::exists('chart_of_accounts', 'id')->where(function ($query) {
                    $query->where('accounting_context', AccountingContext::SHM)
                        ->where('is_active', true);
                }),
            ],
            'amount' => ['required', 'numeric', 'gt:0'],
            'description' => ['nullable', 'string', 'max:500'],
            'shm_settlement_refund_id' => [
                'nullable',
                'integer',
                Rule::exists('shm_settlement_refunds', 'id'),
            ],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'cash_account_id.required' => 'Akun Kas/Bank wajib dipilih.',
            'cash_account_id.exists' => 'Akun Kas/Bank harus merupakan akun SHM yang aktif.',
            'account_id.required' => 'Nama Perkiraan (Akun Lawan) wajib dipilih.',
            'account_id.exists' => 'Nama Perkiraan harus merupakan akun SHM yang aktif.',
            'account_id.different' => 'Nama Perkiraan tidak boleh sama dengan Akun Kas/Bank.',
            'amount.required' => 'Nominal transaksi wajib diisi.',
            'amount.gt' => 'Nominal transaksi harus lebih besar dari 0.',
        ];
    }
}
