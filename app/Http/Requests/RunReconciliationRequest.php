<?php

namespace App\Http\Requests;

use App\Models\ReconciliationRun;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RunReconciliationRequest extends FormRequest
{
    /**
     * Menjalankan rekonsiliasi memerlukan permission khusus, terpisah
     * dari melihat hasil dan menindaklanjuti temuan.
     */
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->can('rekonsiliasi.run');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'period_start' => ['required', 'date', 'before_or_equal:period_end'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start', 'before_or_equal:today'],
            'modules' => ['required', 'array', 'min:1'],
            'modules.*' => ['string', Rule::in(ReconciliationRun::MODULES)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'period_start.required' => 'Periode awal wajib diisi.',
            'period_start.before_or_equal' => 'Periode awal tidak boleh melebihi periode akhir.',
            'period_end.required' => 'Periode akhir wajib diisi.',
            'period_end.after_or_equal' => 'Periode akhir tidak boleh lebih awal dari periode awal.',
            'period_end.before_or_equal' => 'Periode akhir tidak boleh melebihi tanggal hari ini.',
            'modules.required' => 'Pilih minimal satu modul untuk direkonsiliasi.',
            'modules.min' => 'Pilih minimal satu modul untuk direkonsiliasi.',
            'modules.*.in' => 'Modul yang dipilih tidak valid.',
        ];
    }
}
