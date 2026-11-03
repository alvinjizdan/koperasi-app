<?php

namespace App\Http\Requests\Shm;

use App\Models\ShmLoanProduct;
use App\Services\Shm\ProductRateQuote;
use App\Services\Shm\ShmLoanSimulationService;
use App\Services\Shm\ShmProductRateService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

/**
 * Validasi input Simulasi Pinjaman SHM.
 *
 * Rate bunga diturunkan secara authoritative dari konfigurasi produk pinjaman
 * dan tenor yang dipilih (ShmProductRateService), bukan fallback legacy 2%.
 */
class LoanSimulationRequest extends FormRequest
{
    private ?ProductRateQuote $resolvedRateQuote = null;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Apakah ini submit formulir simulasi (bukan render halaman kosong)?
     */
    public function isSubmitted(): bool
    {
        return $this->has('run');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        if (! $this->isSubmitted()) {
            return [];
        }

        $methods = array_keys(ShmLoanSimulationService::supportedMethods());
        $periods = array_keys(ShmLoanSimulationService::supportedPeriods());

        return [
            'name' => ['nullable', 'string', 'max:150'],
            'product_id' => [
                'required',
                'integer',
                Rule::exists('shm_loan_products', 'id')->where('is_active', true),
            ],
            'principal' => ['required', 'numeric', 'min:1', 'max:1000000000000'],
            'tenor_months' => ['required', 'integer', 'min:1', 'max:600'],
            'rate_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'method' => ['required', Rule::in($methods)],
            'rate_period' => ['required', Rule::in($periods)],
            'location' => ['nullable', 'string', 'max:150'],
            'purpose' => ['nullable', 'string', 'max:255'],
            'fees' => ['nullable', 'array'],
            'fees.administration' => ['nullable', 'numeric', 'min:0', 'max:1000000000000'],
            'fees.provision' => ['nullable', 'numeric', 'min:0', 'max:1000000000000'],
            'fees.insurance' => ['nullable', 'numeric', 'min:0', 'max:1000000000000'],
            'fees.other' => ['nullable', 'numeric', 'min:0', 'max:1000000000000'],
            'fees.block_funds' => ['nullable', 'numeric', 'min:0', 'max:1000000000000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.max' => 'Nama calon/nasabah tidak boleh melebihi 150 karakter.',
            'product_id.required' => 'Produk pinjaman wajib dipilih.',
            'product_id.exists' => 'Produk pinjaman tidak ditemukan atau sudah nonaktif.',
            'principal.required' => 'Plafon pinjaman wajib diisi.',
            'principal.numeric' => 'Plafon pinjaman harus berupa angka.',
            'principal.min' => 'Plafon pinjaman harus lebih dari 0.',
            'principal.max' => 'Plafon pinjaman terlalu besar.',
            'tenor_months.required' => 'Tenor wajib diisi.',
            'tenor_months.integer' => 'Tenor harus berupa bilangan bulat.',
            'tenor_months.min' => 'Tenor minimal 1 bulan.',
            'tenor_months.max' => 'Tenor maksimal 600 bulan.',
            'rate_percent.numeric' => 'Bunga/jasa harus berupa angka.',
            'rate_percent.min' => 'Bunga/jasa tidak boleh negatif.',
            'rate_percent.max' => 'Bunga/jasa tidak boleh melebihi 100%.',
            'method.required' => 'Metode angsuran wajib dipilih.',
            'method.in' => 'Metode angsuran tidak dikenal.',
            'rate_period.required' => 'Periode rate wajib dipilih (per bulan atau per tahun).',
            'rate_period.in' => 'Periode rate tidak dikenal.',
            'location.max' => 'Lokasi pinjaman tidak boleh melebihi 150 karakter.',
            'purpose.max' => 'Tujuan pinjaman tidak boleh melebihi 255 karakter.',
            'fees.*.numeric' => 'Rincian biaya harus berupa angka.',
            'fees.*.min' => 'Rincian biaya tidak boleh negatif.',
            'fees.*.max' => 'Rincian biaya terlalu besar.',
        ];
    }

    /**
     * Validasi rate bunga terhadap konfigurasi produk & tenor.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if (! $this->isSubmitted()) {
                return;
            }

            if ($v->errors()->has('product_id') || $v->errors()->has('tenor_months')) {
                return;
            }

            $productId = $this->input('product_id');
            $tenorMonths = (int) $this->input('tenor_months');

            if (! $productId || $tenorMonths <= 0) {
                return;
            }

            $product = ShmLoanProduct::query()->find($productId);
            if (! $product) {
                return;
            }

            try {
                $rateService = app(ShmProductRateService::class);
                $this->resolvedRateQuote = $rateService->resolve($product, $tenorMonths);
            } catch (ValidationException $e) {
                $messages = $e->errors();
                $message = $messages['pengajuan.tenor_months'][0]
                    ?? $messages['tenor_months'][0]
                    ?? 'Rate bunga untuk produk dan tenor yang dipilih belum dikonfigurasi.';

                $v->errors()->add('tenor_months', $message);
            }
        });
    }

    public function resolvedRateQuote(): ?ProductRateQuote
    {
        return $this->resolvedRateQuote;
    }

    /**
     * Rate percent authoritative dari konfigurasi produk.
     */
    public function ratePercent(): float
    {
        if ($this->resolvedRateQuote) {
            return $this->resolvedRateQuote->ratePercent;
        }

        $rate = $this->input('rate_percent');

        return $rate !== null && $rate !== '' ? (float) $rate : 0.0;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'name' => $this->string('name')?->toString(),
            'product_id' => $this->filled('product_id') ? (int) $this->input('product_id') : null,
            'principal' => (float) $this->input('principal'),
            'tenor_months' => (int) $this->input('tenor_months'),
            'rate_percent' => $this->ratePercent(),
            'method' => (string) $this->input('method'),
            'rate_period' => (string) $this->input('rate_period'),
            'location' => $this->string('location')?->toString(),
            'purpose' => $this->string('purpose')?->toString(),
            'fees' => array_map(
                fn ($value): float => (float) ($value ?? 0),
                (array) $this->input('fees', []),
            ) + [
                'administration' => 0.0,
                'provision' => 0.0,
                'insurance' => 0.0,
                'other' => 0.0,
                'block_funds' => 0.0,
            ],
        ];
    }
}
