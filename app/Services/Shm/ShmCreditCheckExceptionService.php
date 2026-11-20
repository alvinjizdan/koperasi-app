<?php

namespace App\Services\Shm;

use App\Models\ShmApplication;
use App\Models\ShmCreditCheckException;
use App\Models\ShmMemorandum;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\DbTransaction;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Service untuk mengelola lifecycle dispensasi & pengecualian Credit Checking (Ticket CC-05).
 *
 * Mengatur:
 * 1. Otorisasi resmi (FAIL Override & Completion Bypass) oleh Direktur Utama.
 * 2. Konsumsi atomik saat memorandum disubmit.
 * 3. Pembatalan (Revocation) sebelum konsumsi.
 */
class ShmCreditCheckExceptionService
{
    /**
     * Berikan otorisasi pengecualian baru oleh Direktur Utama.
     *
     * Invariant CC-09: Tipe FAIL Override telah dihapus dari active workflow.
     *
     * @throws ValidationException
     */
    public function authorizeException(
        ShmApplication $application,
        string $exceptionType,
        User $authorizer,
        string $internalJustification,
        string $officialDisclosureSummary,
        string $blockingCondition,
    ): ShmCreditCheckException {
        if ($exceptionType === ShmCreditCheckException::TYPE_FAIL_OVERRIDE) {
            abort_unless(
                Gate::forUser($authorizer)->allows('shm.credit_checking.override_fail'),
                403,
                'Hanya Direktur Utama yang berwenang memberikan dispensasi FAIL Override.'
            );
        } elseif ($exceptionType === ShmCreditCheckException::TYPE_COMPLETION_BYPASS) {
            // Business Authority: Direktur Utama Makro
            // Technical Administration / Troubleshooting Access: Superadmin
            $canBypass = Gate::forUser($authorizer)->allows('shm.credit_checking.bypass_completion')
                || $authorizer->isSuperadmin();

            abort_unless(
                $canBypass,
                403,
                'Hanya Direktur Utama (otoritas bisnis) atau Superadmin (akses teknis) yang berwenang mengeksekusi dispensasi Completion Bypass.'
            );
        } else {
            throw ValidationException::withMessages([
                'exception_type' => "Tipe pengecualian '{$exceptionType}' tidak valid.",
            ]);
        }

        $trimmedJustification = trim($internalJustification);
        if (mb_strlen($trimmedJustification) < 20) {
            throw ValidationException::withMessages([
                'internal_justification' => 'Justifikasi internal rahasia wajib diisi minimal 20 karakter.',
            ]);
        }

        $trimmedDisclosure = trim($officialDisclosureSummary);
        if ($trimmedDisclosure === '') {
            throw ValidationException::withMessages([
                'official_disclosure_summary' => 'Ringkasan keterbukaan resmi wajib diisi.',
            ]);
        }

        return DbTransaction::run(function () use (
            $application,
            $exceptionType,
            $authorizer,
            $trimmedJustification,
            $trimmedDisclosure,
            $blockingCondition
        ): ShmCreditCheckException {
            $lockedApp = ShmApplication::query()->lockForUpdate()->findOrFail($application->id);

            // Validasi prasyarat kondisi per tipe
            $activeCheck = $lockedApp->latestCreditCheck;

            if ($exceptionType === ShmCreditCheckException::TYPE_FAIL_OVERRIDE) {
                if (! $activeCheck || ! $activeCheck->isCompleted() || $activeCheck->outcome?->value !== 'fail') {
                    throw ValidationException::withMessages([
                        'exception_type' => 'Dispensasi FAIL Override hanya dapat diberikan jika Credit Checking telah selesai dengan hasil FAIL.',
                    ]);
                }
            } elseif ($exceptionType === ShmCreditCheckException::TYPE_COMPLETION_BYPASS) {
                if ($activeCheck && $activeCheck->isCompleted()) {
                    throw ValidationException::withMessages([
                        'exception_type' => 'Completion Bypass tidak diperlukan karena Credit Checking telah berstatus selesai.',
                    ]);
                }
            }

            // Pastikan belum ada dispensasi aktif dengan tipe yang sama yang belum dikonsumsi
            $existingActive = ShmCreditCheckException::query()
                ->where('shm_application_id', $lockedApp->id)
                ->where('exception_type', $exceptionType)
                ->where('status', ShmCreditCheckException::STATUS_AUTHORIZED)
                ->lockForUpdate()
                ->first();

            if ($existingActive) {
                throw ValidationException::withMessages([
                    'exception' => "Sudah terdapat dispensasi aktif ({$existingActive->exception_number}) untuk pengajuan ini yang belum dikonsumsi.",
                ]);
            }

            $exceptionNumber = 'EXP-SHM-'.date('Ym').'-'.str_pad((string) (ShmCreditCheckException::count() + 1), 4, '0', STR_PAD_LEFT);

            $exception = ShmCreditCheckException::create([
                'exception_number' => $exceptionNumber,
                'shm_application_id' => $lockedApp->id,
                'exception_type' => $exceptionType,
                'status' => ShmCreditCheckException::STATUS_AUTHORIZED,
                'authorized_by' => $authorizer->id,
                'authorized_by_name' => $authorizer->name,
                'authorized_at' => now(),
                'blocking_condition' => $blockingCondition,
                'internal_justification' => $trimmedJustification,
                'official_disclosure_summary' => $trimmedDisclosure,
            ]);

            AuditLogger::log(
                "shm.credit_check.{$exceptionType}",
                ShmCreditCheckException::class,
                $exception->id,
                $lockedApp->application_number,
                "Dispensasi khusus eksekutif {$exceptionNumber} diberikan oleh Direktur Utama.",
                [
                    'exception_number' => $exceptionNumber,
                    'exception_type' => $exceptionType,
                    'authorized_by' => $authorizer->id,
                    'disclosure_summary' => $trimmedDisclosure,
                ]
            );

            return $exception;
        });
    }

