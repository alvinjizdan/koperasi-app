<?php

namespace App\Services\Accounting;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\ShmAccountingMapping;
use App\Models\ShmLoan;
use App\Models\ShmLoanPayment;
use App\Models\ShmLoanSettlement;
use App\Models\ShmProductAccounting;
use App\Models\ShmSettlementCorrection;
use App\Services\AuditLogger;
use App\Support\AccountingContext;
use App\Support\DbTransaction;
use Illuminate\Validation\ValidationException;

/**
 * Service Integrasi Jurnal Otomatis Transaksi Bisnis SHM (T006E).
 *
 * Menghubungkan transaksi bisnis SHM:
 * - SHM_LOAN_DISBURSEMENT
 * - SHM_PAYMENT
 * - SHM_SETTLEMENT
 * - SHM_SETTLEMENT_CORRECTION
 *
 * dengan SHM Accounting Context, SHM Product Accounting, SHM Accounting Mapping,
 * dan JournalService (shared journal engine).
 */
class ShmAutomaticJournalService
{
    public function __construct(
        private readonly JournalService $journalService,
        private readonly ShmAccountingMappingService $mappingService,
    ) {}

    /**
     * 1. Jurnal Otomatis Pencairan Pinjaman SHM (SHM_LOAN_DISBURSEMENT).
     *
     * Debit: Piutang Pokok Pinjaman (Receivable)
     * Credit: Kas / Bank (Cash)
     * Nominal: Pokok Pinjaman (Gross Principal)
     */
    public function createDisbursementJournal(ShmLoan $loan, int $actorId): ?JournalEntry
    {
        return DbTransaction::run(function () use ($loan, $actorId) {
            // Cek idempotensi: apakah jurnal sudah pernah dibuat untuk loan ini
            $existing = $this->journalService->findForSource($loan, ShmAccountingMapping::EVENT_DISBURSEMENT);
            if ($existing !== null) {
                return $existing;
            }

            // Resolusi akun via Mapping & Product Accounting
            $resolved = $this->mappingService->resolveAccountsForEvent(
                ShmAccountingMapping::EVENT_DISBURSEMENT,
                $loan->shm_loan_product_id
            );

            $amount = round((float) $loan->principal, 2);
            if ($amount <= 0) {
                throw ValidationException::withMessages([
                    'principal' => ['Nominal pokok pinjaman pencairan harus bernilai positif.'],
                ]);
            }

            $entry = $this->journalService->createDraft([
                'accounting_context' => AccountingContext::SHM,
                'transaction_date' => $loan->disbursed_at?->toDateString() ?? now()->toDateString(),
                'description' => "Pencairan Pinjaman SHM {$loan->loan_number}",
                'source_type' => ShmLoan::class,
                'source_id' => $loan->id,
                'posting_type' => ShmAccountingMapping::EVENT_DISBURSEMENT,
            ], [
                [
                    'account_id' => $resolved['debit_account']->id,
                    'description' => "Piutang pokok pencairan {$loan->loan_number}",
                    'debit' => $amount,
                    'credit' => 0,
                ],
                [
                    'account_id' => $resolved['credit_account']->id,
                    'description' => "Kas keluar pencairan {$loan->loan_number}",
                    'debit' => 0,
                    'credit' => $amount,
                ],
            ], $actorId);

            // Langsung post jurnal agar aktif dan siap untuk laporan keuangan
            $posted = $this->journalService->post($entry, $actorId);

            AuditLogger::log(
                'accounting.shm_auto_journal.disbursement',
                JournalEntry::class,
                $posted->id,
                $posted->journal_number,
                "Jurnal otomatis pencairan pinjaman {$loan->loan_number} berhasil diposting.",
                [
                    'loan_id' => $loan->id,
                    'loan_number' => $loan->loan_number,
                    'amount' => $amount,
                    'journal_number' => $posted->journal_number,
                ]
            );

            return $posted;
        });
    }

