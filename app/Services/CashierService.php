<?php

namespace App\Services;

use App\Models\CashierSession;
use App\Models\CashTransaction;
use App\Support\DbTransaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

class CashierService
{
    /**
     * Sesi kasir yang masih terbuka milik operator tertentu.
     */
    public function activeSessionFor(int $userId): ?CashierSession
    {
        return CashierSession::query()
            ->open()
            ->where('opened_by', $userId)
            ->first();
    }

    /**
     * Buka sesi kasir baru.
     *
     * @param  array{operational_date: string, opening_balance: numeric, opening_notes: ?string}  $data
     *
     * @throws ValidationException bila operator masih memiliki sesi terbuka atau saldo awal negatif
     */
    public function openSession(array $data, int $userId): CashierSession
    {
        $openingBalance = round((float) $data['opening_balance'], 2);

        if ($openingBalance < 0) {
            throw ValidationException::withMessages([
                'opening_balance' => ['Saldo awal kas tidak boleh negatif.'],
            ]);
        }

        // Cegah pembukaan ulang sesi yang sudah terbuka. Saldo awal TIDAK diambil
        // otomatis dari sesi sebelumnya: mekanisme pergantian hari belum dikonfirmasi.
        $activeSession = $this->activeSessionFor($userId);

        if ($activeSession !== null) {
            throw ValidationException::withMessages([
                'operational_date' => [
                    'Anda masih memiliki sesi kasir yang terbuka ('.$activeSession->session_number.'). Tutup sesi tersebut sebelum membuka sesi baru.',
                ],
            ]);
        }

        try {
            // Atomik: pembuatan sesi dan audit trail dibuat dalam satu transaksi.
            $session = DbTransaction::run(function () use ($data, $userId, $openingBalance) {
                $session = CashierSession::create([
                    'session_number' => CashierSession::generateSessionNumber(),
                    'operational_date' => $data['operational_date'],
                    'opened_by' => $userId,
                    'opening_balance' => $openingBalance,
                    'opening_notes' => $data['opening_notes'] ?? null,
                    'status' => CashierSession::STATUS_OPEN,
                    'opened_at' => now(),
                ]);

                AuditLogger::log(
                    'cashier.session.opened',
                    CashierSession::class,
                    $session->id,
                    $session->session_number,
                    sprintf('Sesi kasir dibuka untuk tanggal operasional %s.', $session->operational_date->format('Y-m-d')),
                    [
                        'opening_balance' => $openingBalance,
                        'operator_id' => $userId,
                        'operational_date' => $session->operational_date->format('Y-m-d'),
                    ],
                );

                return $session;
            });
        } catch (QueryException $e) {
            // Safety net: partial unique index mencegah dua sesi OPEN untuk operator yang sama.
            throw ValidationException::withMessages([
                'operational_date' => ['Sesi kasir untuk operator ini sudah terbuka.'],
            ]);
        }

        return $session;
    }

