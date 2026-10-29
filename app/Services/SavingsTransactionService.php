<?php

namespace App\Services;

use App\Models\CashTransaction;
use App\Models\SavingsAccount;
use App\Models\SavingsTransaction;
use App\Support\DbTransaction;
use Illuminate\Validation\ValidationException;

class SavingsTransactionService
{
    public function __construct(private CashierService $cashierService) {}

    /**
     * Catat transaksi setoran.
     *
     * @param  array{amount: numeric, transaction_date: string, description: ?string, idempotency_key: ?string, record_cash_impact?: bool}  $data
     */
    public function deposit(SavingsAccount $account, array $data): SavingsTransaction
    {
        return $this->record($account, 'deposit', $data);
    }

    /**
     * Catat transaksi setoran tanpa dampak kas.
     *
     * @param  array{amount: numeric, transaction_date: string, description: ?string, idempotency_key: ?string}  $data
     */
    public function depositWithoutCashImpact(SavingsAccount $account, array $data): SavingsTransaction
    {
        $data['record_cash_impact'] = false;

        return $this->record($account, 'deposit', $data);
    }

    /**
     * Catat transaksi penarikan. Menolak jika saldo tidak mencukupi.
     *
     * @param  array{amount: numeric, transaction_date: string, description: ?string, idempotency_key: ?string}  $data
     *
     * @throws ValidationException jika saldo tidak mencukupi
     */
    public function withdraw(SavingsAccount $account, array $data): SavingsTransaction
    {
        return $this->record($account, 'withdrawal', $data);
    }

    /**
     * Proses transaksi secara atomik dengan row locking PostgreSQL.
     *
     * @param  'deposit'|'withdrawal'  $type
     * @param  array{amount: numeric, transaction_date: string, description: ?string, idempotency_key: ?string, processed_by: int}  $data
     */
    private function record(SavingsAccount $account, string $type, array $data): SavingsTransaction
    {
        // Idempotensi: kembalikan transaksi yang sudah ada jika kunci sama.
        if (! empty($data['idempotency_key'])) {
            $existing = SavingsTransaction::where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing !== null) {
                return $existing;
            }
        }