    /**
     * 2. Jurnal Otomatis Pembayaran Angsuran Reguler (SHM_PAYMENT).
     *
     * Debit: Kas / Bank (Cash) -> total payment amount
     * Credit: Piutang Pokok Pinjaman (Receivable) -> principal portion
     * Credit: Pendapatan Bunga (Interest Revenue) -> interest portion
     */
    public function createPaymentJournal(ShmLoanPayment $payment, int $actorId): ?JournalEntry
    {
        return DbTransaction::run(function () use ($payment, $actorId) {
            $existing = $this->journalService->findForSource($payment, ShmAccountingMapping::EVENT_REGULAR_PAYMENT);
            if ($existing !== null) {
                return $existing;
            }

            $loan = $payment->loan ?? ShmLoan::findOrFail($payment->shm_loan_id);

            // Ambil alokasi pembayaran aktual
            $payment->load('allocations');
            $principalPaid = round((float) $payment->allocations->sum('principal_amount'), 2);
            $interestPaid = round((float) $payment->allocations->sum('interest_amount'), 2);
            $totalAllocated = round($principalPaid + $interestPaid, 2);

            if ($totalAllocated <= 0) {
                // Jika pembayaran ini belum/tidak memiliki alokasi angsuran (misal 100% overpayment/simpanan),
                // tidak ada jurnal piutang/bunga pinjaman
                return null;
            }

            // Dapatkan konfigurasi product accounting untuk loan ini
            $productConfig = ShmProductAccounting::query()
                ->shm()
                ->active()
                ->where('shm_loan_product_id', $loan->shm_loan_product_id)
                ->first();

            if (! $productConfig) {
                throw ValidationException::withMessages([
                    'product_accounting' => ["Konfigurasi akuntansi produk pinjaman [{$loan->product?->code}] tidak ditemukan atau nonaktif."],
                ]);
            }

            if (! $productConfig->cash_account_id || ! $productConfig->receivable_account_id || ! $productConfig->interest_revenue_account_id) {
                throw ValidationException::withMessages([
                    'product_accounting' => ["Konfigurasi akuntansi produk pinjaman [{$loan->product?->code}] belum lengkap (kas, piutang, dan pendapatan bunga wajib dikonfigurasi)."],
                ]);
            }

            $cashAccount = ChartOfAccount::find($productConfig->cash_account_id);
            $receivableAccount = ChartOfAccount::find($productConfig->receivable_account_id);
            $interestAccount = ChartOfAccount::find($productConfig->interest_revenue_account_id);

            $this->assertAccountUsable($cashAccount, 'Kas');
            $this->assertAccountUsable($receivableAccount, 'Piutang Pokok');
            $this->assertAccountUsable($interestAccount, 'Pendapatan Bunga');

            $lines = [];
            // Debit Kas sebesar total alokasi
            $lines[] = [
                'account_id' => $cashAccount->id,
                'description' => "Penerimaan angsuran {$payment->payment_reference}",
                'debit' => $totalAllocated,
                'credit' => 0,
            ];

            // Credit Piutang Pokok jika ada pokok yang dibayar
            if ($principalPaid > 0) {
                $lines[] = [
                    'account_id' => $receivableAccount->id,
                    'description' => "Pembayaran pokok pinjaman {$loan->loan_number}",
                    'debit' => 0,
                    'credit' => $principalPaid,
                ];
            }

            // Credit Pendapatan Bunga jika ada bunga yang dibayar
            if ($interestPaid > 0) {
                $lines[] = [
                    'account_id' => $interestAccount->id,
                    'description' => "Pendapatan bunga pinjaman {$loan->loan_number}",
                    'debit' => 0,
                    'credit' => $interestPaid,
                ];
            }

            $entry = $this->journalService->createDraft([
                'accounting_context' => AccountingContext::SHM,
                'transaction_date' => $payment->payment_date?->toDateString() ?? now()->toDateString(),
                'description' => "Pembayaran Angsuran SHM {$payment->payment_reference}",
                'source_type' => ShmLoanPayment::class,
                'source_id' => $payment->id,
                'posting_type' => ShmAccountingMapping::EVENT_REGULAR_PAYMENT,
            ], $lines, $actorId);

            $posted = $this->journalService->post($entry, $actorId);

            AuditLogger::log(
                'accounting.shm_auto_journal.payment',
                JournalEntry::class,
                $posted->id,
                $posted->journal_number,
                "Jurnal otomatis pembayaran angsuran {$payment->payment_reference} berhasil diposting.",
                [
                    'payment_id' => $payment->id,
                    'payment_reference' => $payment->payment_reference,
                    'principal_paid' => $principalPaid,
                    'interest_paid' => $interestPaid,
                    'total_allocated' => $totalAllocated,
                    'journal_number' => $posted->journal_number,
                ]
            );

            return $posted;
        });
    }

