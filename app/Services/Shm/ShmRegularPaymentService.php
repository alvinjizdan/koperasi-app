<?php

namespace App\Services\Shm;

use App\Models\Member;
use App\Models\SavingProduct;
use App\Models\SavingsAccount;
use App\Models\SavingsTransaction;
use App\Models\ShmLoan;
use App\Models\ShmLoanPayment;
use App\Services\Accounting\ShmAutomaticJournalService;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * T151 / T003 — Layanan Pembayaran Angsuran Reguler Pinjaman SHM.
 *
 * Mengorkestrasi transaksi pembayaran angsuran reguler secara atomik:
 * 1. Lock baris pinjaman (lockForUpdate).
 * 2. Cek idempotensi berlapis (sebelum dan sesudah lock).
 * 3. Guard status pinjaman (harus dicairkan dan aktif).
 * 4. Guard nominal pembayaran (> 0).
 * 5. Guard settlement domain lock (T108): tolak bila ada settlement aktif.
 * 6. Guard jadwal angsuran: wajib ada jadwal (fail-closed).
 * 7. Guard underpayment: pembayaran < kewajiban angsuran berjalan ditolak.
 * 8. Overpayment pre-resolution: selesaikan Member & Simpanan Pokok sebelum menulis data.
 * 9. Catat pembayaran (ShmLoanPaymentService T86).
 * 10. Alokasi pembayaran bunga -> pokok (ShmLoanPaymentAllocationService T87, penalty = 0).
 * 11. Penutupan pinjaman otomatis jika total_owed <= 0.005.
 * 12. Setor kelebihan pembayaran ke Simpanan Pokok (ShmSavingsIntegrationService T89C, no false cash inflow).
 * 13. Audit log shm.loan.payment.applied dengan jejak finansial lengkap.
 */
class ShmRegularPaymentService
{
    public function __construct(
        private readonly ShmLoanPaymentService $paymentService,
        private readonly ShmLoanPaymentAllocationService $allocationService,
        private readonly ShmSavingsIntegrationService $savingsIntegration,
        // T006E — Layanan integrasi jurnal akuntansi otomatis SHM
        private readonly ShmAutomaticJournalService $autoJournalService,
    ) {}

    /**
     * Jalankan pembayaran angsuran reguler.
     *
     * @param  array{
     *     payment_date: string,
     *     amount: numeric,
     *     payment_channel?: string|null,
     *     notes?: string|null,
     *     idempotency_key?: string|null
     * }  $data
     * @return array{
     *     payment: ShmLoanPayment,
     *     allocated_amount: float,
     *     excess_amount: float,
     *     savings_transaction: SavingsTransaction|null,
     *     savings_account: SavingsAccount|null,
     *     loan_closed: bool,
     *     remaining_total_owed: float,
     *     was_replayed: bool
     * }
     *
     * @throws ValidationException
     * @throws \Throwable
     */
    public function pay(ShmLoan $loan, array $data, int $actorId): array
    {
        $idempotencyKey = $data['idempotency_key'] ?? null;

        // 1. Cek idempotensi sebelum transaksi (cepat)
        if (! empty($idempotencyKey)) {
            $existing = ShmLoanPayment::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                return $this->buildReplayResponse($loan, $existing);
            }
        }

