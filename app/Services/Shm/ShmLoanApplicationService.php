<?php

namespace App\Services\Shm;

use App\Enums\ShmApplicationStatus;
use App\Models\ShmApplication;
use App\Models\ShmLoanApplication;
use App\Services\AuditLogger;
use App\Services\ShmWorkflowService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Ticket 67/68 — penyimpanan & perubahan Pengajuan Pinjaman SHM.
 *
 * CREATE & UPDATE keduanya berjalan dalam SATU transaksi database: record
 * induk, jaminan (Section 5), barang agunan (Section 4), persyaratan
 * (Section 11), dan tepat SATU baris audit. Bila salah satu bagian gagal,
 * tidak ada satu pun bagian yang tertulis (tanpa partial application).
 *
 * Ticket 68 menambahkan:
 * - UPDATE atomik dengan rekonsiliasi child berbasis id (update existing /
 *   create baru / hapus yang hilang dari payload) — tanpa delete-all-reinsert;
 * - lock edit setelah approval dimulai (`shm_application_id` terisi);
 * - start approval yang REUSE ShmWorkflowService::startApproval() existing
 *   lewat mirror `shm_applications` (satu-satunya jalur yang diakui modul
 *   Approval SHM existing) — idempoten, atomik, tanpa approval ganda.
 *
 * Nomor pengajuan dibuat server-side mengikuti pola `PENGAJ-PIN-YYYYMMDD-XXXXXXXX`
 * (random_bytes, unik, tidak ditebak). Rate TIDAK pernah disentuh di sini —
 * rate resmi 2% berasal dari ShmLoan::OFFICIAL_RATE_PERCENT.
 *
 * Status keputusan TIDAK disimpan di kolom `status` (TICKET 68 §6): nilai
 * keputusan hidup di baris ShmApproval existing; kolom status tetap 'pending'.
 */
class ShmLoanApplicationService
{
    /** Percobaan pembuatan nomor sebelum dianggap gagal. */
    private const NUMBER_ATTEMPTS = 5;

    public function __construct(private readonly ShmWorkflowService $workflow) {}

    /**
     * Simpan pengajuan beserta seluruh child section secara atomik.
     *
     * @param  array<string, mixed>  $data  hasil validasi StoreShmLoanApplicationRequest
     */
    public function store(array $data): ShmLoanApplication
    {
        return DB::transaction(function () use ($data): ShmLoanApplication {
            $application = ShmLoanApplication::create(array_merge(
                $this->attributesFromPayload($data),
                [
                    'application_number' => $this->generateNumber(),
                    // Tanggal pengajuan dibuat server (bukan payload klien).
                    'application_date' => now()->format('Y-m-d'),
                    // Pemilik SELALU dari authenticated user — tidak pernah
                    // dari payload (marketing_id tidak ada di rules validasi).
                    'marketing_id' => Auth::id(),
                    // Status awal: vocabulary existing (BARU = menunggu keputusan).
                    'status' => 'pending',
                ],
            ));

            $this->createChildren($application, $data);

            // Tepat SATU baris audit per create (di dalam transaksi yang sama,
            // sehingga audit tidak pernah tertulis bila create gagal).
            AuditLogger::log(
                'shm.loan_application.created',
                ShmLoanApplication::class,
                $application->id,
                $application->application_number,
                sprintf('Pengajuan pinjaman SHM %s dibuat.', $application->application_number),
                [
                    'shm_customer_id' => $application->shm_customer_id,
                    'shm_loan_product_id' => $application->shm_loan_product_id,
                    'marketing_id' => $application->marketing_id,
                    'principal' => (string) $application->principal,
                    'tenor_months' => $application->tenor_months,
                    'calculation_method' => $application->calculation_method,
                    'jaminan_count' => $application->collaterals()->count(),
                    'agunan_count' => $application->assets()->count(),
                    'persyaratan_count' => $application->requirements()->count(),
                ],
            );

            return $application;
        });
    }

