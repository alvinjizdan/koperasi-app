<?php

namespace App\Services\Shm;

use App\Models\ShmCreditCheckException;
use App\Models\ShmMemorandum;
use App\Models\ShmMemorandumApprovalHistory;
use App\Models\ShmMemorandumSubmission;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Service untuk menangani workflow persetujuan (approval) Memorandum.
 *
 * Mengelola transisi state:
 * DRAFT -> (submit) -> SUBMITTED -> (startReview) -> UNDER_REVIEW -> (approve) -> APPROVED
 *                                                                 -> (reject)  -> DRAFT
 * DRAFT -> (resubmit) -> SUBMITTED
 *
 * Menjamin:
 * 1. Pessimistic concurrency locking (lockForUpdate).
 * 2. Pencatatan audit trail permanen ke shm_memorandum_approval_histories.
 * 3. Validasi bisnis sebelum transisi (misal: 5 dokumen lengkap pada buyback).
 * 4. Isolasi ketat dari workflow pengajuan marketing & akuntansi.
 */
class ShmMemorandumApprovalService
{
    public function __construct(
        private readonly ?ShmCreditCheckExceptionService $exceptionService = null,
    ) {}

    /**
     * Mengajukan memorandum dari draft ke submitted.
     *
     * @throws ValidationException
     */
    public function submit(ShmMemorandum $memorandum, User $user, ?string $notes = null): ShmMemorandum
    {
        return DB::transaction(function () use ($memorandum, $user, $notes) {
            /** @var ShmMemorandum $locked */
            $locked = ShmMemorandum::query()->where('id', $memorandum->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== ShmMemorandum::STATUS_DRAFT) {
                throw ValidationException::withMessages([
                    'status' => 'Memorandum hanya dapat diajukan jika berstatus Draft.',
                ]);
            }

            // Validasi prasyarat Buyback: 5 dokumen legalitas harus lengkap
            if ($locked->type === ShmMemorandum::TYPE_BUYBACK && ! $locked->isBuybackDocumentsComplete()) {
                throw ValidationException::withMessages([
                    'documents' => 'Status tidak dapat diubah menjadi Diajukan (Submitted) karena kelima dokumen legalitas Buyback belum lengkap.',
                ]);
            }

            // Ticket CC-05: Penegakan Workflow Credit Checking & Pengecualian
            $application = $locked->application;
            if (! $application) {
                throw ValidationException::withMessages([
                    'application' => 'Pengajuan sumber untuk memorandum ini tidak ditemukan.',
                ]);
            }

            $creditCheck = $application->latestCreditCheck;

            // 1. Cek Completion Bypass jika credit checking belum selesai
            $consumedException = null;
            $exceptionService = $this->exceptionService ?? app(ShmCreditCheckExceptionService::class);

            if (! $creditCheck || ! $creditCheck->isCompleted()) {
                $hasBypass = ShmCreditCheckException::query()
                    ->where('shm_application_id', $application->id)
                    ->where('exception_type', ShmCreditCheckException::TYPE_COMPLETION_BYPASS)
                    ->where('status', ShmCreditCheckException::STATUS_AUTHORIZED)
                    ->exists();

                if (! $hasBypass) {
                    throw ValidationException::withMessages([
                        'credit_checking' => 'Memorandum tidak dapat diajukan karena Credit Checking belum selesai (Completed) dan tidak memiliki dispensasi Completion Bypass.',
                    ]);
                }

                $consumedException = $exceptionService->consumeException(
                    $application,
                    ShmCreditCheckException::TYPE_COMPLETION_BYPASS,
                    $locked
                );
            } else {
                // Evaluasi Outcome FAIL / REVIEW bila record historis memilikinya
                $outcome = $creditCheck->outcome?->value;

                if ($outcome === 'fail') {
                    $hasOverride = ShmCreditCheckException::query()
                        ->where('shm_application_id', $application->id)
                        ->where('exception_type', ShmCreditCheckException::TYPE_FAIL_OVERRIDE)
                        ->where('status', ShmCreditCheckException::STATUS_AUTHORIZED)
                        ->exists();

                    if (! $hasOverride) {
                        throw ValidationException::withMessages([
                            'credit_checking' => 'Hasil Credit Checking adalah FAIL. Memorandum tidak dapat diajukan tanpa dispensasi FAIL Override dari Direktur Utama.',
                        ]);
                    }

                    $consumedException = $exceptionService->consumeException(
                        $application,
                        ShmCreditCheckException::TYPE_FAIL_OVERRIDE,
                        $locked
                    );
                } elseif ($outcome === 'review') {
                    $mitigationToCheck = $notes ?? $locked->notes ?? '';
                    if (trim($mitigationToCheck) === '') {
                        throw ValidationException::withMessages([
                            'notes' => 'Hasil Credit Checking adalah REVIEW. Catatan analisis mitigasi risiko wajib diisi sebelum memorandum dapat diajukan.',
                        ]);
                    }
                }
            }

            // 3. Rakit Snapshot DTO Credit Checking
            $snapshotPayload = [
                'version' => 1,
                'snapshot_captured_at' => now()->toIso8601String(),
                'source_credit_check_id' => $creditCheck?->id,
                'credit_check' => $creditCheck && $creditCheck->isCompleted() ? [
                    'status' => $creditCheck->status->value,
                    'outcome' => $creditCheck->outcome?->value,
                    'outcome_label' => $creditCheck->outcomeLabel(),
                    'result_summary' => $creditCheck->result,
                    'checker_id' => $creditCheck->filled_by,
                    'checker_name' => $creditCheck->checker?->name,
                    'started_at' => $creditCheck->started_at?->toIso8601String(),
                    'completed_at' => $creditCheck->checked_at?->toIso8601String(),
                ] : null,
                'exception_authorization' => $consumedException ? [
                    'is_excepted' => true,
                    'exception_number' => $consumedException->exception_number,
                    'exception_type' => $consumedException->exception_type,
                    'authorized_by_id' => $consumedException->authorized_by,
                    'authorized_by_name' => $consumedException->authorized_by_name,
                    'authorized_at' => $consumedException->authorized_at->toIso8601String(),
                    'official_disclosure_summary' => $consumedException->official_disclosure_summary,
                ] : [
                    'is_excepted' => false,
                ],
            ];

            // 4. Update Parent Memorandum
            $isResubmission = $locked->approvalHistories()->where('action', ShmMemorandumApprovalHistory::ACTION_REJECTED)->exists();
            $action = $isResubmission ? ShmMemorandumApprovalHistory::ACTION_RESUBMITTED : ShmMemorandumApprovalHistory::ACTION_SUBMITTED;

            $fromStatus = $locked->status;
            $toStatus = ShmMemorandum::STATUS_SUBMITTED;

            $locked->status = $toStatus;
            $locked->submitted_by = $user->id;
            $locked->submitted_at = now();

            // First submission freeze anchor: hanya diisi satu kali
            if ($locked->first_submitted_at === null) {
                $locked->first_submitted_at = now();
            }

            if ($creditCheck) {
                $locked->shm_credit_check_id = $creditCheck->id;
            }
            $locked->credit_check_snapshot = $snapshotPayload;

            if ($notes !== null && trim($notes) !== '') {
                $locked->notes = $notes;
            }
            $locked->save();

            // 5. Rakit dan Simpan Immutable Submission Revision Artifact (Option A)
            $nextRevisionNumber = ($locked->submissions()->max('revision_number') ?? 0) + 1;

            $borrowerSnapshot = [
                'customer_id' => $application->customer?->id,
                'customer_number' => $application->customer?->customer_number,
                'name' => $application->customer?->name,
                'nik' => $application->customer?->nik,
                'phone' => $application->customer?->phone,
            ];

            $collateralSnapshot = $application->properties->map(fn ($p) => [
                'id' => $p->id,
                'certificate_number' => $p->certificate_number,
                'owner_name' => $p->owner_name,
                'surface_area' => $p->surface_area,
                'location' => $p->location,
            ])->values()->all();

            // Ambil buyback documents baik yang melekat di memorandum maupun di application
            $buybackDocsColl = $locked->buybackDocuments()->exists()
                ? $locked->buybackDocuments()->get()
                : ($application ? $application->buybackDocuments()->get() : collect());

            $buybackDocsSnapshot = $buybackDocsColl->map(fn ($d) => [
                'id' => $d->id,
                'document_type' => $d->document_type,
                'file_name' => $d->file_name,
                'file_path' => $d->file_path,
                'file_size' => $d->file_size,
                'mime_type' => $d->mime_type,
                'uploaded_at' => $d->created_at?->toIso8601String(),
            ])->values()->all();

            $submissionArtifactData = [
                'shm_memorandum_id' => $locked->id,
                'revision_number' => $nextRevisionNumber,
                'memorandum_type' => $locked->type ?? ShmMemorandum::TYPE_SHM_SHGB,
                'submitted_by' => $user->id,
                'submitted_by_name' => $user->name,
                'submitted_at' => now()->toIso8601String(),
                'narrative_notes' => $locked->notes,
                'risk_mitigation_notes' => $creditCheck?->outcome?->value === 'review' ? ($notes ?? $locked->notes) : null,
                'shgb_start_date' => $locked->shgb_start_date,
                'shgb_end_date' => $locked->shgb_end_date,
                'borrower_snapshot' => $borrowerSnapshot,
                'collateral_snapshot' => $collateralSnapshot,
                'buyback_documents' => $buybackDocsSnapshot,
                'credit_check_snapshot' => $snapshotPayload,
                'exception_snapshot' => $consumedException ? $snapshotPayload['exception_authorization'] : null,
                'template_version' => 'v1.0',
            ];

            $submissionArtifactData['content_hash'] = ShmMemorandumSubmission::computeContentHash($submissionArtifactData);

            ShmMemorandumSubmission::create($submissionArtifactData);

            // 6. Catat Approval History
            ShmMemorandumApprovalHistory::create([
                'shm_memorandum_id' => $locked->id,
                'action' => $action,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'performed_by' => $user->id,
                'notes' => $notes,
            ]);

            return $locked;
        });
    }