        return DB::transaction(function () use ($loan, $data, $actorId, $idempotencyKey): array {
            // 2. Lock baris pinjaman
            $locked = ShmLoan::query()->lockForUpdate()->findOrFail($loan->id);

            // 3. Cek ulang idempotensi setelah lock
            if (! empty($idempotencyKey)) {
                $existing = ShmLoanPayment::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing !== null) {
                    return $this->buildReplayResponse($locked, $existing);
                }
            }

            // 4. Guard status pencairan
            if ($locked->disbursed_at === null) {
                throw ValidationException::withMessages([
                    'loan_id' => sprintf(
                        'Pinjaman %s belum dicairkan sehingga tidak dapat menerima pembayaran.',
                        $locked->loan_number,
                    ),
                ]);
            }

            // 5. Guard status pinjaman aktif
            if ($locked->status !== ShmLoan::STATUS_ACTIVE) {
                throw ValidationException::withMessages([
                    'loan_id' => sprintf(
                        'Pinjaman %s sudah ditutup sehingga tidak dapat menerima pembayaran.',
                        $locked->loan_number,
                    ),
                ]);
            }

            // 6. Guard nominal pembayaran
            $amountRaw = $data['amount'] ?? null;
            if (! is_numeric($amountRaw) || (float) $amountRaw <= 0) {
                throw ValidationException::withMessages([
                    'amount' => 'Nominal pembayaran harus berupa angka dan lebih besar dari 0.',
                ]);
            }
            $amount = (float) $amountRaw;

            // 7. Guard settlement domain lock (T108 / T003)
            $activeSettlement = $locked->activeSettlement();
            if ($activeSettlement !== null) {
                throw ValidationException::withMessages([
                    'loan_id' => sprintf(
                        'Pinjaman %s sedang dalam proses pelunasan (settlement %s berstatus "%s") sehingga pembayaran angsuran reguler ditolak (domain settlement dikunci).',
                        $locked->loan_number,
                        $activeSettlement->settlement_reference,
                        $activeSettlement->status,
                    ),
                ]);
            }

            // 8. Guard jadwal angsuran (fail-closed)
            if (! $locked->installments()->exists()) {
                throw ValidationException::withMessages([
                    'loan_id' => sprintf(
                        'Pinjaman %s belum memiliki jadwal angsuran sehingga pembayaran reguler tidak dapat diproses.',
                        $locked->loan_number,
                    ),
                ]);
            }

            // 9. Guard underpayment terhadap angsuran berjalan pertama
            $currentInstallment = $locked->currentPayableInstallment();
            if ($currentInstallment === null) {
                throw ValidationException::withMessages([
                    'loan_id' => sprintf(
                        'Pinjaman %s tidak memiliki kewajiban angsuran berjalan.',
                        $locked->loan_number,
                    ),
                ]);
            }

            $requiredAmount = (float) $currentInstallment->remaining_amount;
            if ($amount < $requiredAmount) {
                throw ValidationException::withMessages([
                    'amount' => sprintf(
                        'Nominal pembayaran Rp %s tidak memenuhi kewajiban angsuran berjalan sebesar Rp %s.',
                        number_format($amount, 0, ',', '.'),
                        number_format($requiredAmount, 0, ',', '.'),
                    ),
                ]);
            }

            // 10. Pre-resolusi overpayment (fail fast sebelum membuat baris pembayaran apa pun)
            $totalOwedBefore = $locked->totalOwed();
            $allocableAmount = min($amount, $totalOwedBefore);
            $expectedExcess = $amount - $allocableAmount;

            $member = null;
            $savingsAccount = null;

            if ($expectedExcess > 0.005) {
                $customer = $locked->customer;
                if ($customer === null || $customer->member_id === null) {
                    throw ValidationException::withMessages([
                        'amount' => sprintf(
                            'Lebih bayar Rp %s harus diposting ke Simpanan Pokok — BLOCKER BUSINESS DATA: nasabah SHM tidak memiliki relasi anggota koperasi sumber.',
                            number_format($expectedExcess, 2, ',', '.'),
                        ),
                    ]);
                }

                $member = Member::query()->lockForUpdate()->find($customer->member_id);
                if ($member === null) {
                    throw ValidationException::withMessages([
                        'amount' => sprintf(
                            'Lebih bayar Rp %s harus diposting ke Simpanan Pokok — BLOCKER BUSINESS DATA: anggota koperasi sumber #%d tidak ditemukan.',
                            number_format($expectedExcess, 2, ',', '.'),
                            $customer->member_id,
                        ),
                    ]);
                }

                if ($member->status !== 'active') {
                    throw ValidationException::withMessages([
                        'amount' => sprintf(
                            'Lebih bayar Rp %s harus diposting ke Simpanan Pokok — BLOCKER BUSINESS DATA: anggota koperasi sumber %s tidak aktif (status: %s).',
                            number_format($expectedExcess, 2, ',', '.'),
                            $member->member_number,
                            (string) $member->status,
                        ),
                    ]);
                }

                // Verifikasi ketersediaan rekening/produk Simpanan Pokok
                $savingsAccount = $this->savingsIntegration->resolveAccount($member, SavingProduct::CATEGORY_PRINCIPAL);
            }

            // 11. Buat pembayaran (T86)
            $payment = $this->paymentService->create($locked, $data, $actorId);

            // 12. Alokasikan pembayaran (T87)
            $allocationResult = $this->allocationService->allocate($payment);
            $allocatedAmount = (float) $allocationResult['allocated_amount'];
            $excessAmount = (float) $allocationResult['unallocated_amount'];

            // 13. Periksa penutupan pinjaman otomatis (T107)
            $totalOwedAfter = $locked->totalOwed();
            $loanClosed = false;
            $previousStatus = $locked->status;

            if ($totalOwedAfter <= 0.005) {
                $locked->status = ShmLoan::STATUS_CLOSED;
                $locked->save();
                $loanClosed = true;

                AuditLogger::log(
                    'shm.loan.closed',
                    ShmLoan::class,
                    $locked->id,
                    $locked->loan_number,
                    sprintf('Pinjaman %s ditutup otomatis setelah seluruh kewajiban terbayar.', $locked->loan_number),
                    [
                        'shm_loan_id' => $locked->id,
                        'loan_number' => $locked->loan_number,
                        'previous_status' => $previousStatus,
                        'new_status' => ShmLoan::STATUS_CLOSED,
                        'payment_id' => $payment->id,
                        'payment_reference' => $payment->payment_reference,
                        'remaining_total_owed' => number_format($totalOwedAfter, 2, '.', ''),
                    ],
                );
            }

            // 14. Setor kelebihan pembayaran ke Simpanan Pokok (T89C) tanpa dampak kasir
            $savingsTransaction = null;
            if ($excessAmount > 0.005 && $member !== null) {
                $savingsTransaction = $this->savingsIntegration->deposit(
                    $member,
                    SavingProduct::CATEGORY_PRINCIPAL,
                    $excessAmount,
                    sprintf('shm-regular-overpayment:%d', $payment->id),
                    sprintf('Setoran Simpanan Pokok dari lebih bayar angsuran pinjaman SHM %s', $locked->loan_number),
                    $actorId,
                    recordCashImpact: false,
                );
            }

            // T006E — Jurnal Otomatis Pembayaran Angsuran SHM (atomik di dalam transaksi yang sama)
            $this->autoJournalService->createPaymentJournal($payment, $actorId);

            // 15. Audit transaksi lengkap (T151)
            AuditLogger::log(
                'shm.loan.payment.applied',
                ShmLoanPayment::class,
                $payment->id,
                $payment->payment_reference,
                sprintf(
                    'Pembayaran angsuran %s pinjaman %s diterapkan: Rp %s teralokasi%s%s.',
                    $payment->payment_reference,
                    $locked->loan_number,
                    number_format($allocatedAmount, 0, ',', '.'),
                    $excessAmount > 0.005 ? sprintf(', lebih bayar Rp %s disetor ke Simpanan Pokok', number_format($excessAmount, 0, ',', '.')) : '',
                    $loanClosed ? ', pinjaman ditutup otomatis' : '',
                ),
                [
                    'shm_loan_id' => $locked->id,
                    'loan_number' => $locked->loan_number,
                    'payment_id' => $payment->id,
                    'payment_reference' => $payment->payment_reference,
                    'payment_amount' => number_format($amount, 2, '.', ''),
                    'allocated_amount' => number_format($allocatedAmount, 2, '.', ''),
                    'excess_amount' => number_format($excessAmount, 2, '.', ''),
                    'transferred_amount' => number_format($savingsTransaction !== null ? $excessAmount : 0.0, 2, '.', ''),
                    'excess_destination' => $savingsTransaction !== null ? 'Simpanan Pokok' : null,
                    'member_id' => $member?->id,
                    'savings_account_id' => $savingsAccount?->id,
                    'savings_account_number' => $savingsAccount?->account_number,
                    'savings_transaction_id' => $savingsTransaction?->id,
                    'savings_idempotency_key' => $savingsTransaction !== null ? sprintf('shm-regular-overpayment:%d', $payment->id) : null,
                    'loan_closed' => $loanClosed,
                    'previous_loan_status' => $previousStatus,
                    'new_loan_status' => $locked->status,
                    'remaining_total_owed' => number_format($totalOwedAfter, 2, '.', ''),
                    'penalty' => '0.00',
                ],
            );

            return [
                'payment' => $payment,
                'allocated_amount' => $allocatedAmount,
                'excess_amount' => $excessAmount,
                'savings_transaction' => $savingsTransaction,
                'savings_account' => $savingsAccount,
                'loan_closed' => $loanClosed,
                'remaining_total_owed' => $totalOwedAfter,
                'was_replayed' => false,
            ];
        });
    }

    /**
     * Bangun respon untuk request yang diputar ulang (idempotent replay).
     *
     * @return array{
     *     payment: ShmLoanPayment,
     *     allocated_amount: float,
     *     excess_amount: float,
     *     savings_transaction: null,
     *     savings_account: null,
     *     loan_closed: bool,
     *     remaining_total_owed: float,
     *     was_replayed: true
     * }
     */
    private function buildReplayResponse(ShmLoan $loan, ShmLoanPayment $payment): array
    {
        $allocatedAmount = (float) $payment->allocations()->sum('total_amount');
        $unallocatedAmount = max(0.0, (float) $payment->amount - $allocatedAmount);

        return [
            'payment' => $payment,
            'allocated_amount' => $allocatedAmount,
            'excess_amount' => $unallocatedAmount,
            'savings_transaction' => null,
            'savings_account' => null,
            'loan_closed' => $loan->status === ShmLoan::STATUS_CLOSED,
            'remaining_total_owed' => $loan->totalOwed(),
            'was_replayed' => true,
        ];
    }
}