    /**
     * Ticket 68 — perbarui pengajuan yang BELUM diajukan approval.
     *
     * Atomik: induk + rekonsiliasi child + satu audit dalam satu transaksi.
     *
     * @param  array<string, mixed>  $data  hasil validasi UpdateShmLoanApplicationRequest
     *
     * @throws ValidationException bila pengajuan sudah terkunci approval
     */
    public function update(ShmLoanApplication $application, array $data): ShmLoanApplication
    {
        return DB::transaction(function () use ($application, $data): ShmLoanApplication {
            $locked = ShmLoanApplication::query()->lockForUpdate()->findOrFail($application->id);

            // APPROVAL LOCK (§12/§17): state dibaca dari baris terkunci di
            // dalam transaksi — bukan dari state UI yang mungkin sudah basi.
            if ($locked->isLocked()) {
                throw ValidationException::withMessages([
                    'application' => 'Pengajuan yang sudah diajukan approval tidak dapat diubah. Keputusan approve/reject dan peluang perbaikan mengikuti business rule yang belum dikonfirmasi (PENDING BUSINESS CONFIRMATION).',
                ]);
            }

            $locked->fill($this->attributesFromPayload($data));
            // identity/marketing/status/tanggal TIDAK pernah berubah di sini.
            $locked->save();

            $this->reconcileCollaterals($locked, $data['jaminan'] ?? []);
            $this->reconcileAssets($locked, $data['agunan'] ?? []);
            $this->reconcileRequirements($locked, $data['persyaratan'] ?? []);

            // Tepat SATU baris audit per update (dalam transaksi yang sama).
            AuditLogger::log(
                'shm.loan_application.updated',
                ShmLoanApplication::class,
                $locked->id,
                $locked->application_number,
                sprintf('Pengajuan pinjaman SHM %s diperbarui.', $locked->application_number),
                [
                    'shm_customer_id' => $locked->shm_customer_id,
                    'shm_loan_product_id' => $locked->shm_loan_product_id,
                    'principal' => (string) $locked->principal,
                    'tenor_months' => $locked->tenor_months,
                    'calculation_method' => $locked->calculation_method,
                    'jaminan_count' => $locked->collaterals()->count(),
                    'agunan_count' => $locked->assets()->count(),
                    'persyaratan_count' => $locked->requirements()->count(),
                ],
            );

            return $locked->load(['customer', 'product', 'collaterals', 'assets', 'requirements']);
        });
    }

    /**
     * Ticket 68 — "Ajukan Approval": REUSE ShmWorkflowService::startApproval()
     * dan ShmApproval existing tanpa tabel/service approval baru.
     *
     * Kontrak existing `startApproval(ShmApplication, actorId)` mengikat
     * approval ke `shm_applications`, sehingga pengajuan pinjaman membangun
     * SATU mirror `shm_applications` (nomor & nasabah sama) yang membawa baris
     * ShmApproval. Mirror inilah yang tampil di modul Approval SHM existing
     * dan menerima keputusan approve/reject lewat endpoint existing.
     *
     * - atomik: mirror + transisi workflow + tautan ke pengajuan dalam satu
     *   transaksi (kegagalan di tengah meninggalkan nol bekas);
     * - idempoten: panggilan berulang tidak membuat mirror/approval kedua;
     * - audit: TEPAT SATU baris dari workflow existing (tidak ada audit ganda).
     *
     * @throws ValidationException bila transisi workflow existing menolak
     */
    public function startApproval(ShmLoanApplication $application, int $actorId): ShmLoanApplication
    {
        return DB::transaction(function () use ($application, $actorId): ShmLoanApplication {
            $locked = ShmLoanApplication::query()->lockForUpdate()->findOrFail($application->id);

            // Idempoten: approval sudah pernah dimulai (pending maupun sudah
            // diputuskan) — tidak ada mirror/ approval kedua.
            if ($locked->shm_application_id !== null) {
                return $locked;
            }

            $mirror = ShmApplication::create([
                'application_number' => $locked->application_number,
                'shm_customer_id' => $locked->shm_customer_id,
                'marketing_id' => $locked->marketing_id ?? $actorId,
                // Mirror dibuat pada tahap memorandum (tahap teknis tepat
                // sebelum approval — pengajuan pinjaman tidak melewati
                // credit check/memorandum legacy). Transisi ke Approval
                // RESMI dilakukan oleh ShmWorkflowService::startApproval(),
                // sehingga state machine, ShmApproval, dan audit existing
                // tetap menjadi satu-satunya sumber kebenaran.
                'status' => ShmApplicationStatus::Memorandum->value,
                'notes' => sprintf('Mirror pengajuan pinjaman SHM %s (pembawa approval).', $locked->application_number),
            ]);

            $this->workflow->startApproval($mirror, $actorId);

            $locked->shm_application_id = $mirror->id;
            $locked->save();

            return $locked;
        });
    }

