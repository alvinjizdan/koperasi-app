<?php

namespace App\Services;

use App\Enums\ShmApplicationStatus;
use App\Enums\ShmCreditCheckOutcome;
use App\Enums\ShmCreditCheckStatus;
use App\Models\ShmApplication;
use App\Models\ShmApproval;
use App\Models\ShmCreditCheck;
use App\Models\ShmDocument;
use App\Models\ShmMemorandum;
use App\Support\DbTransaction;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Alur kerja pengajuan SHM.
 *
 * Semua perubahan status WAJIB lewat kelas ini. Controller tidak pernah
 * menulis kolom status secara langsung, sehingga transisi ilegal tidak dapat
 * terjadi melalui request biasa maupun melalui request yang memalsukan kolom.
 *
 * Aturan yang ditegakkan:
 * - transisi hanya berjalan bila target ada di peta transisi status;
 * - actor selalu dari pengguna yang sedang login, bukan dari payload;
 * - seluruh timestamp workflow diisi waktu sistem;
 * - satu aksi = satu baris audit.
 *
 * Workflow ini adalah workflow TEKNIS MVP agar sistem dapat dipakai dan
 * didemokan. Bukan SOP resmi KSP. Kebutuhan untuk kembali ke tahap
 * sebelumnya, pembatalan, dan pemulihan dari status ditolak masih
 * PENDING BUSINESS DECISION.
 */
class ShmWorkflowService
{
    /**
     * Nama event audit untuk setiap status tujuan.
     *
     * Peta ini dipakai supaya setiap transisi hanya menulis SATU event.
     * Methods logChanges dan log tidak boleh dipanggil bersamaan untuk aksi
     * yang sama karena keduanya menulis baris audit terpisah.
     *
     * @var array<string, string>
     */
    private const AUDIT_EVENTS = [
        'submitted' => 'shm.application.submitted',
        'approved' => 'shm.application.approved',
        'rejected' => 'shm.application.rejected',
        'disbursed' => 'shm.disbursed',
    ];

    private const DEFAULT_AUDIT_EVENT = 'shm.application.status_changed';

    /**
     * Pindahkan pengajuan ke status tujuan yang diizinkan.
     *
     * @throws ValidationException bila transisi tidak diizinkan
     */
    public function transition(
        ShmApplication $application,
        ShmApplicationStatus $target,
        int $actorId,
        ?string $notes = null,
    ): ShmApplication {
        return DbTransaction::run(function () use ($application, $target, $actorId, $notes): ShmApplication {
            $locked = ShmApplication::query()->lockForUpdate()->findOrFail($application->id);

            $current = $locked->status;

            if (! $current->canTransitionTo($target)) {
                throw ValidationException::withMessages([
                    'status' => sprintf(
                        'Transisi dari "%s" ke "%s" tidak diizinkan.',
                        $current->label(),
                        $target->label(),
                    ),
                ]);
            }

            // Business invariant Ticket 51: pengajuan tidak boleh masuk tahap
            // siap pencairan / dicairkan selama checklist dokumen belum lengkap.
            // Dibaca dari baris yang sudah dikunci (`$locked`) supaya perubahan
            // dokumen oleh pengguna lain tetap terdeteksi, bukan dari props UI.
            $this->guardDocumentCompleteness($locked, $target);

            $locked->status = $target;
            $this->stampTimestamp($locked, $target);

            if ($notes !== null && $notes !== '') {
                $locked->notes = $notes;
            }

            $locked->save();

            AuditLogger::log(
                self::auditEventFor($target),
                ShmApplication::class,
                $locked->id,
                $locked->application_number,
                sprintf('Status pengajuan diubah dari %s ke %s.', $current->label(), $target->label()),
                [
                    'from' => $current->value,
                    'to' => $target->value,
                    'actor_id' => $actorId,
                ],
            );

            return $locked;
        });
    }

    /**
     * Marketing mengajukan pengajuan yang sebelumnya berstatus draft.
     */
    public function submit(ShmApplication $application, int $actorId): ShmApplication
    {
        return $this->transition($application, ShmApplicationStatus::Submitted, $actorId);
    }

