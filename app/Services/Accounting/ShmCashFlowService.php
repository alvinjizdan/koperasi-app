<?php

namespace App\Services\Accounting;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\ShmCashTransaction;
use App\Models\ShmRefundAccountingLink;
use App\Models\ShmSettlementRefund;
use App\Services\AuditLogger;
use App\Services\Shm\ShmSettlementRefundService;
use App\Support\AccountingContext;
use App\Support\DbTransaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class ShmCashFlowService
{
    public function __construct(
        protected JournalService $journalService,
        protected ?ShmSettlementRefundService $refundService = null,
    ) {
        $this->refundService = $this->refundService ?? app(ShmSettlementRefundService::class);
    }

    /**
     * Create a new draft cash transaction.
     */
    public function createDraft(string $type, array $data, int $userId): ShmCashTransaction
    {
        $this->validateAccounts($data['cash_account_id'], $data['account_id']);

        $refundId = $data['shm_settlement_refund_id'] ?? null;
        if ($refundId !== null) {
            $this->validateRefundForOutflow($refundId, (float) $data['amount']);
        }

        return DbTransaction::run(function () use ($type, $data, $userId, $refundId) {
            $transactionNumber = $this->generateTransactionNumber($type, $data['transaction_date']);

            return ShmCashTransaction::create([
                'transaction_number' => $transactionNumber,
                'type' => $type,
                'transaction_date' => $data['transaction_date'],
                'cash_account_id' => $data['cash_account_id'],
                'account_id' => $data['account_id'],
                'shm_settlement_refund_id' => $refundId,
                'amount' => $data['amount'],
                'description' => $data['description'] ?? null,
                'status' => ShmCashTransaction::STATUS_DRAFT,
                'created_by' => $userId,
            ]);
        });
    }

    /**
     * Update an existing draft cash transaction.
     */
    public function updateDraft(ShmCashTransaction $transaction, array $data, int $userId): ShmCashTransaction
    {
        if ($transaction->status !== ShmCashTransaction::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'status' => ['Hanya transaksi berstatus DRAFT yang dapat diperbarui.'],
            ]);
        }

        $cashAccountId = $data['cash_account_id'] ?? $transaction->cash_account_id;
        $accountId = $data['account_id'] ?? $transaction->account_id;
        $amount = (float) ($data['amount'] ?? $transaction->amount);
        $refundId = array_key_exists('shm_settlement_refund_id', $data) ? $data['shm_settlement_refund_id'] : $transaction->shm_settlement_refund_id;

        $this->validateAccounts($cashAccountId, $accountId);

        if ($refundId !== null) {
            $this->validateRefundForOutflow($refundId, $amount, $transaction->id);
        }

        return DbTransaction::run(function () use ($transaction, $data, $cashAccountId, $accountId, $refundId) {
            $transaction->update([
                'transaction_date' => $data['transaction_date'] ?? $transaction->transaction_date,
                'cash_account_id' => $cashAccountId,
                'account_id' => $accountId,
                'shm_settlement_refund_id' => $refundId,
                'amount' => $data['amount'] ?? $transaction->amount,
                'description' => array_key_exists('description', $data) ? $data['description'] : $transaction->description,
            ]);

            return $transaction->fresh(['cashAccount', 'counterpartAccount']);
        });
    }

    /**
     * Validasi kelayakan refund untuk dikaitkan dengan Arus Kas Keluar.
     */
    private function validateRefundForOutflow(int $refundId, float $amount, ?int $excludeTransactionId = null): void
    {
        $refund = ShmSettlementRefund::findOrFail($refundId);

        if (! in_array($refund->status, [ShmSettlementRefund::STATUS_PAID, ShmSettlementRefund::STATUS_RECOVERY_PENDING, ShmSettlementRefund::STATUS_RECOVERED], true)) {
            throw ValidationException::withMessages([
                'shm_settlement_refund_id' => [sprintf('Refund %s belum berstatus dibayarkan (paid). Status: "%s".', $refund->refund_reference, $refund->status)],
            ]);
        }

        if (round((float) $refund->amount, 2) !== round($amount, 2)) {
            throw ValidationException::withMessages([
                'amount' => [sprintf(
                    'Nominal pengeluaran kas (Rp %s) harus sama dengan nominal refund %s (Rp %s).',
                    number_format($amount, 2, ',', '.'),
                    $refund->refund_reference,
                    number_format((float) $refund->amount, 2, ',', '.')
                )],
            ]);
        }

        // Cek apakah sudah pernah diakui di link accounting POSTED
        $existingLink = ShmRefundAccountingLink::query()
            ->where('shm_settlement_refund_id', $refund->id)
            ->where('status', ShmRefundAccountingLink::STATUS_POSTED)
            ->first();

        if ($existingLink !== null) {
            throw ValidationException::withMessages([
                'shm_settlement_refund_id' => [sprintf('Refund %s sudah diakui dalam akuntansi melalui Jurnal #%d (%s).', $refund->refund_reference, $existingLink->journal_entry_id, $existingLink->recognition_channel)],
            ]);
        }

        // Cek apakah sudah ada transaksi kas lain yang aktif mengaitkan refund ini
        $dupCash = ShmCashTransaction::query()
            ->where('shm_settlement_refund_id', $refund->id)
            ->where('status', '!=', ShmCashTransaction::STATUS_REVERSED)
            ->when($excludeTransactionId, fn ($q) => $q->where('id', '!=', $excludeTransactionId))
            ->first();

        if ($dupCash !== null) {
            throw ValidationException::withMessages([
                'shm_settlement_refund_id' => [sprintf('Refund %s sudah dikaitkan pada Transaksi Kas %s (%s).', $refund->refund_reference, $dupCash->transaction_number, $dupCash->status)],
            ]);
        }
    }

    /**
     * Delete an existing draft cash transaction.
     */
    public function deleteDraft(ShmCashTransaction $transaction, ?int $userId = null, ?string $reason = null): bool
    {
        return DbTransaction::run(function () use ($transaction, $userId, $reason): bool {
            $locked = ShmCashTransaction::query()->lockForUpdate()->find($transaction->id);

            if ($locked === null) {
                return true; // Already deleted
            }

            if ($locked->status !== ShmCashTransaction::STATUS_DRAFT) {
                throw ValidationException::withMessages([
                    'status' => ['Hanya transaksi berstatus DRAFT yang dapat dihapus.'],
                ]);
            }

            $actorId = $userId ?? Auth::id();

            // Snapshot data yang diperlukan untuk audit trail permanen
            $payload = [
                'event_name' => 'shm_cash_transaction.draft_deleted',
                'transaction_id' => $locked->id,
                'transaction_number' => $locked->transaction_number,
                'type' => $locked->type,
                'amount' => (float) $locked->amount,
                'transaction_date' => $locked->transaction_date?->format('Y-m-d'),
                'cash_account_id' => $locked->cash_account_id,
                'account_id' => $locked->account_id,
                'previous_status' => $locked->status,
                'actor_id' => $actorId,
                'shm_settlement_refund_id' => $locked->shm_settlement_refund_id,
                'reason' => $reason,
                'timestamp' => now()->toIso8601String(),
            ];

            // Tulis audit log di dalam transaksi database yang sama (Atomic)
            AuditLogger::log(
                action: 'shm_cash_transaction.draft_deleted',
                objectType: 'ShmCashTransaction',
                objectId: $locked->id,
                objectLabel: $locked->transaction_number,
                description: sprintf(
                    'Menghapus draft transaksi kas %s (%s) sejumlah Rp %s',
                    $locked->transaction_number,
                    $locked->type,
                    number_format((float) $locked->amount, 0, ',', '.')
                ),
                changes: $payload,
                actorId: $actorId,
            );

            return (bool) $locked->delete();
        });
    }

    /**
     * Post a draft transaction to the SHM double-entry ledger.
     */
    public function post(ShmCashTransaction $transaction, int $userId): ShmCashTransaction
    {
        return DbTransaction::run(function () use ($transaction, $userId) {
            $locked = ShmCashTransaction::query()->lockForUpdate()->findOrFail($transaction->id);

            if ($locked->status !== ShmCashTransaction::STATUS_DRAFT) {
                throw ValidationException::withMessages([
                    'status' => ['Transaksi ini tidak dapat diposting karena berstatus '.$locked->status.'.'],
                ]);
            }

            if ($locked->journal_entry_id !== null) {
                throw ValidationException::withMessages([
                    'status' => ['Transaksi sudah memiliki jurnal dan tidak dapat diposting dua kali.'],
                ]);
            }

            // Build double entry lines based on type
            // INFLOW (Arus Kas Masuk): Kas/Bank (Debit), Akun Lawan (Credit)
            // OUTFLOW (Arus Kas Keluar): Akun Lawan (Debit), Kas/Bank (Credit)
            $amount = (float) $locked->amount;
            $cashDesc = ($locked->type === ShmCashTransaction::TYPE_INFLOW ? 'Penerimaan Kas: ' : 'Pengeluaran Kas: ').($locked->description ?: $locked->transaction_number);
            $counterpartDesc = $locked->description ?: $locked->transaction_number;

            if ($locked->type === ShmCashTransaction::TYPE_INFLOW) {
                $lines = [
                    [
                        'account_id' => $locked->cash_account_id,
                        'description' => $cashDesc,
                        'debit' => $amount,
                        'credit' => 0,
                    ],
                    [
                        'account_id' => $locked->account_id,
                        'description' => $counterpartDesc,
                        'debit' => 0,
                        'credit' => $amount,
                    ],
                ];
            } else {
                $lines = [
                    [
                        'account_id' => $locked->account_id,
                        'description' => $counterpartDesc,
                        'debit' => $amount,
                        'credit' => 0,
                    ],
                    [
                        'account_id' => $locked->cash_account_id,
                        'description' => $cashDesc,
                        'debit' => 0,
                        'credit' => $amount,
                    ],
                ];
            }

            $journalData = [
                'accounting_context' => AccountingContext::SHM,
                'transaction_date' => $locked->transaction_date->format('Y-m-d'),
                'description' => ($locked->type === ShmCashTransaction::TYPE_INFLOW ? 'Arus Kas Masuk: ' : 'Arus Kas Keluar: ').($locked->description ?: $locked->transaction_number),
                'source_type' => ShmCashTransaction::class,
                'source_id' => $locked->id,
                'posting_type' => $locked->type === ShmCashTransaction::TYPE_INFLOW ? 'shm_cash_inflow' : 'shm_cash_outflow',
            ];

            $draftEntry = $this->journalService->createDraft($journalData, $lines, $userId);
            $postedEntry = $this->journalService->post($draftEntry, $userId);

            // BR-42B-02 & BR-42B-03: Jika berkaitan dengan refund payout, catat structured accounting link
            if ($locked->shm_settlement_refund_id !== null) {
                $refund = ShmSettlementRefund::findOrFail($locked->shm_settlement_refund_id);
                $this->refundService->linkAccountingRecognition(
                    $refund,
                    $postedEntry->id,
                    ShmRefundAccountingLink::CHANNEL_CASH_OUTFLOW,
                    $userId,
                    $locked->id,
                    'Pengakuan akuntansi melalui Arus Kas Keluar: '.$locked->transaction_number
                );
            }

            $locked->update([
                'status' => ShmCashTransaction::STATUS_POSTED,
                'journal_entry_id' => $postedEntry->id,
                'posted_by' => $userId,
                'posted_at' => now(),
            ]);

            return $locked->fresh(['cashAccount', 'counterpartAccount', 'journalEntry']);
        });
    }

    /**
     * Reverse an already POSTED transaction.
     */
    public function reverse(ShmCashTransaction $transaction, int $userId, string $reason): ShmCashTransaction
    {
        return DbTransaction::run(function () use ($transaction, $userId, $reason) {
            $locked = ShmCashTransaction::query()->lockForUpdate()->findOrFail($transaction->id);

            if ($locked->status !== ShmCashTransaction::STATUS_POSTED) {
                throw ValidationException::withMessages([
                    'status' => ['Hanya transaksi POSTED yang dapat dibatalkan/direversi.'],
                ]);
            }

            if (! $locked->journal_entry_id) {
                throw ValidationException::withMessages([
                    'journal' => ['Transaksi tidak memiliki jurnal terkait untuk direversi.'],
                ]);
            }

            $journalEntry = JournalEntry::findOrFail($locked->journal_entry_id);
            $this->journalService->reverse($journalEntry, $userId, $reason);

            // Jika berkaitan dengan refund, batalkan accounting recognition link
            if ($locked->shm_settlement_refund_id !== null) {
                $this->refundService->reverseAccountingRecognition($journalEntry->id, $userId);
            }

            $locked->update([
                'status' => ShmCashTransaction::STATUS_REVERSED,
                'reversed_by' => $userId,
                'reversed_at' => now(),
                'reversal_reason' => $reason,
            ]);

            return $locked->fresh(['cashAccount', 'counterpartAccount', 'journalEntry']);
        });
    }

    /**
     * Validate that both accounts belong to SHM context and are active.
     */
    protected function validateAccounts(int $cashAccountId, int $accountId): void
    {
        $cashAccount = ChartOfAccount::find($cashAccountId);
        if (! $cashAccount || $cashAccount->accounting_context !== AccountingContext::SHM || ! $cashAccount->is_active) {
            throw ValidationException::withMessages([
                'cash_account_id' => ['Akun Kas/Bank harus merupakan akun SHM yang aktif.'],
            ]);
        }

        $counterpartAccount = ChartOfAccount::find($accountId);
        if (! $counterpartAccount || $counterpartAccount->accounting_context !== AccountingContext::SHM || ! $counterpartAccount->is_active) {
            throw ValidationException::withMessages([
                'account_id' => ['Akun Nama Perkiraan harus merupakan akun SHM yang aktif.'],
            ]);
        }

        if ($cashAccountId === $accountId) {
            throw ValidationException::withMessages([
                'account_id' => ['Akun Kas/Bank dan Akun Lawan tidak boleh sama.'],
            ]);
        }
    }

    /**
     * Generate unique transaction number.
     * Example: KM-SHM-20261112-0001 or KK-SHM-20261112-0001
     */
    protected function generateTransactionNumber(string $type, string $transactionDate): string
    {
        $prefix = $type === ShmCashTransaction::TYPE_INFLOW ? 'KM-SHM' : 'KK-SHM';
        $dateStr = Carbon::parse($transactionDate)->format('Ymd');
        $base = "{$prefix}-{$dateStr}-";

        $count = ShmCashTransaction::where('transaction_number', 'like', "{$base}%")->count();
        $seq = str_pad((string) ($count + 1), 4, '0', STR_PAD_LEFT);

        return "{$base}{$seq}";
    }
}
