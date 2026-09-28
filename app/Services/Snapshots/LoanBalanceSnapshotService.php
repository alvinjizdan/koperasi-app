<?php

namespace App\Services\Snapshots;

use App\Models\InstallmentPayment;
use App\Models\Loan;
use App\Models\LoanBalanceSnapshot;
use App\Services\AuditLogger;
use App\Support\DbTransaction;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * Snapshot posisi saldo pinjaman (Ticket 25).
 *
 * Sumber data = kolom jadwal angsuran (sumber kebenaran komponen kewajiban):
 * pokok = Σ(principal_amount - paid_amount), bunga = Σ(interest_amount -
 * interest_paid), denda = Σ(penalty_accrued - penalty_paid), total = Σ
 * komponen — konsisten dengan definisi Loan::total_owed.
 *
 * Jaminan:
 * - PURE READ-ONLY terhadap data finansial: tidak ada mutasi pinjaman, kas,
 *   jurnal; TIDAK memicu akrual penalti.
 * - Idempoten: unik per (loan_id, as_of_date, context); upsert-like via
 *   firstOrCreate — snapshot yang ada dikembalikan tanpa menulis ulang.
 * - Tanggal posisi (as_of_date) ≠ waktu pengambilan (created_at).
 * - Snapshot untuk tanggal lampau: nilai yang tersimpan adalah saldo SAAT
 *   PENGAMBILAN (dibaca hari ini); flag integrity menandai bahwa snapshot
 *   yang dibuat setelah tanggal posisi BUKAN histori terverifikasi.
 * - Tidak ada klaim histori sebelum snapshot pertama.
 */