    /**
     * Konsumsi dispensasi aktif secara atomik saat memorandum disubmit.
     *
     * @throws ValidationException
     */
    public function consumeException(
        ShmApplication $application,
        string $requiredType,
        ShmMemorandum $memorandum
    ): ShmCreditCheckException {
        return DbTransaction::run(function () use ($application, $requiredType, $memorandum): ShmCreditCheckException {
            /** @var ShmCreditCheckException $lockedException */
            $lockedException = ShmCreditCheckException::query()
                ->where('shm_application_id', $application->id)
                ->where('exception_type', $requiredType)
                ->where('status', ShmCreditCheckException::STATUS_AUTHORIZED)
                ->lockForUpdate()
                ->first();

            if (! $lockedException) {
                throw ValidationException::withMessages([
                    'exception' => "Pengajuan memerlukan dispensasi aktif tipe '{$requiredType}' yang telah diotorisasi oleh Direktur Utama.",
                ]);
            }

            if (! $lockedException->isAvailable()) {
                throw ValidationException::withMessages([
                    'exception' => "Dispensasi {$lockedException->exception_number} sudah tidak tersedia atau telah dikonsumsi.",
                ]);
            }

            $lockedException->update([
                'status' => ShmCreditCheckException::STATUS_CONSUMED,
                'consumed_memorandum_id' => $memorandum->id,
                'consumed_at' => now(),
            ]);

            return $lockedException;
        });
    }

    /**
     * Batalkan dispensasi yang belum dikonsumsi.
     *
     * @throws ValidationException
     */
    public function revokeException(
        ShmCreditCheckException $exception,
        User $revoker,
        string $reason
    ): ShmCreditCheckException {
        $trimmedReason = trim($reason);
        if ($trimmedReason === '') {
            throw ValidationException::withMessages([
                'revocation_reason' => 'Alasan pembatalan dispensasi wajib diisi.',
            ]);
        }

        return DbTransaction::run(function () use ($exception, $revoker, $trimmedReason): ShmCreditCheckException {
            /** @var ShmCreditCheckException $locked */
            $locked = ShmCreditCheckException::query()->lockForUpdate()->findOrFail($exception->id);

            if ($locked->status !== ShmCreditCheckException::STATUS_AUTHORIZED) {
                throw ValidationException::withMessages([
                    'status' => 'Hanya dispensasi berstatus Authorized yang dapat dibatalkan.',
                ]);
            }

            $locked->update([
                'status' => ShmCreditCheckException::STATUS_REVOKED,
                'revoked_by' => $revoker->id,
                'revoked_at' => now(),
                'revocation_reason' => $trimmedReason,
            ]);

            return $locked;
        });
    }
}
