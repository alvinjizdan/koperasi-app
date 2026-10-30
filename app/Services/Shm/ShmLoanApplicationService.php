<?php

namespace App\Services\Shm;

use App\Enums\ShmApplicationStatus;
use App\Models\ShmApplication;
use App\Models\ShmLoanApplication;
use App\Models\ShmLoanProduct;
use App\Services\AuditLogger;
use App\Services\ShmWorkflowService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

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
 * (random_bytes, unik, tidak ditebak). Rate TIDAK pernah datang dari klien:
 * sejak T82 rate = f(product, tenor) yang di-resolve ulang oleh backend
 * (ShmProductRateService) — universal 2% BUKAN lagi rate SHM. Pengajuan tidak
 * memiliki kolom rate sehingga payload klien tidak dapat mengoverride.
 *
 * Status keputusan TIDAK disimpan di kolom `status` (TICKET 68 §6): nilai
 * keputusan hidup di baris ShmApproval existing; kolom status tetap 'pending'.
 */
class ShmLoanApplicationService
{
    /** Percobaan pembuatan nomor sebelum dianggap gagal. */
    private const NUMBER_ATTEMPTS = 5;

    /**
     * Ticket 77 — foto jaminan disimpan pada disk PRIVATE Laravel ("local",
     * storage/app/private) sehingga berkas tidak dapat diakses langsung dari
     * web. Path RELATIF yang disimpan di database; penyajian ke frontend
     * selalu lewat route terotorisasi (ownership pengajuan SHM).
     */
    private const PHOTO_DISK = 'local';

    private const PHOTO_DIRECTORY = 'shm/collateral-photos';

    private const DOCUMENT_DIRECTORY = 'shm/collateral-documents';

    public function __construct(private readonly ShmWorkflowService $workflow) {}

