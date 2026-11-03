<?php

namespace App\Services\Shm;

use App\Models\Shm\ShmSavingsAccount;
use App\Models\Shm\ShmSavingsTransaction;
use App\Support\DbTransaction;
use Illuminate\Validation\ValidationException;

class ShmSavingsTransactionService
{
    /**
     * Catat transaksi setoran pada simpanan SHM.
     *
     * @param  array{amount: numeric, transaction_date: string, description: ?string, idempotency_key: ?string, processed_by?: int}  $data
     */
    public function deposit(ShmSavingsAccount $account, array $data): ShmSavingsTransaction
    {
        return $this->record($account, 'deposit', $data);
    }

    /**
     * Catat transaksi penarikan pada simpanan SHM.
     *
     * @param  array{amount: numeric, transaction_date: string, description: ?string, idempotency_key: ?string, processed_by?: int}  $data
     */
    public function withdraw(ShmSavingsAccount $account, array $data): ShmSavingsTransaction
    {
        return $this->record($account, 'withdrawal', $data);
    }

    /**
     * Proses transaksi secara atomik dengan row locking.
     */
    protected function record(ShmSavingsAccount $account, string $type, array $data): ShmSavingsTransaction
    {
        return DbTransaction::run(function () use ($account, $type, $data) {
            $locked = ShmSavingsAccount::lockForUpdate()->findOrFail($account->id);

            if ($locked->status !== 'active') {
                throw ValidationException::withMessages([
                    'savings' => ['Rekening simpanan SHM tidak aktif.'],
                ]);
            }

            if (! empty($data['idempotency_key'])) {
                $existing = ShmSavingsTransaction::where('idempotency_key', $data['idempotency_key'])->first();
                if ($existing) {
                    return $existing;
                }
            }

            $amount = (float) $data['amount'];
            if ($amount <= 0) {
                throw ValidationException::withMessages([
                    'amount' => ['Nominal transaksi harus lebih besar dari 0.'],
                ]);
            }

            if ($type === 'withdrawal' && (float) $locked->balance < $amount) {
                throw ValidationException::withMessages([
                    'amount' => ['Saldo rekening simpanan SHM tidak mencukupi.'],
                ]);
            }

            $newBalance = $type === 'deposit'
                ? (float) $locked->balance + $amount
                : (float) $locked->balance - $amount;

            $locked->update(['balance' => $newBalance]);

            return ShmSavingsTransaction::create([
                'transaction_number' => ShmSavingsTransaction::generateTransactionNumber($type),
                'shm_savings_account_id' => $locked->id,
                'type' => $type,
                'amount' => $amount,
                'transaction_date' => $data['transaction_date'] ?? now()->toDateString(),
                'description' => $data['description'] ?? null,
                'processed_by' => $data['processed_by'] ?? auth()->id() ?? 1,
                'status' => 'completed',
                'idempotency_key' => $data['idempotency_key'] ?? null,
            ]);
        });
    }

    /**
     * Reversal transaksi simpanan SHM.
     */
    public function reverse(ShmSavingsTransaction $transaction, int $actorId, string $reason): ShmSavingsTransaction
    {
        return DbTransaction::run(function () use ($transaction, $actorId, $reason) {
            $lockedTx = ShmSavingsTransaction::lockForUpdate()->findOrFail($transaction->id);

            if ($lockedTx->status === 'reversed') {
                throw ValidationException::withMessages([
                    'status' => ['Transaksi ini sudah pernah dibatalkan/di-reverse.'],
                ]);
            }

            $account = ShmSavingsAccount::lockForUpdate()->findOrFail($lockedTx->shm_savings_account_id);

            $reversalType = $lockedTx->type === 'deposit' ? 'withdrawal' : 'deposit';
            $amount = (float) $lockedTx->amount;

            if ($reversalType === 'withdrawal' && (float) $account->balance < $amount) {
                throw ValidationException::withMessages([
                    'amount' => ['Saldo tidak mencukupi untuk membatalkan setoran ini.'],
                ]);
            }

            $newBalance = $reversalType === 'deposit'
                ? (float) $account->balance + $amount
                : (float) $account->balance - $amount;

            $account->update(['balance' => $newBalance]);
            $lockedTx->update(['status' => 'reversed']);

            return ShmSavingsTransaction::create([
                'transaction_number' => ShmSavingsTransaction::generateTransactionNumber($reversalType),
                'shm_savings_account_id' => $account->id,
                'type' => $reversalType,
                'amount' => $amount,
                'transaction_date' => now()->toDateString(),
                'description' => "Reversal {$lockedTx->transaction_number}: {$reason}",
                'processed_by' => $actorId,
                'status' => 'completed',
                'reversal_of_id' => $lockedTx->id,
            ]);
        });
    }
}