    /**
     * Tutup sesi kasir dan simpan snapshot saldo akhir.
     *
     * @param  array{physical_closing_balance: numeric, closing_notes: ?string}  $data
     *
     * @throws ValidationException bila sesi sudah ditutup, atau selisih kas tanpa catatan
     */
    public function closeSession(CashierSession $session, array $data, int $userId): CashierSession
    {
        return DbTransaction::run(function () use ($session, $data, $userId) {
            // Row locking: mencegah penutupan ganda dan transaksi bersamaan saat penutupan.
            $lockedSession = CashierSession::lockForUpdate()->findOrFail($session->id);

            if (! $lockedSession->isOpen()) {
                throw ValidationException::withMessages([
                    'status' => ['Sesi kasir ini sudah ditutup sebelumnya.'],
                ]);
            }

            $totals = $this->totals($lockedSession);
            $physicalBalance = round((float) $data['physical_closing_balance'], 2);
            $difference = round($physicalBalance - $totals['closing_balance'], 2);
            $closingNotes = $data['closing_notes'] ?? null;

            if (round($difference, 2) !== 0.0 && blank($closingNotes)) {
                throw ValidationException::withMessages([
                    'closing_notes' => ['Catatan penjelasan wajib diisi karena terdapat selisih kas.'],
                ]);
            }

            $lockedSession->update([
                'status' => CashierSession::STATUS_CLOSED,
                'total_income' => $totals['income'],
                'total_expense' => $totals['expense'],
                'total_deposit' => $totals['deposit'],
                'system_closing_balance' => $totals['closing_balance'],
                'physical_closing_balance' => $physicalBalance,
                'difference' => $difference,
                'closed_at' => now(),
                'closed_by' => $userId,
                'closing_notes' => $closingNotes,
            ]);

            AuditLogger::log(
                'cashier.session.closed',
                CashierSession::class,
                $lockedSession->id,
                $lockedSession->session_number,
                'Sesi kasir ditutup beserta snapshot saldo akhir.',
                [
                    'system_closing_balance' => $totals['closing_balance'],
                    'physical_closing_balance' => $physicalBalance,
                    'difference' => $difference,
                    'closed_by' => $userId,
                ],
            );

            return $lockedSession;
        });
    }

    /**
     * Ringkasan transaksi sah (POSTED) pada satu sesi kasir.
     *
     * @return array{opening_balance: float, income: float, expense: float, deposit: float, closing_balance: float}
     */
    public function totals(CashierSession $session): array
    {
        $totals = $session->transactions()
            ->where('status', CashTransaction::STATUS_POSTED)
            ->selectRaw('type, COALESCE(SUM(amount), 0) AS total')
            ->groupBy('type')
            ->pluck('total', 'type');

        $openingBalance = round((float) $session->opening_balance, 2);
        $income = round((float) ($totals[CashTransaction::TYPE_INCOME] ?? 0), 2);
        $expense = round((float) ($totals[CashTransaction::TYPE_EXPENSE] ?? 0), 2);
        $deposit = round((float) ($totals[CashTransaction::TYPE_DEPOSIT] ?? 0), 2);

        return [
            'opening_balance' => $openingBalance,
            'income' => $income,
            'expense' => $expense,
            'deposit' => $deposit,
            'closing_balance' => round($openingBalance + $income - $expense - $deposit, 2),
        ];
    }

    /**
     * Catat penerimaan kas pada sesi yang masih terbuka.
     *
     * @param  array{amount: numeric, category: string, transaction_date: string, description: ?string, idempotency_key: ?string}  $data
     */
    public function recordIncome(CashierSession $session, array $data, int $userId): CashTransaction
    {
        return $this->record($session, CashTransaction::TYPE_INCOME, $data, $userId);
    }

    /**
     * Catat pengeluaran kas pada sesi yang masih terbuka.
     *
     * @param  array{amount: numeric, category: string, transaction_date: string, description: ?string, document_reference: ?string, idempotency_key: ?string}  $data
     */
    public function recordExpense(CashierSession $session, array $data, int $userId): CashTransaction
    {
        return $this->record($session, CashTransaction::TYPE_EXPENSE, $data, $userId);
    }

    /**
     * Catat setoran kasir (perpindahan kas internal) pada sesi yang masih terbuka.
     *
     * @param  array{amount: numeric, transaction_date: string, description: string, idempotency_key: ?string}  $data
     */
    public function recordDeposit(CashierSession $session, array $data, int $userId): CashTransaction
    {
        return $this->record($session, CashTransaction::TYPE_DEPOSIT, [
            ...$data,
            'category' => config('cashier.deposit.category', 'Setoran Kasir'),
        ], $userId);
    }