        return DbTransaction::run(function () use ($account, $type, $data) {
            // Lock rekening untuk mencegah race condition saldo.
            $lockedAccount = SavingsAccount::with(['savingProduct', 'member'])
                ->lockForUpdate()
                ->findOrFail($account->id);

            // Kelayakan transaksi (A.6 / SAV-08): hanya akun aktif dan anggota
            // yang memenuhi status kelayakan (default: anggota harus aktif;
            // dapat dikonfigurasi di config/business-rules.php).
            if ($lockedAccount->status !== 'active') {
                throw ValidationException::withMessages([
                    'account' => ['Rekening simpanan tidak aktif dan tidak dapat bertransaksi.'],
                ]);
            }

            // Ticket 20: produk simpanan nonaktif tidak dapat digunakan untuk
            // transaksi baru. Rekening & histori lama tetap dapat dilihat.
            if ($lockedAccount->savingProduct !== null && ! $lockedAccount->savingProduct->is_active) {
                throw ValidationException::withMessages([
                    'account' => ['Produk simpanan rekening ini sudah dinonaktifkan; transaksi baru tidak dapat diproses.'],
                ]);
            }

            $allowInactive = (bool) config('business-rules.savings.allow_transactions_for_inactive_member', false);
            if (! $allowInactive && $lockedAccount->member && $lockedAccount->member->status !== 'active') {
                throw ValidationException::withMessages([
                    'member' => ['Anggota berstatus nonaktif; transaksi simpanan dinonaktifkan. Hubungi administrator koperasi.'],
                ]);
            }

            // Re-check idempotensi setelah lock: request berulang yang datang
            // bersamaan tetap mengembalikan transaksi pertama tanpa dampak ganda.
            if (! empty($data['idempotency_key'])) {
                $existing = SavingsTransaction::where('idempotency_key', $data['idempotency_key'])->first();

                if ($existing !== null) {
                    return $existing;
                }
            }

            $amount = (float) $data['amount'];

            $minAmount = (float) config('business-rules.savings.min_transaction_amount', 0.01);
            if ($amount < $minAmount) {
                throw ValidationException::withMessages([
                    'amount' => ['Nominal transaksi minimal Rp '.number_format($minAmount, 2, ',', '.')],
                ]);
            }

            if ($type === 'withdrawal') {
                if ((float) $lockedAccount->balance < $amount) {
                    throw ValidationException::withMessages([
                        'amount' => ['Saldo tidak mencukupi. Saldo saat ini: '.number_format((float) $lockedAccount->balance, 2, ',', '.')],
                    ]);
                }
                $lockedAccount->balance = (string) ((float) $lockedAccount->balance - $amount);
            } else {
                $lockedAccount->balance = (string) ((float) $lockedAccount->balance + $amount);
            }

            $lockedAccount->save();

            $transaction = SavingsTransaction::create([
                'transaction_number' => $this->generateTransactionNumber(),
                'savings_account_id' => $lockedAccount->id,
                'type' => $type,
                'amount' => $amount,
                'transaction_date' => $data['transaction_date'],
                'description' => $data['description'] ?? null,
                'processed_by' => $data['processed_by'],
                'status' => 'completed',
                'idempotency_key' => $data['idempotency_key'] ?? null,
            ]);

            AuditLogger::log(
                $type === 'deposit' ? 'savings.transaction.deposit' : 'savings.transaction.withdrawal',
                SavingsTransaction::class,
                $transaction->id,
                $transaction->transaction_number,
                sprintf(
                    '%s simpanan Rp %s pada rekening %s (saldo menjadi Rp %s).',
                    $type === 'deposit' ? 'Setoran' : 'Penarikan',
                    number_format($amount, 2, ',', '.'),
                    $lockedAccount->account_number,
                    number_format((float) $lockedAccount->balance, 2, ',', '.')
                ),
                [
                    'type' => $type,
                    'amount' => $amount,
                    'account_number' => $lockedAccount->account_number,
                    'balance_after' => round((float) $lockedAccount->balance, 2),
                    'processed_by' => $data['processed_by'],
                ],
            );

            // Integrasi kas: setoran tunai menambah kas, penarikan tunai mengurangi kas.
            // Idempotensi dijaga melalui referensi transaksi sumber sehingga satu transaksi
            // simpanan tidak pernah menghasilkan dampak kas ganda.
            $recordCashImpact = $data['record_cash_impact'] ?? true;
            if ($recordCashImpact) {
                $isDeposit = $type === 'deposit';

                $this->cashierService->recordSourceImpact(
                    $isDeposit ? CashTransaction::TYPE_INCOME : CashTransaction::TYPE_EXPENSE,
                    $isDeposit
                        ? config('cashier.source_categories.savings_deposit')
                        : config('cashier.source_categories.savings_withdrawal'),
                    $amount,
                    $data['transaction_date'],
                    ($isDeposit ? 'Setoran simpanan ' : 'Penarikan simpanan ').$lockedAccount->account_number,
                    $transaction,
                    $data['processed_by'],
                );
            }

            return $transaction;
        });
    }

    /**
     * Koreksi transaksi simpanan melalui reversal teraudit (A.7/A.8).
     *
     * Transaksi sukses tidak boleh diubah/dihapus langsung; reversal membuat
     * transaksi penyesuaian baru yang mereferensikan transaksi asal, dengan
     * dampak saldo dan kas yang berlawanan.
     */
    public function reverse(SavingsTransaction $transaction, int $userId, string $reason, bool $recordCashImpact = true): SavingsTransaction
    {
        // Ticket 16: seluruh pemeriksaan state dievaluasi DI DALAM transaksi
        // database setelah baris transaksi dikunci, sehingga dua reversal
        // konkuren terhadap transaksi yang sama tidak dapat lolos bersamaan
        // (proteksi double-reversal benar-benar atomik, bukan hanya check-then-act).
        return DbTransaction::run(function () use ($transaction, $userId, $reason, $recordCashImpact) {
            $lockedTransaction = SavingsTransaction::lockForUpdate()->findOrFail($transaction->id);

            if ($lockedTransaction->status !== 'completed') {
                throw ValidationException::withMessages([
                    'transaction' => ['Hanya transaksi berstatus completed yang dapat direversi.'],
                ]);
            }

            if ($lockedTransaction->entry_type === 'reversal') {
                throw ValidationException::withMessages([
                    'transaction' => ['Transaksi penyesuaian (reversal) tidak dapat direversi kembali.'],
                ]);
            }

            if ($lockedTransaction->reversals()->where('status', 'completed')->exists()) {
                throw ValidationException::withMessages([
                    'transaction' => ['Transaksi ini sudah pernah direversi.'],
                ]);
            }

            $lockedAccount = SavingsAccount::with(['savingProduct', 'member'])
                ->lockForUpdate()
                ->findOrFail($lockedTransaction->savings_account_id);

            // Ticket 20: kelayakan reversal dievaluasi terhadap status rekening
            // & anggota SAAT INI (bukan status saat transaksi asal dibuat).
            // Catatan: produk nonaktif TIDAK memblokir reversal — koreksi atas
            // transaksi masa lalu tetap harus dimungkinkan.
            if ($lockedAccount->status !== 'active') {
                throw ValidationException::withMessages([
                    'transaction' => ['Rekening simpanan tidak aktif; reversal tidak dapat diproses.'],
                ]);
            }

            $allowInactive = (bool) config('business-rules.savings.allow_transactions_for_inactive_member', false);
            if (! $allowInactive && $lockedAccount->member && $lockedAccount->member->status !== 'active') {
                throw ValidationException::withMessages([
                    'transaction' => ['Anggota berstatus nonaktif; reversal tidak dapat diproses.'],
                ]);
            }

            $reversalType = $lockedTransaction->type === 'deposit' ? 'withdrawal' : 'deposit';

            // Reversal menyalin aturan transaksi normal (saldo & kas berlawanan).
            $reversal = $this->record($lockedAccount, $reversalType, [
                'amount' => $lockedTransaction->amount,
                'transaction_date' => now()->format('Y-m-d'),
                'description' => 'Reversal '.$transaction->transaction_number.': '.$reason,
                'idempotency_key' => null,
                'processed_by' => $userId,
                'record_cash_impact' => $recordCashImpact,
            ]);

            $reversal->forceFill([
                'reversal_of_id' => $transaction->id,
                'entry_type' => 'reversal',
            ])->save();

            AuditLogger::log(
                'savings.transaction.reversed',
                SavingsTransaction::class,
                $reversal->id,
                $reversal->transaction_number,
                sprintf('Reversal transaksi %s oleh pengguna #%d. Alasan: %s', $lockedTransaction->transaction_number, $userId, $reason),
                [
                    'original_transaction' => $lockedTransaction->transaction_number,
                    'reversal_transaction' => $reversal->transaction_number,
                    'amount' => (float) $lockedTransaction->amount,
                    'reason' => $reason,
                    'processed_by' => $userId,
                ],
            );

            return $reversal;
        });
    }

    /**
     * Koreksi transaksi simpanan melalui reversal tanpa dampak kas (non-cash impact).
     */
    public function reverseWithoutCashImpact(SavingsTransaction $transaction, int $userId, string $reason): SavingsTransaction
    {
        return $this->reverse($transaction, $userId, $reason, false);
    }

    /**
     * Hasilkan nomor transaksi unik dengan format TRX-YYYYMMDD-XXXXXXXX.
     */
    private function generateTransactionNumber(): string
    {
        $date = now()->format('Ymd');
        $random = strtoupper(substr(uniqid('', true), -8));

        return "TRX-{$date}-{$random}";
    }
}
