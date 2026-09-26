<?php

namespace App\Services\Reconciliation;

use App\Models\SavingsAccount;

/**
 * Pemeriksaan konsistensi simpanan: bandingkan saldo tersimpan pada
 * savings_accounts.balance dengan hasil perhitungan ulang dari transaksi
 * yang sah (status completed). Read-only: tidak pernah mengubah saldo.
 */
class SavingsReconciliationChecker
{
    /**
     * Status transaksi simpanan yang secara resmi memengaruhi saldo.
     */
    private const BALANCE_AFFECTING_STATUS = 'completed';

    /**
     * Jalankan pemeriksaan dan kembalikan temuan mentah.
     *
     * @param  string  $periodStart  Y-m-d (inklusif) - transaksi sebelum tanggal ini tetap dihitung untuk saldo berjalan
     * @param  string  $periodEnd  Y-m-d (inklusif)
     * @return array{findings: array<int, array<string, mixed>>, checked_rows: int, summary: array<string, mixed>}
     */
    public function check(string $periodStart, string $periodEnd): array
    {
        $findings = [];
        $checkedRows = 0;
        $accountsChecked = 0;

        SavingsAccount::query()
            ->withCount(['transactions as affecting_count' => fn ($q) => $q
                ->where('status', self::BALANCE_AFFECTING_STATUS)
                ->whereDate('transaction_date', '<=', $periodEnd),
            ])
            ->orderBy('id')
            ->chunkById(200, function ($accounts) use (&$findings, &$checkedRows, &$accountsChecked, $periodStart, $periodEnd) {
                foreach ($accounts as $account) {
                    $accountsChecked++;

                    $posted = $account->transactions()
                        ->select('type', 'amount', 'status', 'transaction_number', 'transaction_date')
                        ->where('status', self::BALANCE_AFFECTING_STATUS)
                        ->whereDate('transaction_date', '<=', $periodEnd)
                        ->get();

                    $checkedRows += (int) $posted->count();

                    $totalDeposit = round((float) $posted->where('type', 'deposit')->sum('amount'), 2);
                    $totalWithdrawal = round((float) $posted->where('type', 'withdrawal')->sum('amount'), 2);

                    // Struktur saat ini tidak menyimpan saldo awal rekening: seluruh
                    // saldo berasal dari akumulasi transaksi (rekening dibuka dengan
                    // balance 0 dan hanya berubah melalui transaksi).
                    $recalculated = round($totalDeposit - $totalWithdrawal, 2);
                    $stored = round((float) $account->balance, 2);

                    if (round($stored - $recalculated, 2) !== 0.0) {
                        $findings[] = $this->finding(
                            account: $account,
                            type: 'BALANCE_MISMATCH',
                            expected: $recalculated,
                            actual: $stored,
                            variance: round($stored - $recalculated, 2),
                            description: sprintf(
                                'Saldo tersimpan Rp %s tidak sesuai hasil perhitungan transaksi sah Rp %s (setoran Rp %s - penarikan Rp %s).',
                                number_format($stored, 2, ',', '.'),
                                number_format($recalculated, 2, ',', '.'),
                                number_format($totalDeposit, 2, ',', '.'),
                                number_format($totalWithdrawal, 2, ',', '.')
                            ),
                            meta: [
                                'total_deposit' => $totalDeposit,
                                'total_withdrawal' => $totalWithdrawal,
                                'transaction_count' => $posted->count(),
                            ],
                        );
                    }

                    // Referensi berpotensi duplikat: nomor transaksi sama pada beberapa baris.
                    // (safety net; duplikat nomor global seharusnya dicegah unique constraint DB)
                    $duplicates = $account->transactions()
                        ->selectRaw('transaction_number, COUNT(*) AS total')
                        ->groupBy('transaction_number')
                        ->havingRaw('COUNT(*) > 1')
                        ->pluck('total', 'transaction_number');

                    foreach ($duplicates as $number => $count) {
                        $findings[] = $this->finding(
                            account: $account,
                            type: 'DUPLICATE_REFERENCE',
                            expected: 1.0,
                            actual: (float) $count,
                            variance: (float) ($count - 1),
                            description: sprintf('Nomor transaksi %s muncul %d kali pada rekening %s.', $number, $count, $account->account_number),
                            meta: ['transaction_number' => $number, 'occurrences' => (int) $count],
                        );
                    }

                    // Potensi double-posting: dua atau lebih transaksi sah dengan jenis,
                    // nominal, dan tanggal yang sama pada satu rekening dalam periode.
                    $potentialDuplicates = $account->transactions()
                        ->selectRaw('type, amount, transaction_date, COUNT(*) AS total')
                        ->where('status', self::BALANCE_AFFECTING_STATUS)
                        ->whereDate('transaction_date', '>=', $periodStart)
                        ->whereDate('transaction_date', '<=', $periodEnd)
                        ->groupBy('type', 'amount', 'transaction_date')
                        ->havingRaw('COUNT(*) > 1')
                        ->get();

                    foreach ($potentialDuplicates as $duplicate) {
                        $findings[] = $this->finding(
                            account: $account,
                            type: 'DUPLICATE_REFERENCE',
                            expected: 1.0,
                            actual: (float) $duplicate->total,
                            variance: (float) ($duplicate->total - 1),
                            description: sprintf(
                                '%d transaksi %s sebesar Rp %s pada %s tercatat berulang pada rekening %s (potensi double-posting).',
                                $duplicate->total,
                                $duplicate->type,
                                number_format((float) $duplicate->amount, 2, ',', '.'),
                                $duplicate->transaction_date,
                                $account->account_number
                            ),
                            meta: [
                                'type' => $duplicate->type,
                                'amount' => round((float) $duplicate->amount, 2),
                                'transaction_date' => $duplicate->transaction_date,
                                'occurrences' => (int) $duplicate->total,
                            ],
                        );
                    }

                    // Transaksi di luar status sah tidak dihitung ke saldo, tetap
                    // dilaporkan agar dapat dievaluasi pengelola.
                    $nonAffecting = $account->transactions()
                        ->where('status', '!=', self::BALANCE_AFFECTING_STATUS)
                        ->count();

                    if ($nonAffecting > 0) {
                        $findings[] = $this->finding(
                            account: $account,
                            type: 'INVALID_STATUS',
                            expected: null,
                            actual: null,
                            variance: null,
                            description: sprintf('%d transaksi berstatus tidak sah pada rekening %s tidak dihitung ke saldo.', $nonAffecting, $account->account_number),
                            meta: ['non_affecting_count' => $nonAffecting],
                        );
                    }
                }
            });

        return [
            'findings' => $findings,
            'checked_rows' => $checkedRows,
            'summary' => [
                'accounts_checked' => $accountsChecked,
                'transactions_reviewed' => $checkedRows,
                'mismatch_count' => count(array_filter($findings, fn ($f) => $f['finding_type'] === 'BALANCE_MISMATCH')),
                'duplicate_count' => count(array_filter($findings, fn ($f) => $f['finding_type'] === 'DUPLICATE_REFERENCE')),
                'invalid_status_count' => count(array_filter($findings, fn ($f) => $f['finding_type'] === 'INVALID_STATUS')),
            ],
        ];
    }

    /**
     * Struktur temuan mentah sebelum dipersistenkan.
     *
     * @param  array<string, mixed>|null  $meta
     * @return array<string, mixed>
     */
    private function finding(SavingsAccount $account, string $type, ?float $expected, ?float $actual, ?float $variance, string $description, ?array $meta = null): array
    {
        $magnitude = $variance !== null ? abs($variance) : ($actual ?? 0);

        return [
            'finding_type' => $type,
            'module' => 'savings',
            'object_type' => SavingsAccount::class,
            'object_id' => $account->id,
            'object_label' => $account->account_number,
            'expected_value' => $expected,
            'actual_value' => $actual,
            'variance' => $variance,
            'priority' => $magnitude >= 1000000 ? 'HIGH' : ($magnitude >= 10000 ? 'MEDIUM' : 'LOW'),
            'description' => $description,
            'meta' => $meta,
        ];
    }
}
