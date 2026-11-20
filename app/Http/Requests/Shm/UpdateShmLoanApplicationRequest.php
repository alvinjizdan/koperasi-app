<?php

namespace App\Http\Requests\Shm;

use App\Models\ShmLoanApplication;
use Illuminate\Validation\Rule;

/**
 * Ticket 68 — validasi server-side perubahan Pengajuan Pinjaman SHM.
 *
 * Rules = rules create (StoreShmLoanApplicationRequest) karena contract data
 * 11 section identik; ditambah kunci `id` opsional pada array child agar
 * service dapat merekonsiliasi baris lama (update) vs baris baru (create)
 * tanpa delete-all-reinsert.
 *
 * Lock approval TIDAK divalidasi di sini: dilakukan di dalam transaksi
 * service (lockForUpdate) sehingga keputusan diambil dari state database
 * terbaru, bukan dari state UI yang mungkin sudah basi.
 *
 * `marketing_id` TIDAK diterima dari payload (tidak ada di rules) — pemilik
 * diatur server saat create dan tidak pernah berubah saat update, sehingga
 * manipulasi marketing_id dari klien tidak dapat membypass scope.
 */
class UpdateShmLoanApplicationRequest extends StoreShmLoanApplicationRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $application = $this->route('application');
        $currentAgentId = $application instanceof ShmLoanApplication
            ? $application->agent_id
            : null;

        $hasExistingMarriageDoc = $application instanceof ShmLoanApplication
            && ! empty($application->marriage_certificate_path);

        $hasExistingDivorceDoc = $application instanceof ShmLoanApplication
            && ! empty($application->divorce_certificate_path);

        $hasExistingDeathDoc = $application instanceof ShmLoanApplication
            && ! empty($application->death_certificate_path);

        return array_merge(parent::rules(), [
            // Pada update, Buku Nikah hanya wajib jika status menikah DAN belum pernah upload sebelumnya
            'pribadi.buku_nikah' => [
                Rule::requiredIf(fn () => in_array($this->input('pribadi.marital_status'), ['married', 'menikah'], true) && ! $hasExistingMarriageDoc),
                'nullable',
                'file',
                'mimes:pdf',
                'max:10240',
            ],
            // Pada update, Akta Cerai hanya wajib jika status janda/duda + cerai hidup DAN belum pernah upload sebelumnya
            'pribadi.akta_cerai' => [
                Rule::requiredIf(fn () => in_array($this->input('pribadi.marital_status'), ['janda', 'duda'], true)
                    && $this->input('pribadi.marital_separation_type') === 'cerai_hidup'
                    && ! $hasExistingDivorceDoc),
                'nullable',
                'file',
                'mimes:pdf',
                'max:10240',
            ],
            // Pada update, Akta Kematian hanya wajib jika status janda/duda + cerai mati DAN belum pernah upload sebelumnya
            'pribadi.akta_kematian' => [
                Rule::requiredIf(fn () => in_array($this->input('pribadi.marital_status'), ['janda', 'duda'], true)
                    && $this->input('pribadi.marital_separation_type') === 'cerai_mati'
                    && ! $hasExistingDeathDoc),
                'nullable',
                'file',
                'mimes:pdf',
                'max:10240',
            ],
            // Agen yang sudah terpasang tetap boleh dipertahankan meskipun
            // kemudian dinonaktifkan. Assignment BARU tetap wajib agen aktif.
            'pengajuan.agent_id' => [
                'nullable',
                'integer',
                Rule::exists('shm_agents', 'id')->where(function ($query) use ($currentAgentId): void {
                    $query->where('is_active', true);

                    if ($currentAgentId !== null) {
                        $query->orWhere('id', $currentAgentId);
                    }
                }),
            ],
            // Kunci rekonsiliasi child (opsional; tanpa id = baris baru).
            'jaminan.*.id' => ['nullable', 'integer'],
            'agunan.*.id' => ['nullable', 'integer'],
            'persyaratan.*.id' => ['nullable', 'integer'],
        ]);
    }
}