    /**
     * Memulai pemeriksaan/review memorandum (SUBMITTED -> UNDER_REVIEW).
     *
     * @throws ValidationException
     */
    public function startReview(ShmMemorandum $memorandum, User $user, ?string $notes = null): ShmMemorandum
    {
        return DB::transaction(function () use ($memorandum, $user, $notes) {
            /** @var ShmMemorandum $locked */
            $locked = ShmMemorandum::query()->where('id', $memorandum->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== ShmMemorandum::STATUS_SUBMITTED) {
                throw ValidationException::withMessages([
                    'status' => 'Review hanya dapat dimulai jika memorandum berstatus Diajukan (Submitted).',
                ]);
            }

            $fromStatus = $locked->status;
            $toStatus = ShmMemorandum::STATUS_UNDER_REVIEW;

            $locked->status = $toStatus;
            $locked->reviewed_by = $user->id;
            $locked->reviewed_at = now();
            $locked->save();

            ShmMemorandumApprovalHistory::create([
                'shm_memorandum_id' => $locked->id,
                'action' => ShmMemorandumApprovalHistory::ACTION_REVIEW_STARTED,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'performed_by' => $user->id,
                'notes' => $notes,
            ]);

            return $locked;
        });
    }

    /**
     * Menyetujui memorandum (UNDER_REVIEW -> APPROVED).
     *
     * @throws ValidationException
     */
    public function approve(ShmMemorandum $memorandum, User $user, ?string $notes = null): ShmMemorandum
    {
        return DB::transaction(function () use ($memorandum, $user, $notes) {
            /** @var ShmMemorandum $locked */
            $locked = ShmMemorandum::query()->where('id', $memorandum->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== ShmMemorandum::STATUS_UNDER_REVIEW) {
                throw ValidationException::withMessages([
                    'status' => 'Memorandum hanya dapat disetujui jika sedang berada dalam tahap review (Under Review).',
                ]);
            }

            $fromStatus = $locked->status;
            $toStatus = ShmMemorandum::STATUS_APPROVED;

            $locked->status = $toStatus;
            $locked->approved_by = $user->id;
            $locked->approved_at = now();
            $locked->save();

            ShmMemorandumApprovalHistory::create([
                'shm_memorandum_id' => $locked->id,
                'action' => ShmMemorandumApprovalHistory::ACTION_APPROVED,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'performed_by' => $user->id,
                'notes' => $notes,
            ]);

            return $locked;
        });
    }