    /**
     * Simpan pengajuan beserta seluruh child section secara atomik.
     *
     * @param  array<string, mixed>  $data  hasil validasi StoreShmLoanApplicationRequest
     */
    public function store(array $data): ShmLoanApplication
    {
        // Ticket 82 — resolusi rate produk+tenor SEBELUM transaksi: tenor di
        // luar range eksak produk ditolak dengan validation error yang jelas.
        $this->assertProductTenorCompatible($data);

        // Ticket 77 — jejak berkas baru agar dapat dibersihkan bila transaksi
        // gagal (operasi filesystem tidak ikut ter-rollback oleh database).
        $storedPhotos = [];
        $storedDocuments = [];

        try {
            return DB::transaction(function () use ($data, &$storedPhotos, &$storedDocuments): ShmLoanApplication {
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

                $this->createChildren($application, $data, $storedPhotos, $storedDocuments);

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
        } catch (Throwable $exception) {
            // Bersihkan berkas foto/dokumen yang sudah tersimpan sebelum kegagalan —
            // database di-rollback, jadi tidak boleh ada orphan file.
            $this->deletePhotoFiles($storedPhotos);
            $this->deletePhotoFiles($storedDocuments);

            throw $exception;
        }
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
        // Ticket 77 — jejak berkas (baru & lama) untuk failure-safe:
        // - berkas BARU dibersihkan bila transaksi gagal (no orphan file);
        // - berkas LAMA dihapus HANYA setelah transaksi berhasil, sehingga
        //   replace foto tidak pernah menghapus berkas lama lebih dulu.
        $storedPhotos = [];
        $retiredPhotos = [];
        $storedDocuments = [];
        $retiredDocuments = [];

        // Ticket 82 — resolusi rate produk+tenor berlaku untuk create & update.
        $this->assertProductTenorCompatible($data);

        try {
            return DB::transaction(function () use ($application, $data, &$storedPhotos, &$retiredPhotos, &$storedDocuments, &$retiredDocuments): ShmLoanApplication {
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

                $this->reconcileCollaterals($locked, $data['jaminan'] ?? [], $storedPhotos, $retiredPhotos, $storedDocuments, $retiredDocuments);
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
        } catch (Throwable $exception) {
            $this->deletePhotoFiles($storedPhotos);
            $this->deletePhotoFiles($storedDocuments);

            throw $exception;
        } finally {
            // Transaksi sukses → baris kolateral sudah menunjuk berkas baru
            // (atau tetap); penghapusan berkas lama yang digantikan/dihapus
            // baris dilakukan SETELAH commit (failure-safe).
            $this->deletePhotoFiles($retiredPhotos);
            $this->deletePhotoFiles($retiredDocuments);
        }
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
     * Ticket 82 — guard kontrak produk: tenor harus berada di range EXAKT
     * tier rate produk (inclusive; gap INVALID — tanpa fallback/interpolasi/
     * nearest/default). Resolusi dilakukan backend; frontend hanyalah
     * tampilan (bukan source of truth).
     *
     * Produk nullable di pengajuan (perilaku existing T66): tanpa produk
     * tidak ada rate yang bisa di-resolve sehingga guard ini lewat; gate
     * produk + first_due_date tetap ada di pencairan (disbursement).
     *
     * @param  array<string, mixed>  $data
     */
    private function assertProductTenorCompatible(array $data): void
    {
        $productId = data_get($data, 'pengajuan.product_id');
        $tenor = (int) data_get($data, 'pengajuan.tenor_months');

        if (empty($productId) || $tenor < 1) {
            return;
        }

        $product = ShmLoanProduct::query()->find($productId);

        if ($product === null) {
            return; // request rules sudah memvalidasi exists-product
        }

        // Melempar ValidationException pada kunci 'pengajuan.tenor_months'
        // bila tenor di gap / di luar seluruh range / produk nonaktif.
        app(ShmProductRateService::class)->resolve($product, $tenor);
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
            // Ticket 76-FIX — Data Pribadi adalah snapshot per pengajuan di
            // `shm_loan_applications`; TIDAK PERNAH menulis `shm_customers`.
            'unit_kerja' => data_get($data, 'pribadi.unit_kerja') ?: null,
            'nip' => data_get($data, 'pribadi.nip') ?: null,
            'telepon_rumah' => data_get($data, 'pribadi.telepon_rumah') ?: null,
            'tempat_lahir' => data_get($data, 'pribadi.tempat_lahir') ?: null,
            'tanggal_lahir' => data_get($data, 'pribadi.tanggal_lahir') ?: null,
            // 0 adalah nilai sah (tanpa tanggungan) — jangan di-`?:` ke null.
            'jumlah_tanggungan' => data_get($data, 'pribadi.jumlah_tanggungan'),
            'saldo_modal' => $this->amount($data, 'pribadi.saldo_modal'),
            'saldo_non_modal' => $this->amount($data, 'pribadi.saldo_non_modal'),
            'batas_hutang' => $this->amount($data, 'pribadi.batas_hutang'),
            'shm_loan_product_id' => data_get($data, 'pengajuan.product_id') ?: null,
            'principal' => data_get($data, 'pengajuan.principal'),
            'tenor_months' => (int) data_get($data, 'pengajuan.tenor_months'),
            // Ticket 82 — jatuh tempo pertama dipilih Marketing (date-only;
            // bukan hasil hitung otomatis dari tanggal pencairan).
            'first_due_date' => data_get($data, 'pengajuan.first_due_date') ?: null,
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
            // Ticket 85 — biaya notaris custom.
            'notary_fee' => $this->amount($data, 'persetujuan.notary_fee'),
            'calculation_method' => data_get($data, 'persetujuan.method') ?: null,
            'approval_notes' => data_get($data, 'persetujuan.notes') ?: null,
        ];
    }

    /** Buat seluruh child section (dipakai store). */
    private function createChildren(ShmLoanApplication $application, array $data, array &$storedPhotos, array &$storedDocuments = []): void
    {
        foreach ($data['jaminan'] ?? [] as $item) {
            $collateral = $application->collaterals()->create($this->collateralAttributes($item));

            // Foto jaminan (bila ada)
            $photoPath = $this->storeCollateralPhoto($application, $item['foto'] ?? null);
            if ($photoPath !== null) {
                $storedPhotos[] = $photoPath;
                $collateral->forceFill(['photo_path' => $photoPath])->save();
            }

            // Dokumen PDF jaminan (bila ada)
            $docResult = $this->storeCollateralDocument($application, $item['dokumen'] ?? null);
            if ($docResult !== null) {
                $storedDocuments[] = $docResult['path'];
                $collateral->forceFill([
                    'document_path' => $docResult['path'],
                    'document_name' => $docResult['name'],
                ])->save();
            }
        }

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
     *
     * Ticket 77 — foto:
     * - upload baru → berkas disimpan DULU, path ditulis ke baris; berkas
     *   lama (bila ada) dijadwalkan untuk dihapus setelah commit;
     * - baris dihapus dari payload → berkas fotonya ikut dijadwalkan hapus
     *   (tidak ada orphan file);
     * - tanpa foto baru → photo_path lama TIDAK disentuh.
     */
    private function reconcileCollaterals(
        ShmLoanApplication $application,
        array $items,
        array &$storedPhotos,
        array &$retiredPhotos,
        array &$storedDocuments = [],
        array &$retiredDocuments = [],
    ): void {
        $kept = [];

        foreach ($items as $item) {
            $attributes = $this->collateralAttributes($item);
            $id = $item['id'] ?? null;

            if ($id !== null && $id !== '') {
                $existing = $application->collaterals()->whereKey($id)->first();
                if ($existing !== null) {
                    $existing->fill($attributes)->save();

                    $photoPath = $this->storeCollateralPhoto($application, $item['foto'] ?? null);
                    if ($photoPath !== null) {
                        $storedPhotos[] = $photoPath;

                        if ($existing->photo_path !== null) {
                            $retiredPhotos[] = $existing->photo_path;
                        }

                        $existing->forceFill(['photo_path' => $photoPath])->save();
                    }

                    $docResult = $this->storeCollateralDocument($application, $item['dokumen'] ?? null);
                    if ($docResult !== null) {
                        $storedDocuments[] = $docResult['path'];

                        if ($existing->document_path !== null) {
                            $retiredDocuments[] = $existing->document_path;
                        }

                        $existing->forceFill([
                            'document_path' => $docResult['path'],
                            'document_name' => $docResult['name'],
                        ])->save();
                    }

                    $kept[] = $existing->id;

                    continue;
                }
            }

            $collateral = $application->collaterals()->create($attributes);

            $photoPath = $this->storeCollateralPhoto($application, $item['foto'] ?? null);
            if ($photoPath !== null) {
                $storedPhotos[] = $photoPath;
                $collateral->forceFill(['photo_path' => $photoPath])->save();
            }

            $docResult = $this->storeCollateralDocument($application, $item['dokumen'] ?? null);
            if ($docResult !== null) {
                $storedDocuments[] = $docResult['path'];
                $collateral->forceFill([
                    'document_path' => $docResult['path'],
                    'document_name' => $docResult['name'],
                ])->save();
            }

            $kept[] = $collateral->id;
        }

        $removed = $application->collaterals()->whereNotIn('id', $kept)->get();

        foreach ($removed as $collateral) {
            if ($collateral->photo_path !== null) {
                $retiredPhotos[] = $collateral->photo_path;
            }
            if ($collateral->document_path !== null) {
                $retiredDocuments[] = $collateral->document_path;
            }
        }

        $application->collaterals()->whereNotIn('id', $kept)->delete();
    }

    /**
     * Ticket 77 — simpan satu berkas foto jaminan ke disk privat.
     *
     * Mengembalikan path relatif pada disk, atau null bila payload tidak
     * membawa berkas (jaminan tanpa foto tetap valid — backward compatible).
     * Validasi tipe/ukuran sudah dilakukan FormRequest; method ini hanya
     * memastikan berkas benar-benar ter-upload sebelum menyimpan path.
     */
    private function storeCollateralPhoto(ShmLoanApplication $application, mixed $file): ?string
    {
        if (! $file instanceof UploadedFile || ! $file->isValid()) {
            return null;
        }

        $path = $file->store(
            self::PHOTO_DIRECTORY.'/'.$application->id,
            self::PHOTO_DISK,
        );

        if ($path === false) {
            throw new RuntimeException('Foto jaminan gagal disimpan.');
        }

        return $path;
    }

    /**
     * Simpan satu berkas dokumen PDF jaminan ke disk privat.
     *
     * @return array{path: string, name: string}|null
     */
    private function storeCollateralDocument(ShmLoanApplication $application, mixed $file): ?array
    {
        if (! $file instanceof UploadedFile || ! $file->isValid()) {
            return null;
        }

        $path = $file->store(
            self::DOCUMENT_DIRECTORY.'/'.$application->id,
            self::PHOTO_DISK,
        );

        if ($path === false) {
            throw new RuntimeException('Dokumen jaminan gagal disimpan.');
        }

        return [
            'path' => $path,
            'name' => $file->getClientOriginalName(),
        ];
    }

    /**
     * Hapus berkas (foto/dokumen) secara failure-safe.
     *
     * @param  array<int, string>  $paths
     */
    private function deletePhotoFiles(array $paths): void
    {
        foreach ($paths as $path) {
            if (is_string($path) && $path !== '') {
                Storage::disk(self::PHOTO_DISK)->delete($path);
            }
        }
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