    /**
     * Catat dampak kas dari transaksi modul lain (Simpanan, Pinjaman, Angsuran).
     *
     * Mengembalikan null bila operator tidak memiliki sesi kasir terbuka; dalam kondisi
     * tersebut transaksi sumber tetap sah namun tidak menghasilkan entri kas baru
     * (lihat pertanyaan terbuka pada laporan implementasi).
     *
     * @throws ValidationException bila saldo kas tidak mencukupi untuk pengeluaran
     */
    public function recordSourceImpact(
        string $type,
        string $category,
        float $amount,
        string $transactionDate,
        ?string $description,
        Model $source,
        int $userId,
    ): ?CashTransaction {
        // Idempotensi lintas modul: satu transaksi bisnis hanya menghasilkan satu dampak kas.
        $existing = $this->findBySource($source);

        if ($existing !== null) {
            return $existing;
        }

        $session = $this->activeSessionFor($userId);

        if ($session === null) {
            // Peristiwa penting untuk rekonsiliasi: transaksi bisnis sah tetapi
            // tidak menghasilkan dampak kas karena operator tidak memiliki sesi terbuka.
            AuditLogger::log(
                'cashier.source.skipped',
                $source::class,
                (int) $source->getKey(),
                null,
                'Dampak kas dari transaksi modul lain dilewati karena operator tidak memiliki sesi kasir terbuka.',
                [
                    'source_type' => $source::class,
                    'source_id' => $source->getKey(),
                    'operator_id' => $userId,
                ],
            );

            return null;
        }

        return DbTransaction::run(function () use ($type, $category, $amount, $transactionDate, $description, $source, $userId, $session) {
            $lockedSession = CashierSession::lockForUpdate()->findOrFail($session->id);

            if (! $lockedSession->isOpen()) {
                throw ValidationException::withMessages([
                    'cashier_session_id' => ['Sesi kasir sudah ditutup. Transaksi tidak dapat diproses.'],
                ]);
            }

            if ($this->findBySource($source) !== null) {
                return $this->findBySource($source);
            }

            $normalizedAmount = round($amount, 2);

            if ($type === CashTransaction::TYPE_EXPENSE) {
                $this->assertSufficientCash($lockedSession, $normalizedAmount);
            }

            return CashTransaction::create([
                'transaction_number' => CashTransaction::generateTransactionNumber($type),
                'cashier_session_id' => $lockedSession->id,
                'type' => $type,
                'category' => $category,
                'amount' => $normalizedAmount,
                'transaction_date' => $transactionDate,
                'transacted_at' => now(),
                'description' => $description,
                'processed_by' => $userId,
                'status' => CashTransaction::STATUS_POSTED,
                'source_type' => $source::class,
                'source_id' => $source->getKey(),
            ]);
        });
    }

    /**
     * Koreksi transaksi kas melalui pembatalan yang tercatat, bukan penghapusan fisik.
     *
     * Rekaman transaksi tidak pernah dihapus: statusnya diubah menjadi REVERSED beserta
     * jejak audit operator, waktu, dan alasan koreksi. Baris yang telah dibatalkan tidak
     * lagi dihitung pada saldo kas sehingga tidak ada dampak ganda.
     *
     * @throws ValidationException bila transaksi sudah dibatalkan, berasal dari modul lain,
     *                             atau sesi kasirnya sudah ditutup
     */
    public function reverse(CashTransaction $transaction, string $reason, int $userId): CashTransaction
    {
        return DbTransaction::run(function () use ($transaction, $reason, $userId) {
            $lockedTransaction = CashTransaction::lockForUpdate()->findOrFail($transaction->id);

            if (! $lockedTransaction->isPosted()) {
                throw ValidationException::withMessages([
                    'status' => ['Transaksi ini sudah dibatalkan sebelumnya.'],
                ]);
            }

            if ($lockedTransaction->source_type !== null) {
                throw ValidationException::withMessages([
                    'status' => ['Transaksi kas dari modul lain hanya dapat dikoreksi dari modul sumbernya.'],
                ]);
            }

            $session = $lockedTransaction->cashier_session_id !== null
                ? CashierSession::lockForUpdate()->find($lockedTransaction->cashier_session_id)
                : null;

            if ($session !== null && ! $session->isOpen()) {
                throw ValidationException::withMessages([
                    'status' => ['Sesi kasir transaksi ini sudah ditutup. Koreksi tidak dapat diproses.'],
                ]);
            }

            $lockedTransaction->update([
                'status' => CashTransaction::STATUS_REVERSED,
                'reversed_at' => now(),
                'reversed_by' => $userId,
                'reversal_reason' => $reason,
            ]);

            AuditLogger::log(
                'cashier.transaction.reversed',
                CashTransaction::class,
                $lockedTransaction->id,
                $lockedTransaction->transaction_number,
                'Transaksi kas dibatalkan melalui koreksi resmi.',
                [
                    'type' => $lockedTransaction->type,
                    'amount' => round((float) $lockedTransaction->amount, 2),
                    'reason' => $reason,
                    'reversed_by' => $userId,
                ],
            );

            return $lockedTransaction;
        });
    }