    /**
     * Pemetaan payload form (group per section) ke kolom induk — dipakai
     * bersama oleh store() dan update() sehingga tidak ada dua versi
     * pemetaan yang bisa menyimpang.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributesFromPayload(array $data): array
    {
        return [
            'shm_customer_id' => data_get($data, 'pribadi.customer_id'),
            'shm_loan_product_id' => data_get($data, 'pengajuan.product_id') ?: null,
            'principal' => data_get($data, 'pengajuan.principal'),
            'tenor_months' => (int) data_get($data, 'pengajuan.tenor_months'),
            'purpose' => data_get($data, 'pengajuan.purpose') ?: null,
            'loan_sequence' => data_get($data, 'pengajuan.loan_sequence') ?: null,
            'disbursement_method' => data_get($data, 'pengajuan.disbursement_method') ?: null,

            // Section 3 — komponen; total/sisa bersifat derived.
            'income_fixed' => $this->amount($data, 'income.tetap') ?? 0,
            'income_spouse' => $this->amount($data, 'income.pasangan') ?? 0,
            'income_business' => $this->amount($data, 'income.usaha') ?? 0,
            'expense_deduction' => $this->amount($data, 'expense.potongan') ?? 0,
            'expense_household' => $this->amount($data, 'expense.rumahTangga') ?? 0,
            'expense_education' => $this->amount($data, 'expense.pendidikan') ?? 0,
            'expense_rent' => $this->amount($data, 'expense.sewa') ?? 0,
            'expense_utilities' => $this->amount($data, 'expense.listrik') ?? 0,
            'expense_transport' => $this->amount($data, 'expense.transportasi') ?? 0,
            'expense_other' => $this->amount($data, 'expense.lain') ?? 0,

            // Section 4 — Data Tambahan.
            'koperasi_loan_balance' => $this->amount($data, 'tambahan.koperasi_loan_balance'),
            'other_facility' => data_get($data, 'tambahan.other_facility') ?: null,
            'institution_name' => data_get($data, 'tambahan.institution_name') ?: null,

            // Section 6 — Catatan Kasir / SC.
            'kasir_note_date' => data_get($data, 'catatan.date') ?: null,
            'kasir_note_name' => data_get($data, 'catatan.name') ?: null,
            'kasir_note_text' => data_get($data, 'catatan.notes') ?: null,

            // Section 7 — Analisa Pinjaman.
            'analyst_code' => data_get($data, 'analisa.analyst_code') ?: null,
            'analyst_office' => data_get($data, 'analisa.analyst_office') ?: null,
            'officer_code' => data_get($data, 'analisa.officer_code') ?: null,
            'officer_office' => data_get($data, 'analisa.officer_office') ?: null,
            'analysis_date' => data_get($data, 'analisa.date') ?: null,
            'analysis_value' => $this->amount($data, 'analisa.value'),
            'analysis_notes' => data_get($data, 'analisa.notes') ?: null,

            // Section 9 — Kolektor (teks bebas; master PENDING).
            'collector_name' => data_get($data, 'kolektor.name') ?: null,

            // Section 10 — Persetujuan (tanpa formula; method eksplisit).
            'approved_amount' => $this->amount($data, 'persetujuan.approved_amount'),
            'provision_fee' => $this->amount($data, 'persetujuan.provision_fee'),
            'administration_fee' => $this->amount($data, 'persetujuan.administration_fee'),
            'calculation_method' => data_get($data, 'persetujuan.method') ?: null,
            'approval_notes' => data_get($data, 'persetujuan.notes') ?: null,
        ];
    }

    /** Buat seluruh child section (dipakai store). */
    private function createChildren(ShmLoanApplication $application, array $data): void
    {
        $application->collaterals()->createMany(
            collect($data['jaminan'] ?? [])->map(fn (array $item): array => $this->collateralAttributes($item))->all(),
        );

        $application->assets()->createMany(
            collect($data['agunan'] ?? [])->map(fn (array $item): array => $this->assetAttributes($item))->all(),
        );

        $application->requirements()->createMany(
            collect($data['persyaratan'] ?? [])->map(fn (array $item): array => $this->requirementAttributes($item))->all(),
        );
    }