    /**
     * Keputusan approve pada tahap approval.
     *
     * Catatan approval disimpan pada baris approval terpisah, bukan pada
     * kolom status aplikasi.
     */
    public function approve(ShmApplication $application, int $actorId, ?string $notes = null): ShmApplication
    {
        return DbTransaction::run(function () use ($application, $actorId, $notes): ShmApplication {
            $updated = $this->transition($application, ShmApplicationStatus::Approved, $actorId);

            ShmApproval::query()->updateOrCreate(
                ['shm_application_id' => $updated->id],
                [
                    'decided_by' => $actorId,
                    'status' => ShmApproval::STATUS_APPROVED,
                    'decided_at' => now(),
                    'notes' => $notes,
                ],
            );

            return $updated;
        });
    }

    /**
     * Keputusan reject pada tahap approval.
     */
    public function reject(ShmApplication $application, int $actorId, ?string $notes = null): ShmApplication
    {
        return DbTransaction::run(function () use ($application, $actorId, $notes): ShmApplication {
            $updated = $this->transition($application, ShmApplicationStatus::Rejected, $actorId, $notes);

            ShmApproval::query()->updateOrCreate(
                ['shm_application_id' => $updated->id],
                [
                    'decided_by' => $actorId,
                    'status' => ShmApproval::STATUS_REJECTED,
                    'decided_at' => now(),
                    'notes' => $notes,
                ],
            );

            return $updated;
        });
    }

    // -----------------------------------------------------------------
    // Workflow credit checking (Ticket 47)
    //
    // Rantai transisi: Pending -> InProgress -> Completed.
    //
    // Pending -> Completed SENGAJA ditolak supaya hasil tidak pernah tercatat
    // tanpa tahap pengerjaan (selalu ada siapa mulai dan kapan). Completed
    // bersifat terminal: reopen belum diputuskan KSP, sehingga tidak tersedia.
    //
    // Semua metode di bawah HANYA mengubah data credit checking. Status
    // pengajuan SHM tidak pernah ikut berubah: keputusan setuju/tolak tetap
    // melalui workflow Approval.
    // -----------------------------------------------------------------

    /**
     * Mulai pengerjaan credit checking sebuah pengajuan.
     *
     * Baris credit checking dibuat eksplisit di sini, bukan otomatis saat
     * pengajuan dibuat. Satu pengajuan hanya boleh memiliki satu baris
     * (dijaga indeks unik shm_application_id).
     *
     * @throws ValidationException bila checking sudah berjalan atau sudah selesai
     */
    public function startCreditCheck(ShmApplication $application, int $actorId): ShmCreditCheck
    {
        return DbTransaction::run(function () use ($application, $actorId): ShmCreditCheck {
            $creditCheck = ShmCreditCheck::query()
                ->where('shm_application_id', $application->id)
                ->lockForUpdate()
                ->first();

            if ($creditCheck === null) {
                $creditCheck = new ShmCreditCheck([
                    'shm_application_id' => $application->id,
                    'status' => ShmCreditCheckStatus::Pending->value,
                ]);
            }

            $current = $creditCheck->status;

            $this->assertCreditCheckTransition($current, ShmCreditCheckStatus::InProgress);

            $creditCheck->fill([
                'status' => ShmCreditCheckStatus::InProgress->value,
                'filled_by' => $actorId,
                // Waktu sistem, bukan input pengguna.
                'started_at' => now(),
            ]);
            $creditCheck->save();

            AuditLogger::log(
                'shm.credit_check.started',
                ShmCreditCheck::class,
                $creditCheck->id,
                $application->application_number,
                'Credit checking pengajuan dimulai.',
                [
                    'shm_application_id' => $application->id,
                    'from' => $current->value,
                    'to' => ShmCreditCheckStatus::InProgress->value,
                    'actor_id' => $actorId,
                ],
            );

            return $creditCheck;
        });
    }