class LoanBalanceSnapshotService
{
    /**
     * Ambil snapshot untuk satu pinjaman pada tanggal posisi tertentu.
     *
     * @return array{snapshot: LoanBalanceSnapshot, created: bool}
     *
     * @throws ValidationException bila pinjaman tidak dalam status terverifikasi snapshot
     */
    public function capture(Loan $loan, string $asOfDate, int $userId, string $context = LoanBalanceSnapshot::CONTEXT_MANUAL): array
    {
        return DbTransaction::run(function () use ($loan, $asOfDate, $userId, $context) {
            $existing = LoanBalanceSnapshot::query()
                ->where('loan_id', $loan->id)
                ->where('as_of_date', $asOfDate)
                ->where('context', $context)
                ->first();

            if ($existing !== null) {
                return ['snapshot' => $existing, 'created' => false];
            }

            // Baca komponen kewajiban dari jadwal (sumber otoritatif).
            $components = $loan->schedules()
                ->selectRaw('
                    COALESCE(SUM(principal_amount - paid_amount), 0) as principal,
                    COALESCE(SUM(interest_amount - interest_paid), 0) as interest,
                    COALESCE(SUM(penalty_accrued - penalty_paid), 0) as penalty,
                    COUNT(*) as schedule_count,
                    SUM(CASE WHEN status != \'PAID\' THEN 1 ELSE 0 END) as unpaid_schedule_count
                ')
                ->first();

            $principal = round(max(0.0, (float) $components->principal), 2);
            $interest = round(max(0.0, (float) $components->interest), 2);
            $penalty = round(max(0.0, (float) $components->penalty), 2);
            $totalOwed = round($principal + $interest + $penalty, 2);

            // Konsistensi dengan Loan::total_owed (sumber keputusan pelunasan).
            $loanTotal = $loan->fresh()->total_owed;
            if (round($loanTotal - $totalOwed, 2) !== 0.0) {
                throw ValidationException::withMessages([
                    'loan' => ['Total kewajiban tidak konsisten antara komponen jadwal dan perhitungan pinjaman; snapshot dibatalkan.'],
                ]);
            }

            $totalPaid = round((float) InstallmentPayment::query()
                ->where('loan_id', $loan->id)
                ->where('status', 'COMPLETED')
                ->sum('amount'), 2);

            $snapshot = LoanBalanceSnapshot::create([
                'loan_id' => $loan->id,
                'as_of_date' => $asOfDate,
                'context' => $context,
                'status' => $loan->status,
                'outstanding_principal' => $principal,
                'outstanding_interest' => $interest,
                'outstanding_penalty' => $penalty,
                'total_owed' => $totalOwed,
                'total_paid' => $totalPaid,
                'schedule_count' => (int) $components->schedule_count,
                'unpaid_schedule_count' => (int) $components->unpaid_schedule_count,
                'captured_by' => $userId,
            ]);

            AuditLogger::log(
                'loan.snapshot.captured',
                LoanBalanceSnapshot::class,
                $snapshot->id,
                $loan->loan_number,
                sprintf(
                    'Snapshot posisi pinjaman %s per %s: total kewajiban Rp %s (pokok Rp %s, bunga Rp %s, denda Rp %s).',
                    $loan->loan_number,
                    $asOfDate,
                    number_format($totalOwed, 2, ',', '.'),
                    number_format($principal, 2, ',', '.'),
                    number_format($interest, 2, ',', '.'),
                    number_format($penalty, 2, ',', '.'),
                ),
                [
                    'as_of_date' => $asOfDate,
                    'context' => $context,
                    'outstanding_principal' => $principal,
                    'outstanding_interest' => $interest,
                    'outstanding_penalty' => $penalty,
                    'total_owed' => $totalOwed,
                    'total_paid' => $totalPaid,
                    'loan_status' => $loan->status,
                    'captured_by' => $userId,
                ],
            );

            return ['snapshot' => $snapshot->fresh(), 'created' => true];
        });
    }

    /**
     * Snapshot untuk semua pinjaman ACTIVE (jalur batch eksplisit).
     *
     * Kegagalan satu pinjaman terisolasi dan teraudit; pinjaman lain tetap
     * terproses. Hasil membedakan dibuat / dilewati (idempoten) / gagal.
     *
     * @return array{created: int, skipped: int, failed: int, total_owed: float, failures: array<int, array{loan_id: int, reason: string}>}
     */
    public function captureAll(string $asOfDate, int $userId, string $context = LoanBalanceSnapshot::CONTEXT_BATCH): array
    {
        $created = 0;
        $skipped = 0;
        $failed = 0;
        $totalOwed = 0.0;
        $failures = [];

        Loan::query()
            ->where('status', 'ACTIVE')
            ->orderBy('id')
            ->chunkById(100, function ($loans) use (&$created, &$skipped, &$failed, &$totalOwed, &$failures, $asOfDate, $userId, $context) {
                foreach ($loans as $loan) {
                    try {
                        $result = $this->capture($loan->fresh(), $asOfDate, $userId, $context);

                        if ($result['created']) {
                            $created++;
                            $totalOwed = round($totalOwed + (float) $result['snapshot']->total_owed, 2);
                        } else {
                            $skipped++;
                        }
                    } catch (ValidationException $e) {
                        $failed++;
                        $failures[] = [
                            'loan_id' => $loan->id,
                            'reason' => implode('; ', Arr::flatten($e->errors())),
                        ];
                    }
                }
            });

        if ($failures !== []) {
            AuditLogger::log(
                'loan.snapshot.failures',
                Loan::class,
                null,
                'loan-snapshots:capture',
                sprintf('%d pinjaman gagal snapshot pada tanggal posisi %s.', $failed, $asOfDate),
                ['failures' => $failures, 'as_of_date' => $asOfDate],
            );
        }

        return [
            'created' => $created,
            'skipped' => $skipped,
            'failed' => $failed,
            'total_owed' => $totalOwed,
            'failures' => $failures,
        ];
    }

    /**
     * Posisi historis sebuah pinjaman untuk tanggal posisi yang diminta.
     *
     * TIDAK mengklaim histori yang tidak tersedia:
     * - Snapshot tepat pada tanggal diminta → data tersedia.
     * - Tidak ada snapshot pada tanggal diminta → status NOT_AVAILABLE dengan
     *   penanda tanggal snapshot terdekat (jika ada), TANPA mencampur nilai.
     * - Snapshot dibuat setelah tanggal posisi (created_at > as_of_date) →
     *   ditandai bukan histori terverifikasi untuk tanggal diminta.
     */
    public function positionFor(Loan $loan, string $asOfDate): array
    {
        $snapshot = LoanBalanceSnapshot::query()
            ->where('loan_id', $loan->id)
            ->where('as_of_date', $asOfDate)
            ->orderByDesc('id')
            ->first();

        if ($snapshot === null) {
            $nearest = LoanBalanceSnapshot::query()
                ->where('loan_id', $loan->id)
                ->orderByDesc('as_of_date')
                ->first();

            return [
                'available' => false,
                'as_of_date' => $asOfDate,
                'reason' => $nearest === null
                    ? 'Belum ada snapshot untuk pinjaman ini (histori sebelum snapshot pertama tidak tersedia).'
                    : sprintf('Tidak ada snapshot per %s; snapshot terdekat tersedia per %s.', $asOfDate, (string) $nearest->as_of_date),
                'nearest_as_of_date' => $nearest !== null ? (string) $nearest->as_of_date : null,
            ];
        }

        // Snapshot yang dibuat SETELAH tanggal posisi bukan histori terverifikasi.
        $capturedAfter = $snapshot->created_at->toDateString() > (string) $snapshot->as_of_date;

        return [
            'available' => true,
            'as_of_date' => (string) $snapshot->as_of_date,
            'captured_at' => $snapshot->created_at->toDateTimeString(),
            'captured_after_as_of' => $capturedAfter,
            'verified_historical' => ! $capturedAfter,
            'status' => $snapshot->status,
            'outstanding_principal' => (float) $snapshot->outstanding_principal,
            'outstanding_interest' => (float) $snapshot->outstanding_interest,
            'outstanding_penalty' => (float) $snapshot->outstanding_penalty,
            'total_owed' => (float) $snapshot->total_owed,
            'total_paid' => $snapshot->total_paid !== null ? (float) $snapshot->total_paid : null,
            'schedule_count' => $snapshot->schedule_count,
            'unpaid_schedule_count' => $snapshot->unpaid_schedule_count,
            'context' => $snapshot->context,
        ];
    }
}