    /**
     * Catat transaksi kas pada sesi terbuka secara atomik.
     *
     * @param  array{amount: numeric, category: ?string, transaction_date: string, description: ?string, document_reference: ?string, idempotency_key: ?string}  $data
     */
    private function record(CashierSession $session, string $type, array $data, int $userId): CashTransaction
    {
        // Idempotensi: request berulang dengan kunci sama tidak menghasilkan transaksi ganda.
        if (! empty($data['idempotency_key'])) {
            $existing = CashTransaction::where('idempotency_key', $data['idempotency_key'])->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        return DbTransaction::run(function () use ($session, $type, $data, $userId) {
            $lockedSession = CashierSession::lockForUpdate()->findOrFail($session->id);

            if (! $lockedSession->isOpen()) {
                throw ValidationException::withMessages([
                    'cashier_session_id' => ['Sesi kasir sudah ditutup. Transaksi tidak dapat diproses.'],
                ]);
            }

            $amount = round((float) $data['amount'], 2);

            if ($amount <= 0) {
                throw ValidationException::withMessages([
                    'amount' => ['Nominal transaksi harus lebih besar dari 0.'],
                ]);
            }

            if ($type === CashTransaction::TYPE_EXPENSE) {
                $this->assertSufficientCash($lockedSession, $amount);
            }

            $transaction = CashTransaction::create([
                'transaction_number' => CashTransaction::generateTransactionNumber($type),
                'cashier_session_id' => $lockedSession->id,
                'type' => $type,
                'category' => $data['category'] ?? null,
                'amount' => $amount,
                'transaction_date' => $data['transaction_date'],
                'transacted_at' => now(),
                'description' => $data['description'] ?? null,
                'document_reference' => $data['document_reference'] ?? null,
                'processed_by' => $userId,
                'status' => CashTransaction::STATUS_POSTED,
                'idempotency_key' => $data['idempotency_key'] ?? null,
            ]);

            AuditLogger::log(
                'cashier.transaction.created',
                CashTransaction::class,
                $transaction->id,
                $transaction->transaction_number,
                sprintf('Transaksi kas %s dicatat pada sesi %s.', $transaction->type_label, $lockedSession->session_number),
                [
                    'type' => $type,
                    'amount' => $amount,
                    'category' => $data['category'] ?? null,
                    'session_number' => $lockedSession->session_number,
                    'processed_by' => $userId,
                ],
            );

            return $transaction;
        });
    }

    /**
     * Pastikan pengeluaran kas tidak membuat saldo kas negatif.
     *
     * @throws ValidationException
     */
    private function assertSufficientCash(CashierSession $session, float $amount): void
    {
        $availableBalance = $this->totals($session)['closing_balance'];

        if (round($amount, 2) > round($availableBalance, 2)) {
            throw ValidationException::withMessages([
                'amount' => [
                    'Saldo kas tidak mencukupi. Saldo tersedia: Rp '.number_format($availableBalance, 0, ',', '.').'.',
                ],
            ]);
        }
    }

    /**
     * Cari transaksi kas yang sudah tercatat untuk transaksi sumber tertentu.
     */
    private function findBySource(Model $source): ?CashTransaction
    {
        return CashTransaction::query()
            ->where('source_type', $source::class)
            ->where('source_id', $source->getKey())
            ->first();
    }
}