    /**
     * Perbarui hasil sementara credit checking.
     *
     * Hanya boleh saat pengerjaan sedang berjalan. Perubahan outcome sebelum
     * selesai tetap tercatat sebagai perubahan biasa, bukan sebagai hasil final.
     *
     * @throws ValidationException bila checking belum dimulai atau sudah selesai
     */
    public function updateCreditCheck(
        ShmCreditCheck $creditCheck,
        ?ShmCreditCheckOutcome $outcome,
        ?string $result,
        ?string $notes,
        int $actorId,
    ): ShmCreditCheck {
        return DbTransaction::run(function () use ($creditCheck, $outcome, $result, $notes, $actorId): ShmCreditCheck {
            $locked = ShmCreditCheck::query()->lockForUpdate()->findOrFail($creditCheck->id);

            if (! $locked->isInProgress()) {
                throw ValidationException::withMessages([
                    'status' => sprintf(
                        'Hasil credit checking hanya dapat diubah saat pengerjaan berjalan. Status saat ini: %s.',
                        $locked->statusLabel(),
                    ),
                ]);
            }

            $locked->fill([
                'outcome' => $outcome?->value,
                'result' => $result,
                'notes' => $notes,
                // Petugas terakhir yang memperbarui.
                'filled_by' => $actorId,
            ]);
            $locked->save();

            AuditLogger::log(
                'shm.credit_check.updated',
                ShmCreditCheck::class,
                $locked->id,
                $locked->application?->application_number,
                'Hasil sementara credit checking diperbarui.',
                [
                    'shm_application_id' => $locked->shm_application_id,
                    'outcome' => $outcome?->value,
                    'actor_id' => $actorId,
                ],
            );

            return $locked;
        });
    }

    /**
     * Selesaikan credit checking.
     *
     * WAJIB melalui tahap InProgress. Status pengajuan SHM TIDAK ikut berubah:
     * menyelesaikan credit checking tidak menyetujui atau menolak pengajuan.
     *
     * @throws ValidationException bila belum dimulai, sudah selesai, atau waktu tidak konsisten
     */
    public function completeCreditCheck(
        ShmCreditCheck $creditCheck,
        ShmCreditCheckOutcome $outcome,
        ?string $result,
        ?string $notes,
        int $actorId,
    ): ShmCreditCheck {
        return DbTransaction::run(function () use ($creditCheck, $outcome, $result, $notes, $actorId): ShmCreditCheck {
            $locked = ShmCreditCheck::query()->lockForUpdate()->findOrFail($creditCheck->id);

            $this->assertCreditCheckTransition($locked->status, ShmCreditCheckStatus::Completed);

            if ($locked->started_at === null) {
                throw ValidationException::withMessages([
                    'status' => 'Credit checking tidak dapat diselesaikan tanpa waktu mulai yang tercatat.',
                ]);
            }

            $completedAt = now();

            if ($completedAt->lt($locked->started_at)) {
                throw ValidationException::withMessages([
                    'status' => 'Waktu penyelesaian credit checking tidak boleh lebih awal dari waktu mulai.',
                ]);
            }

            $locked->fill([
                'status' => ShmCreditCheckStatus::Completed->value,
                'outcome' => $outcome->value,
                'result' => $result,
                'notes' => $notes,
                'filled_by' => $actorId,
                'checked_at' => $completedAt,
            ]);
            $locked->save();

            AuditLogger::log(
                'shm.credit_check.completed',
                ShmCreditCheck::class,
                $locked->id,
                $locked->application?->application_number,
                'Credit checking pengajuan diselesaikan.',
                [
                    'shm_application_id' => $locked->shm_application_id,
                    'outcome' => $outcome->value,
                    'actor_id' => $actorId,
                ],
            );

            return $locked;
        });
    }

    /**
     * Business invariant Ticket 51: kelengkapan dokumen adalah prasyarat
     * pencairan.
     *
     * Transisi menuju `ready_for_disbursement` atau `disbursed` ditolak selama
     * `documentsComplete()` bernilai false. Semantik kelengkapan tetap milik
     * model (`ShmApplication::documentsComplete()`), bukan aturan baru:
     *
     * - `complete`/`not_applicable` -> lengkap;
     * - `pending` -> belum lengkap;
     * - belum ada item dokumen -> belum lengkap (behavior existing).
     *
     * Gate ini berada di dalam transaksi/lock sehingga keputusan diambil dari
     * state database terbaru, bukan dari state UI yang mungkin sudah basi.
     *
     * @throws ValidationException bila dokumen belum lengkap
     */
    private function guardDocumentCompleteness(
        ShmApplication $application,
        ShmApplicationStatus $target,
    ): void {
        if (! in_array($target, [ShmApplicationStatus::ReadyForDisbursement, ShmApplicationStatus::Disbursed], true)) {
            return;
        }

        if ($application->documentsComplete()) {
            return;
        }

        // Ringkasan existing dipakai apa adanya supaya tidak ada perhitungan
        // kedua yang bisa menyimpang dari `documentsComplete()`.
        $summary = $application->documentsSummary();

        $message = $summary['total'] === 0
            ? 'Pengajuan belum dapat diproses ke tahap pencairan karena belum ada item dokumen yang dicatat.'
            : sprintf(
                'Pengajuan belum dapat diproses ke tahap pencairan karena masih terdapat %d dokumen yang berstatus Pending.',
                $summary['pending'],
            );

        throw ValidationException::withMessages(['status' => $message]);
    }