    /**
     * Rekonsiliasi Section 5: item dengan id → update bila milik pengajuan
     * ini; tanpa id → create; baris lama yang hilang dari payload → delete.
     * Id asing tidak pernah disentuh (scope relasi membatasi ke pengajuan ini).
     */
    private function reconcileCollaterals(ShmLoanApplication $application, array $items): void
    {
        $kept = [];

        foreach ($items as $item) {
            $attributes = $this->collateralAttributes($item);
            $id = $item['id'] ?? null;

            if ($id !== null && $id !== '') {
                $existing = $application->collaterals()->whereKey($id)->first();
                if ($existing !== null) {
                    $existing->fill($attributes)->save();
                    $kept[] = $existing->id;

                    continue;
                }
            }

            $kept[] = $application->collaterals()->create($attributes)->id;
        }

        $application->collaterals()->whereNotIn('id', $kept)->delete();
    }

    /** Rekonsiliasi Section 4 — pola sama dengan jaminan. */
    private function reconcileAssets(ShmLoanApplication $application, array $items): void
    {
        $kept = [];

        foreach ($items as $item) {
            $attributes = $this->assetAttributes($item);
            $id = $item['id'] ?? null;

            if ($id !== null && $id !== '') {
                $existing = $application->assets()->whereKey($id)->first();
                if ($existing !== null) {
                    $existing->fill($attributes)->save();
                    $kept[] = $existing->id;

                    continue;
                }
            }

            $kept[] = $application->assets()->create($attributes)->id;
        }

        $application->assets()->whereNotIn('id', $kept)->delete();
    }

    /** Rekonsiliasi Section 11 — pola sama dengan jaminan. */
    private function reconcileRequirements(ShmLoanApplication $application, array $items): void
    {
        $kept = [];

        foreach ($items as $item) {
            $attributes = $this->requirementAttributes($item);
            $id = $item['id'] ?? null;

            if ($id !== null && $id !== '') {
                $existing = $application->requirements()->whereKey($id)->first();
                if ($existing !== null) {
                    $existing->fill($attributes)->save();
                    $kept[] = $existing->id;

                    continue;
                }
            }

            $kept[] = $application->requirements()->create($attributes)->id;
        }

        $application->requirements()->whereNotIn('id', $kept)->delete();
    }

    /** @param array<string, mixed> $item */
    private function collateralAttributes(array $item): array
    {
        return [
            'collateral_date' => $item['tanggal'] ?? null,
            'collateral_type' => $item['jenis'],
            'collateral_name' => $item['nama'],
            'notes' => $item['keterangan'] ?? null,
            'estimated_value' => $item['nilai_perkiraan'] ?? null,
            'binding_status' => $item['status_pengikatan'] ?? null,
            'binding_date' => $item['tanggal_pengikatan'] ?? null,
            'rights_value' => $item['nilai_hak_tanggungan'] ?? null,
        ];
    }

    /** @param array<string, mixed> $item */
    private function assetAttributes(array $item): array
    {
        return [
            'asset_type' => $item['jenis'],
            'asset_number' => $item['nomor'] ?? null,
            'asset_year' => $item['tahun'] ?? null,
            'estimated_value' => $item['nilai'] ?? null,
        ];
    }

    /** @param array<string, mixed> $item */
    private function requirementAttributes(array $item): array
    {
        return [
            'requirement' => $item['text'],
            'is_done' => (bool) ($item['done'] ?? false),
        ];
    }

    /**
     * Nomor pengajuan baru, dibuat di server.
     *
     * Format provisional `PENGAJ-PIN-YYYYMMDD-XXXXXXXX` mengikuti pola nomor
     * nasabah SHM; komponen acak dari random_bytes sehingga tidak dapat
     * ditebak dan tidak bentrok antar request paralel.
     *
     * @throws RuntimeException bila setelah beberapa percobaan tetap bentrok
     */
    private function generateNumber(): string
    {
        for ($attempt = 0; $attempt < self::NUMBER_ATTEMPTS; $attempt++) {
            $candidate = sprintf(
                'PENGAJ-PIN-%s-%s',
                now()->format('Ymd'),
                strtoupper(bin2hex(random_bytes(4))),
            );

            if (! ShmLoanApplication::query()->where('application_number', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new RuntimeException(
            'Nomor pengajuan pinjaman SHM tidak dapat dibuat setelah beberapa percobaan.'
        );
    }

    /**
     * Ambil nilai finansial dari payload; kosong/null menjadi null agar kolom
     * nullable menerima NULL (kolom default 0 ditangani pemanggil dengan
     * `?? 0`).
     */
    private function amount(array $data, string $path): ?float
    {
        $value = data_get($data, $path);

        return $value === null || $value === '' ? null : (float) $value;
    }
}