    /**
     * Menolak memorandum (UNDER_REVIEW -> DRAFT).
     *
     * Alasan penolakan wajib diisi dan disimpan permanen.
     *
     * @throws ValidationException
     */
    public function reject(ShmMemorandum $memorandum, User $user, string $reason): ShmMemorandum
    {
        $trimmedReason = trim($reason);
        if ($trimmedReason === '') {
            throw ValidationException::withMessages([
                'reason' => 'Alasan penolakan wajib diisi.',
            ]);
        }

        return DB::transaction(function () use ($memorandum, $user, $trimmedReason) {
            /** @var ShmMemorandum $locked */
            $locked = ShmMemorandum::query()->where('id', $memorandum->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== ShmMemorandum::STATUS_UNDER_REVIEW) {
                throw ValidationException::withMessages([
                    'status' => 'Memorandum hanya dapat ditolak jika sedang berada dalam tahap review (Under Review).',
                ]);
            }

            $fromStatus = $locked->status;
            $toStatus = ShmMemorandum::STATUS_DRAFT;

            $locked->status = $toStatus;
            $locked->rejected_at = now();
            $locked->rejection_notes = $trimmedReason;
            // Reset metadata submit & review agar siklus baru bersih di level aktif
            $locked->submitted_at = null;
            $locked->submitted_by = null;
            $locked->reviewed_at = null;
            $locked->reviewed_by = null;
            $locked->save();

            ShmMemorandumApprovalHistory::create([
                'shm_memorandum_id' => $locked->id,
                'action' => ShmMemorandumApprovalHistory::ACTION_REJECTED,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'performed_by' => $user->id,
                'notes' => $trimmedReason,
            ]);

            return $locked;
        });
    }
}