    /**
     * Pastikan transisi status credit checking diizinkan.
     *
     * @throws ValidationException bila transisi tidak ada pada peta transisi
     */
    private function assertCreditCheckTransition(
        ShmCreditCheckStatus $current,
        ShmCreditCheckStatus $target,
    ): void {
        if ($current->canTransitionTo($target)) {
            return;
        }

        throw ValidationException::withMessages([
            'status' => sprintf(
                'Transisi credit checking dari "%s" ke "%s" tidak diizinkan.',
                $current->label(),
                $target->label(),
            ),
        ]);
    }

    /**
     * Buat atau perbarui memorandum pengajuan.
     *
     * Tidak ada perhitungan finansial apa pun yang dikunci di sini.
     *
     * Relasi memorandum/pengajuan tetap SATU baris per pengajuan (perilaku
     * Ticket 44 yang sudah ada melalui firstOrNew): kategori (Ticket 49)
     * hanyalah atribut naskah yang diperbarui, bukan pembentuk baris kedua.
     *
     * @param  string|null  $type  Kategori memorandum (shm_shgb|buyback) atau
     *                             null bila klien tidak mengirimkannya (perilaku lama).
     */
    public function recordMemorandum(
        ShmApplication $application,
        string $status,
        ?string $notes,
        int $actorId,
        ?string $type = null,
    ): ShmMemorandum {
        return DbTransaction::run(function () use ($application, $status, $notes, $actorId, $type): ShmMemorandum {
            $memorandum = ShmMemorandum::query()->firstOrNew(['shm_application_id' => $application->id]);

            $memorandum->fill([
                'status' => $status,
                'notes' => $notes,
            ]);

            // Kategori hanya diisi bila klien mengirimkannya; baris lama tanpa
            // kategori tidak dikarang nilainya secara diam-diam.
            if ($type !== null) {
                $memorandum->type = $type;
            }

            // created_by hanya diisi saat baris pertama dibuat.
            if (! $memorandum->exists) {
                $memorandum->created_by = $actorId;
            }

            $memorandum->submitted_at = $status === ShmMemorandum::STATUS_SUBMITTED ? now() : null;
            $memorandum->save();

            AuditLogger::log(
                'shm.memorandum.updated',
                ShmMemorandum::class,
                $memorandum->id,
                $application->application_number,
                'Memorandum pengajuan diperbarui.',
                [
                    'type' => $memorandum->type,
                    'status' => $status,
                    'shm_application_id' => $application->id,
                    'actor_id' => $actorId,
                ],
            );

            return $memorandum;
        });
    }

    /**
     * Tambahkan atau perbarui item checklist dokumen.
     *
     * Tidak ada daftar dokumen legal yang dikunci, dan tidak ada upload berkas.
     */
    public function recordDocument(
        ShmApplication $application,
        string $documentName,
        string $status,
        ?string $notes,
        int $actorId,
        ?int $documentId = null,
    ): ShmDocument {
        return DbTransaction::run(function () use ($application, $documentName, $status, $notes, $actorId, $documentId): ShmDocument {
            if ($documentId !== null) {
                $document = ShmDocument::query()
                    ->where('shm_application_id', $application->id)
                    ->findOrFail($documentId);
            } else {
                // Tanpa id, item dengan nama yang sama diperbarui agar checklist
                // tidak menumpuk baris duplikat setiap kali form dikirim ulang.
                $document = ShmDocument::query()
                    ->where('shm_application_id', $application->id)
                    ->where('document_name', $documentName)
                    ->first() ?? new ShmDocument(['shm_application_id' => $application->id]);
            }

            $document->fill([
                'document_name' => $documentName,
                'status' => $status,
                'notes' => $notes,
            ]);

            if ($status !== ShmDocument::STATUS_PENDING) {
                $document->checked_by = $actorId;
                $document->checked_at = now();
            } else {
                $document->checked_by = null;
                $document->checked_at = null;
            }

            $document->save();

            AuditLogger::log(
                'shm.document.updated',
                ShmDocument::class,
                $document->id,
                $application->application_number,
                'Kelengkapan dokumen pengajuan diperbarui.',
                [
                    'document_name' => $documentName,
                    'status' => $status,
                    'shm_application_id' => $application->id,
                    'actor_id' => $actorId,
                ],
            );

            return $document;
        });
    }

