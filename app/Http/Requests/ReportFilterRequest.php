<?php

namespace App\Http\Requests;

use App\Services\Reports\ReportPeriod;
use App\Support\PerPage;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Filter laporan dan dashboard.
 *
 * Seluruh parameter filter divalidasi di backend (tanggal, status, jenis, dan
 * relasi terkait) sehingga laporan tidak dapat dijalankan dengan parameter liar.
 */
class ReportFilterRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'period' => ['nullable', Rule::in(ReportPeriod::PRESETS)],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'status' => ['nullable', 'string', 'max:30'],
            'search' => ['nullable', 'string', 'max:100'],
            'operator_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'product_id' => ['nullable', 'integer', Rule::exists('saving_products', 'id')],
            'account_status' => ['nullable', Rule::in(['active', 'closed'])],
            'period_basis' => ['nullable', Rule::in(['submission', 'disbursement'])],
            'type' => ['nullable', Rule::in(['INCOME', 'EXPENSE', 'DEPOSIT'])],
            'category' => ['nullable', 'string', 'max:50'],
            'as_of_date' => ['nullable', 'date'],
            'minimum_days_late' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'sort' => ['nullable', 'string', 'max:50'],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ];
    }

    /**
     * Validasi rentang tanggal secara eksplisit.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $from = $this->input('date_from');
                $to = $this->input('date_to');

                if (is_string($from) && $from !== '' && is_string($to) && $to !== ''
                    && Carbon::parse($from)->startOfDay()->greaterThan(Carbon::parse($to)->startOfDay())) {
                    $validator->errors()->add('date_to', 'Tanggal akhir tidak boleh lebih awal dari tanggal awal.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'operator_id.exists' => 'Operator yang dipilih tidak ditemukan.',
            'product_id.exists' => 'Produk simpanan yang dipilih tidak ditemukan.',
            'type.in' => 'Jenis transaksi kas tidak dikenal.',
            'category.max' => 'Kategori transaksi terlalu panjang.',
        ];
    }

    /**
     * Filter yang sudah dinormalisasi dengan nilai bawaan.
     *
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        $validated = $this->validated();

        return [
            'period' => $validated['period'] ?? ReportPeriod::PRESET_THIS_MONTH,
            'date_from' => $validated['date_from'] ?? null,
            'date_to' => $validated['date_to'] ?? null,
            'status' => $validated['status'] ?? 'all',
            'search' => trim((string) ($validated['search'] ?? '')),
            'operator_id' => $validated['operator_id'] ?? null,
            'product_id' => $validated['product_id'] ?? null,
            'account_status' => $validated['account_status'] ?? 'all',
            'period_basis' => $validated['period_basis'] ?? 'submission',
            'type' => $validated['type'] ?? 'all',
            'category' => $validated['category'] ?? 'all',
            'as_of_date' => $validated['as_of_date'] ?? null,
            'minimum_days_late' => (int) ($validated['minimum_days_late'] ?? 0),
        ];
    }

    /**
     * Jumlah baris per halaman sesuai pilihan pengguna (?per_page=).
     *
     * Hanya menerima whitelist 10/25/50/100; nilai lain jatuh ke bawaan 25.
     *
     * @see PerPage
     */
    public function perPage(): int
    {
        return PerPage::resolve($this->input(PerPage::PARAM));
    }

    /**
     * Kunci kolom sorting yang diminta (mis. "member_number").
     */
    public function sort(): ?string
    {
        $sort = $this->validated()['sort'] ?? $this->input('sort');

        return is_string($sort) && $sort !== '' ? $sort : null;
    }

    /**
     * Arah sorting: "asc" atau "desc".
     */
    public function sortDirection(): ?string
    {
        $direction = $this->validated()['direction'] ?? $this->input('direction');

        return strtolower((string) $direction) === 'desc' ? 'desc' : (strtolower((string) $direction) === 'asc' ? 'asc' : null);
    }
}