    /**
     * 3. Jurnal Otomatis Pelunasan Dipercepat (SHM_SETTLEMENT).
     * Hanya diposting saat settlement berstatus COMPLETED.
     */
    public function createSettlementJournal(ShmLoanSettlement $settlement, int $actorId): ?JournalEntry
    {
        return DbTransaction::run(function () use ($settlement, $actorId) {
            if ($settlement->status !== ShmLoanSettlement::STATUS_COMPLETED) {
                throw ValidationException::withMessages([
                    'settlement' => ['Jurnal pelunasan hanya dapat dibuat untuk settlement yang berstatus completed.'],
                ]);
            }

            $existing = $this->journalService->findForSource($settlement, ShmAccountingMapping::EVENT_SETTLEMENT);
            if ($existing !== null) {
                return $existing;
            }

            $loan = $settlement->loan ?? ShmLoan::findOrFail($settlement->shm_loan_id);

            $productConfig = ShmProductAccounting::query()
                ->shm()
                ->active()
                ->where('shm_loan_product_id', $loan->shm_loan_product_id)
                ->first();

            if (! $productConfig) {
                throw ValidationException::withMessages([
                    'product_accounting' => ["Konfigurasi akuntansi produk pinjaman [{$loan->product?->code}] tidak ditemukan atau nonaktif."],
                ]);
            }

            $principalAmount = round((float) $settlement->outstanding_principal, 2);
            $dueInterestAmount = round((float) $settlement->outstanding_interest, 2);
            $futureInterestAmount = (float) $settlement->discount_applied && $settlement->interest_after_discount_amount !== null
                ? round((float) $settlement->interest_after_discount_amount, 2)
                : round((float) ($settlement->remaining_interest_amount ?? 0), 2);

            $interestAmount = round($dueInterestAmount + $futureInterestAmount, 2);

            $discountAmount = (float) $settlement->discount_applied ? round((float) ($settlement->discount_amount ?? 0), 2) : 0.0;

            // Jika ada diskon pelunasan > 0 tetapi akun diskon belum dikonfigurasi: FAIL CLOSED!
            if ($discountAmount > 0 && ! $productConfig->settlement_discount_account_id) {
                throw ValidationException::withMessages([
                    'discount_account' => ['Settlement memiliki potongan diskon bunga, namun akun diskon pelunasan belum dikonfigurasi pada produk pinjaman (Pending Business Confirmation).'],
                ]);
            }

            $cashAmount = round((float) $settlement->settlement_amount, 2);

            $cashAccount = ChartOfAccount::find($productConfig->cash_account_id);
            $receivableAccount = ChartOfAccount::find($productConfig->receivable_account_id);
            $interestAccount = ChartOfAccount::find($productConfig->interest_revenue_account_id);

            $this->assertAccountUsable($cashAccount, 'Kas');
            $this->assertAccountUsable($receivableAccount, 'Piutang Pokok');
            $this->assertAccountUsable($interestAccount, 'Pendapatan Bunga');

            $lines = [];
            // Debit Kas sebesar nilai pembayaran pelunasan
            $lines[] = [
                'account_id' => $cashAccount->id,
                'description' => "Penerimaan pelunasan SHM {$settlement->settlement_reference}",
                'debit' => $cashAmount,
                'credit' => 0,
            ];

            // Credit Piutang Pokok
            if ($principalAmount > 0) {
                $lines[] = [
                    'account_id' => $receivableAccount->id,
                    'description' => "Pelunasan pokok pinjaman {$loan->loan_number}",
                    'debit' => 0,
                    'credit' => $principalAmount,
                ];
            }

            // Credit Pendapatan Bunga (bunga bersih yang diakui)
            if ($interestAmount > 0) {
                $lines[] = [
                    'account_id' => $interestAccount->id,
                    'description' => "Pendapatan bunga pelunasan {$loan->loan_number}",
                    'debit' => 0,
                    'credit' => $interestAmount,
                ];
            }

            // Catatan: Jika ada denda / outstanding_penalty
            if ((float) $settlement->outstanding_penalty > 0) {
                $penaltyAccount = ChartOfAccount::find($productConfig->penalty_revenue_account_id);
                if ($penaltyAccount) {
                    $this->assertAccountUsable($penaltyAccount, 'Pendapatan Denda');
                    $lines[] = [
                        'account_id' => $penaltyAccount->id,
                        'description' => "Pendapatan denda pelunasan {$loan->loan_number}",
                        'debit' => 0,
                        'credit' => round((float) $settlement->outstanding_penalty, 2),
                    ];
                }
            }

            $entry = $this->journalService->createDraft([
                'accounting_context' => AccountingContext::SHM,
                'transaction_date' => $settlement->settlement_date?->toDateString() ?? now()->toDateString(),
                'description' => "Pelunasan Dipercepat SHM {$settlement->settlement_reference}",
                'source_type' => ShmLoanSettlement::class,
                'source_id' => $settlement->id,
                'posting_type' => ShmAccountingMapping::EVENT_SETTLEMENT,
            ], $lines, $actorId);

            $posted = $this->journalService->post($entry, $actorId);

            AuditLogger::log(
                'accounting.shm_auto_journal.settlement',
                JournalEntry::class,
                $posted->id,
                $posted->journal_number,
                "Jurnal otomatis pelunasan {$settlement->settlement_reference} berhasil diposting.",
                [
                    'settlement_id' => $settlement->id,
                    'settlement_reference' => $settlement->settlement_reference,
                    'settlement_amount' => $cashAmount,
                    'journal_number' => $posted->journal_number,
                ]
            );

            return $posted;
        });
    }