    // -----------------------------------------------------------------
    // Transisi antar tahap kerja (Ticket 45)
    //
    // Ticket 44 sudah mendefinisikan rantai status, tetapi tidak menyediakan
    // cara untuk melewati tahap credit_checking, memorandum, approval,
    // dan document_check. Akibatnya pengajuan tidak pernah bisa mencapai
    // status approved. Metode di bawah menutup celah tersebut TANPA mengubah
    // peta transisi yang sudah ada.
    // -----------------------------------------------------------------

    /**
     * Submitted ke credit checking.
     */
    public function startCreditChecking(ShmApplication $application, int $actorId): ShmApplication
    {
        return $this->transition($application, ShmApplicationStatus::CreditChecking, $actorId);
    }

    /**
     * Credit checking ke memorandum.
     */
    public function startMemorandum(ShmApplication $application, int $actorId): ShmApplication
    {
        return $this->transition($application, ShmApplicationStatus::Memorandum, $actorId);
    }

    /**
     * Memorandum ke tahap approval.
     */
    public function startApproval(ShmApplication $application, int $actorId): ShmApplication
    {
        return $this->transition($application, ShmApplicationStatus::Approval, $actorId);
    }

    /**
     * Approved ke kelengkapan dokumen.
     */
    public function startDocumentCheck(ShmApplication $application, int $actorId): ShmApplication
    {
        return $this->transition($application, ShmApplicationStatus::DocumentCheck, $actorId);
    }

    /**
     * Kelengkapan dokumen ke siap pencairan.
     */
    public function markReadyForDisbursement(ShmApplication $application, int $actorId): ShmApplication
    {
        return $this->transition($application, ShmApplicationStatus::ReadyForDisbursement, $actorId);
    }

    /**
     * Pengajuan yang ditolak dikembalikan ke draft supaya bisa diperbaiki.
     *
     * Transisi rejected ke draft sudah ada di peta transisi status Ticket 44
     * (ShmApplicationStatus::Rejected). Metode ini hanya membuka jalurnya.
     */
    public function returnToDraft(ShmApplication $application, int $actorId): ShmApplication
    {
        return $this->transition($application, ShmApplicationStatus::Draft, $actorId);
    }

    /**
     * Pastikan pengajuan sedang berada pada tahap yang dibutuhkan untuk aksi
     * data (credit checking, memorandum, dokumen, objek).
     *
     * Ini menutup celah Ticket 44 di mana data tahap bisa diisi pada status
     * apa pun. Frontend menyembunyikan tombol, tetapi penjaga sesungguhnya
     * ada di sini agar request langsung tetap ditolak.
     *
     * @param  array<int, ShmApplicationStatus>  $requiredStatuses
     *
     * @throws ValidationException bila tahap pengajuan tidak sesuai
     */
    public function guardStage(ShmApplication $application, array $requiredStatuses, string $actionLabel): void
    {
        $current = $application->status;

        if (in_array($current, $requiredStatuses, true)) {
            return;
        }

        $expected = collect($requiredStatuses)
            ->map(fn (ShmApplicationStatus $status): string => $status->label())
            ->implode(' atau ');

        throw ValidationException::withMessages([
            'status' => sprintf(
                '%s hanya dapat dilakukan saat pengajuan berstatus %s. Status saat ini: %s.',
                $actionLabel,
                $expected,
                $current->label(),
            ),
        ]);
    }

    /**
     * Tandai pengajuan sudah dicairkan.
     *
     * Yang dicatat di sini HANYA fakta pencairan administratif. Tidak ada
     * posting jurnal otomatis, tidak ada penghitungan potongan atau pelunasan,
     * dan tidak ada transaksi finansial apa pun. Seluruh aturan keuangan SHM
     * masih PENDING BUSINESS DECISION.
     */
    public function markDisbursed(ShmApplication $application, int $actorId, ?string $notes = null): ShmApplication
    {
        return $this->transition($application, ShmApplicationStatus::Disbursed, $actorId, $notes);
    }

