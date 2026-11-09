<?php

namespace App\Services\Shm;

use App\Models\ShmMemorandum;
use App\Models\ShmMemorandumApprovalHistory;
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

            $isResubmission = $locked->approvalHistories()->where('action', ShmMemorandumApprovalHistory::ACTION_REJECTED)->exists();
            $action = $isResubmission ? ShmMemorandumApprovalHistory::ACTION_RESUBMITTED : ShmMemorandumApprovalHistory::ACTION_SUBMITTED;

            $fromStatus = $locked->status;
            $toStatus = ShmMemorandum::STATUS_SUBMITTED;

            $locked->status = $toStatus;
            $locked->submitted_by = $user->id;
            $locked->submitted_at = now();
            if ($notes !== null && trim($notes) !== '') {
                $locked->notes = $notes;
            }
            $locked->save();

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
