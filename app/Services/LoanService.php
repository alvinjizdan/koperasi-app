<?php

namespace App\Services;

use App\Models\CashTransaction;
use App\Models\InstallmentSchedule;
use App\Models\Loan;
use App\Models\LoanDisbursement;
use App\Models\Member;
use App\Support\DbTransaction;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class LoanService
{
    public function __construct(private CashierService $cashierService) {}

    /**
     * Pencatatan/Pengajuan Pinjaman Baru.
     *
     * Atomik: nomor pinjaman diregenerasi di dalam transaksi, sehingga race
     * condition pada penomoran berbasis max(id) tidak menghasilkan pengajuan
     * parsial atau nomor ganda.
     */
    public function submit(array $data, int $userId): Loan
    {
        return DbTransaction::run(function () use ($data, $userId) {
            $member = Member::lockForUpdate()->findOrFail($data['member_id']);

            if ($member->status !== 'active') {
                throw ValidationException::withMessages([
                    'member_id' => ['Anggota harus dalam status aktif untuk mengajukan pinjaman.'],
                ]);
            }

            $loan = Loan::create([
                'loan_number' => Loan::generateLoanNumber(),
                'member_id' => $member->id,
                'amount' => $data['amount'],
                'tenor_months' => $data['tenor_months'],
                'submission_date' => $data['submission_date'],
                'status' => 'PENDING',
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
            ]);

            AuditLogger::log(
                'loan.submitted',
                Loan::class,
                $loan->id,
                $loan->loan_number,
                sprintf('Pengajuan pinjaman Rp %s dengan tenor %d bulan.', number_format((float) $loan->amount, 2, ',', '.'), (int) $loan->tenor_months),
                [
                    'amount' => round((float) $loan->amount, 2),
                    'tenor_months' => (int) $loan->tenor_months,
                    'member_id' => $member->id,
                    'member_number' => $member->member_number,
                    'created_by' => $userId,
                ],
            );

            return $loan;
        });
    }

    /**
     * Persetujuan Pinjaman
     */
    public function approve(Loan $loan, int $userId): Loan
    {
        return DbTransaction::run(function () use ($loan, $userId) {
            $lockedLoan = Loan::lockForUpdate()->findOrFail($loan->id);

            if ($lockedLoan->status !== 'PENDING') {
                throw ValidationException::withMessages([
                    'status' => ['Hanya pinjaman dengan status PENDING yang dapat disetujui.'],
                ]);
            }

            $lockedLoan->update([
                'status' => 'APPROVED',
                'approved_at' => now()->format('Y-m-d'),
                'approved_by' => $userId,
            ]);

            AuditLogger::log(
                'loan.approved',
                Loan::class,
                $lockedLoan->id,
                $lockedLoan->loan_number,
                sprintf('Pinjaman disetujui: Rp %s.', number_format((float) $lockedLoan->amount, 2, ',', '.')),
                [
                    'amount' => round((float) $lockedLoan->amount, 2),
                    'approved_by' => $userId,
                    'from_status' => 'PENDING',
                    'to_status' => 'APPROVED',
                ],
            );

            return $lockedLoan;
        });
    }

    /**
     * Penolakan Pinjaman
     */
    public function reject(Loan $loan, string $reason, int $userId): Loan
    {
        return DbTransaction::run(function () use ($loan, $reason, $userId) {
            $lockedLoan = Loan::lockForUpdate()->findOrFail($loan->id);

            if ($lockedLoan->status !== 'PENDING') {
                throw ValidationException::withMessages([
                    'status' => ['Hanya pinjaman dengan status PENDING yang dapat ditolak.'],
                ]);
            }

            $lockedLoan->update([
                'status' => 'REJECTED',
                'rejected_at' => now()->format('Y-m-d'),
                'rejected_by' => $userId,
                'rejection_reason' => $reason,
            ]);

            AuditLogger::log(
                'loan.rejected',
                Loan::class,
                $lockedLoan->id,
                $lockedLoan->loan_number,
                'Pinjaman ditolak.',
                [
                    'reason' => $reason,
                    'rejected_by' => $userId,
                    'from_status' => 'PENDING',
                    'to_status' => 'REJECTED',
                ],
            );

            return $lockedLoan;
        });
    }

    /**
     * Pencairan Pinjaman & Pembentukan Jadwal Angsuran
     */
    public function disburse(Loan $loan, array $data, int $userId): LoanDisbursement
    {
        return DbTransaction::run(function () use ($loan, $data, $userId) {
            $lockedLoan = Loan::lockForUpdate()->findOrFail($loan->id);

            if ($lockedLoan->status !== 'APPROVED') {
                throw ValidationException::withMessages([
                    'status' => ['Hanya pinjaman berstatus APPROVED yang dapat dicairkan.'],
                ]);
            }

            if ($lockedLoan->disbursement()->exists()) {
                throw ValidationException::withMessages([
                    'disbursement' => ['Pinjaman ini sudah dicairkan sebelumnya.'],
                ]);
            }

            $disbursementDate = $data['disbursement_date'];
            $firstDueDate = $data['first_due_date'] ?? Carbon::parse($disbursementDate)->addMonth()->format('Y-m-d');
            $disbursementAmount = (float) $lockedLoan->amount;

            $disbursement = LoanDisbursement::create([
                'disbursement_number' => LoanDisbursement::generateDisbursementNumber(),
                'loan_id' => $lockedLoan->id,
                'amount' => $disbursementAmount,
                'disbursement_date' => $disbursementDate,
                'method' => $data['method'] ?? 'CASH',
                'processed_by' => $userId,
                'notes' => $data['notes'] ?? null,
            ]);

            $lockedLoan->update([
                'status' => 'ACTIVE',
                'disbursed_at' => $disbursementDate,
                'disbursed_by' => $userId,
                'first_due_date' => $firstDueDate,
            ]);

            // Buat Jadwal Angsuran
            $tenor = (int) $lockedLoan->tenor_months;
            $monthlyPrincipal = round($disbursementAmount / $tenor, 2);

            for ($i = 1; $i <= $tenor; $i++) {
                $dueDate = Carbon::parse($firstDueDate)->addMonths($i - 1)->format('Y-m-d');

                // Untuk angsuran terakhir, sesuaikan selisih pembulatan
                $principal = ($i === $tenor)
                    ? round($disbursementAmount - ($monthlyPrincipal * ($tenor - 1)), 2)
                    : $monthlyPrincipal;

                InstallmentSchedule::create([
                    'loan_id' => $lockedLoan->id,
                    'installment_number' => $i,
                    'due_date' => $dueDate,
                    'principal_amount' => $principal,
                    'interest_amount' => 0,
                    'total_amount' => $principal,
                    'paid_amount' => 0,
                    'remaining_amount' => $principal,
                    'status' => 'UPCOMING',
                ]);
            }

            // Integrasi kas: hanya pencairan tunai yang memengaruhi saldo kas fisik.
            // Idempotensi dijaga melalui referensi pencairan sebagai transaksi sumber.
            if ($disbursement->method === 'CASH') {
                $this->cashierService->recordSourceImpact(
                    CashTransaction::TYPE_EXPENSE,
                    config('cashier.source_categories.loan_disbursement'),
                    $disbursementAmount,
                    $disbursementDate,
                    'Pencairan pinjaman '.$lockedLoan->loan_number,
                    $disbursement,
                    $userId,
                );
            }

            AuditLogger::log(
                'loan.disbursed',
                LoanDisbursement::class,
                $disbursement->id,
                $disbursement->disbursement_number,
                sprintf('Pencairan pinjaman %s sebesar Rp %s (%s).', $lockedLoan->loan_number, number_format($disbursementAmount, 2, ',', '.'), $disbursement->method),
                [
                    'loan_id' => $lockedLoan->id,
                    'loan_number' => $lockedLoan->loan_number,
                    'amount' => $disbursementAmount,
                    'method' => $disbursement->method,
                    'disbursement_number' => $disbursement->disbursement_number,
                    'disbursed_by' => $userId,
                ],
            );

            return $disbursement;
        });
    }
}