    /**
     * Tandai pengajuan selesai.
     *
     * Tidak ada collection engine pada ticket ini. Payment history hanya
     * direferensikan ke modul pembayaran existing bila sudah terhubung.
     */
    public function markCompleted(ShmApplication $application, int $actorId): ShmApplication
    {
        return $this->transition($application, ShmApplicationStatus::Completed, $actorId);
    }

    /**
     * Batalkan pengajuan.
     *
     * Pembatalan hanya diizinkan dari tahap awal, sebelum approval. Setelah
     * disetujui tidak ada transisi pembatalan yang diizinkan karena aturan
     * pembatalan pasca-approval belum diputuskan KSP.
     */
    public function cancel(ShmApplication $application, int $actorId, ?string $notes = null): ShmApplication
    {
        return $this->transition($application, ShmApplicationStatus::Cancelled, $actorId, $notes);
    }

    /**
     * Reopen untuk revalidasi kelengkapan dokumen (Ticket 52).
     *
     * Jika pengajuan berada di `ready_for_disbursement` namun checklist dokumen
     * sudah tidak lengkap (karena dokumen berubah jadi `pending` secara manual),
     * pengajuan harus dikembalikan ke `document_check` supaya tidak lagi
     * dianggap siap dicairkan.
     *
     * - Dengan mengandaikan dokumen per application (satu pengajuan = satu
     *   pengambilan dokumen). Reopen HANYA jika dokumen tidak lengkap.
     * - JANGAN memperkenalkan status baru; gunakan `document_check` sebagai
     *   state revalisasi. Transisi ini TIDAK melalui peta transisi biasa karena
     *   `ready_for_disbursement -> document_check` tidak ada di status machine.
     *   Sebesar ini dikontrol melalui method ini supaya tidak ada transisi
     *   ilegal yang ikutan terbuka.
     * - Disbursed dan completed TIDAK boleh rollback; reopen hanya dari
     *   ready_for_disbursement.
     *
     * @throws ValidationException bila bukan dari ready_for_disbursement,
     *                             bila dokumen masih lengkap, atau bila aplikasi sudah disbursed/selesai.
     */
    public function reopenDocumentCheck(ShmApplication $application, int $actorId): ShmApplication
    {
        return DbTransaction::run(function () use ($application, $actorId): ShmApplication {
            $locked = ShmApplication::query()->lockForUpdate()->findOrFail($application->id);
            $current = $locked->status;

            // Hanya dari ready_for_disbursement.
            if ($current !== ShmApplicationStatus::ReadyForDisbursement) {
                throw ValidationException::withMessages([
                    'status' => sprintf(
                        'Reopen hanya dapat dilakukan dari pengajuan yang berstatus Siap Pencairan. Status saat ini: %s.',
                        $current->label(),
                    ),
                ]);
            }

            // Dokumen TIDAK boleh lengkap supaya reopen bermakna.
            if ($locked->documentsComplete()) {
                throw ValidationException::withMessages([
                    'status' => 'Pengajuan tidak perlu dibuka kembali karena dokumen masih lengkap.',
                ]);
            }

            $locked->status = ShmApplicationStatus::DocumentCheck;

            AuditLogger::log(
                'shm.application.status_changed',
                ShmApplication::class,
                $locked->id,
                $locked->application_number,
                sprintf('Status pengajuan diubah dari %s ke %s karena kelengkapan dokumen tidak terpenuhi.', $current->label(), ShmApplicationStatus::DocumentCheck->label()),
                [
                    'from' => $current->value,
                    'to' => ShmApplicationStatus::DocumentCheck->value,
                    'actor_id' => $actorId,
                ],
            );

            $locked->save();

            return $locked;
        });
    }

    /**
     * Isi timestamp workflow sesuai status tujuan.
     */
    private function stampTimestamp(ShmApplication $application, ShmApplicationStatus $target): void
    {
        $now = Carbon::now();

        match ($target) {
            ShmApplicationStatus::Submitted => $application->submitted_at = $now,
            ShmApplicationStatus::Approved => $application->approved_at = $now,
            ShmApplicationStatus::Rejected => $application->rejected_at = $now,
            ShmApplicationStatus::Disbursed => $application->disbursed_at = $now,
            ShmApplicationStatus::Completed => $application->completed_at = $now,
            default => null,
        };
    }

    /**
     * Nama event audit untuk status tujuan.
     */
    private function auditEventFor(ShmApplicationStatus $target): string
    {
        return self::AUDIT_EVENTS[$target->value] ?? self::DEFAULT_AUDIT_EVENT;
    }
}