    /**
     * 4. Jurnal Otomatis Koreksi Pelunasan (SHM_SETTLEMENT_CORRECTION).
     * Mereversi jurnal settlement original melalui reversal compensating journal.
     */
    public function reverseSettlementJournal(ShmSettlementCorrection $correction, int $actorId): ?JournalEntry
    {
        return DbTransaction::run(function () use ($correction, $actorId) {
            $originalSettlement = $correction->originalSettlement;
            if (! $originalSettlement) {
                return null;
            }

            $originalJournal = $this->journalService->findForSource($originalSettlement, ShmAccountingMapping::EVENT_SETTLEMENT);

            if (! $originalJournal) {
                // Jika settlement original tidak memiliki jurnal posted, tidak ada yang perlu direversi
                return null;
            }

            if ($originalJournal->status === JournalEntry::STATUS_REVERSED) {
                return $originalJournal->reversedBy;
            }

            $reason = "Koreksi Pelunasan SHM #{$correction->id}: {$correction->correction_reason}";
            $reversalJournal = $this->journalService->reverse($originalJournal, $actorId, $reason);

            AuditLogger::log(
                'accounting.shm_auto_journal.settlement_correction',
                JournalEntry::class,
                $reversalJournal->id,
                $reversalJournal->journal_number,
                "Jurnal pembalik otomatis untuk settlement {$originalSettlement->settlement_reference} berhasil diposting.",
                [
                    'correction_id' => $correction->id,
                    'original_settlement_id' => $originalSettlement->id,
                    'original_journal_number' => $originalJournal->journal_number,
                    'reversal_journal_number' => $reversalJournal->journal_number,
                ]
            );

            return $reversalJournal;
        });
    }

    private function assertAccountUsable(?ChartOfAccount $account, string $label): void
    {
        if (! $account) {
            throw ValidationException::withMessages([
                'account' => ["Akun {$label} tidak ditemukan."],
            ]);
        }

        if ($account->accounting_context !== AccountingContext::SHM) {
            throw ValidationException::withMessages([
                'account' => ["Akun {$label} [{$account->code}] bukan milik Akuntansi SHM (tercatat di {$account->accounting_context})."],
            ]);
        }

        if (! $account->is_active) {
            throw ValidationException::withMessages([
                'account' => ["Akun {$label} [{$account->code}] berstatus nonaktif dan tidak dapat digunakan."],
            ]);
        }
    }
}
