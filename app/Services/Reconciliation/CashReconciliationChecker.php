<?php

namespace App\Services\Reconciliation;

use App\Models\CashierSession;
use App\Models\CashTransaction;

/**
 * Pemeriksaan konsistensi kas: bandingkan saldo akhir sistem dengan hasil
 * perhitungan transaksi, verifikasi setoran kasir, deteksi transaksi ganda,
 * dan bedakan perpindahan kas internal dari penerimaan/pengeluaran eksternal.
 *
 * Read-only: tidak pernah mengubah saldo kas maupun transaksi.
 */
class CashReconciliationChecker
{
    /**
     * @param  string  $periodStart  Y-m-d (inklusif) - filter tanggal operasional sesi
     * @param  string  $periodEnd  Y-m-d (inklusif)
     * @return array{findings: array<int, array<string, mixed>>, checked_rows: int, summary: array<string, mixed>}
     */
    public function check(string $periodStart, string $periodEnd): array
    {
        $findings = [];
        $checkedRows = 0;
        $sessionsChecked = 0;
        $transactionsChecked = 0;

        CashierSession::query()
            ->with(['transactions'])
            // Kolom operational_date dapat tersimpan sebagai date/datetime tergantung
            // driver; whereDate menjaga perbandingan tetap inklusif pada tanggal murni.
            ->whereDate('operational_date', '>=', $periodStart)
            ->whereDate('operational_date', '<=', $periodEnd)
            ->orderBy('id')
            ->chunkById(200, function ($sessions) use (&$findings, &$checkedRows, &$sessionsChecked, &$transactionsChecked) {
                foreach ($sessions as $session) {
                    $sessionsChecked++;

                    $transactions = $session->transactions;
                    $posted = $transactions->filter(fn ($t) => $t->status === CashTransaction::STATUS_POSTED);
                    $reversed = $transactions->filter(fn ($t) => $t->status === CashTransaction::STATUS_REVERSED);
                    $checkedRows += $transactions->count();
                    $transactionsChecked += $posted->count();

                    $income = round((float) $posted->where('type', CashTransaction::TYPE_INCOME)->sum('amount'), 2);
                    $expense = round((float) $posted->where('type', CashTransaction::TYPE_EXPENSE)->sum('amount'), 2);
                    $deposit = round((float) $posted->where('type', CashTransaction::TYPE_DEPOSIT)->sum('amount'), 2);

                    $openingBalance = round((float) $session->opening_balance, 2);
                    $recalculatedClosing = round($openingBalance + $income - $expense - $deposit, 2);
                    $storedClosing = $session->system_closing_balance !== null
                        ? round((float) $session->system_closing_balance, 2)
                        : null;

                    // Sesi terbuka: snapshot belum wajib ada, tidak diperiksa equalitas.
                    if ($storedClosing !== null && round($storedClosing - $recalculatedClosing, 2) !== 0.0) {
                        $findings[] = $this->finding(
                            session: $session,
                            type: 'BALANCE_MISMATCH',
                            expected: $recalculatedClosing,
                            actual: $storedClosing,
                            variance: round($storedClosing - $recalculatedClosing, 2),
                            description: sprintf(
                                'Sesi %s: saldo akhir sistem (Rp %s) tidak sesuai hasil perhitungan transaksi (Rp %s).',
                                $session->session_number,
                                number_format($storedClosing, 2, ',', '.'),
                                number_format($recalculatedClosing, 2, ',', '.')
                            ),
                            meta: [
                                'opening_balance' => $openingBalance,
                                'income' => $income,
                                'expense' => $expense,
                                'deposit' => $deposit,
                                'reversed_count' => $reversed->count(),
                            ],
                        );
                    }

                    // Sesi tertutup: snapshot wajib ada dan selisih fisik harus konsisten.
                    if ($session->status === CashierSession::STATUS_CLOSED) {
                        if ($storedClosing === null) {
                            $findings[] = $this->finding(
                                session: $session,
                                type: 'INCOMPLETE_DATA',
                                expected: null,
                                actual: null,
                                variance: null,
                                description: sprintf('Sesi %s sudah ditutup tetapi tidak memiliki snapshot saldo akhir sistem.', $session->session_number),
                                meta: null,
                            );
                        } else {
                            $physicalBalance = $session->physical_closing_balance !== null
                                ? round((float) $session->physical_closing_balance, 2)
                                : null;
                            $storedDifference = $session->difference !== null ? round((float) $session->difference, 2) : null;

                            if ($physicalBalance === null || $storedDifference === null) {
                                $findings[] = $this->finding(
                                    session: $session,
                                    type: 'INCOMPLETE_DATA',
                                    expected: null,
                                    actual: null,
                                    variance: null,
                                    description: sprintf('Sesi %s ditutup tanpa saldo fisik atau selisih kas yang lengkap.', $session->session_number),
                                    meta: null,
                                );
                            } elseif (round(($physicalBalance - $recalculatedClosing) - $storedDifference, 2) !== 0.0) {
                                $findings[] = $this->finding(
                                    session: $session,
                                    type: 'CASH_VARIANCE',
                                    expected: round($physicalBalance - $recalculatedClosing, 2),
                                    actual: $storedDifference,
                                    variance: round($storedDifference - ($physicalBalance - $recalculatedClosing), 2),
                                    description: sprintf(
                                        'Sesi %s: selisih kas tercatat (Rp %s) tidak sesuai perhitungan saldo fisik terhadap saldo sistem (Rp %s).',
                                        $session->session_number,
                                        number_format($storedDifference, 2, ',', '.'),
                                        number_format(round($physicalBalance - $recalculatedClosing, 2), 2, ',', '.')
                                    ),
                                    meta: [
                                        'physical_balance' => $physicalBalance,
                                        'system_balance' => $recalculatedClosing,
                                        'recorded_difference' => $storedDifference,
                                    ],
                                );
                            } elseif (round($storedDifference, 2) !== 0.0) {
                                // Selisih kas nyata pada penutupan: dilaporkan untuk tindak lanjut.
                                $findings[] = $this->finding(
                                    session: $session,
                                    type: 'CASH_VARIANCE',
                                    expected: $recalculatedClosing,
                                    actual: $physicalBalance,
                                    variance: $storedDifference,
                                    description: sprintf(
                                        'Sesi %s: terdapat selisih kas Rp %s pada penutupan (saldo sistem Rp %s, fisik Rp %s).',
                                        $session->session_number,
                                        number_format($storedDifference, 2, ',', '.'),
                                        number_format($recalculatedClosing, 2, ',', '.'),
                                        number_format($physicalBalance, 2, ',', '.')
                                    ),
                                    meta: [
                                        'physical_balance' => $physicalBalance,
                                        'system_balance' => $recalculatedClosing,
                                        'closing_notes' => $session->closing_notes,
                                    ],
                                );
                            }
                        }
                    }

                    // Total snapshot pada sesi tertutup harus sesuai transaksi.
                    if ($session->status === CashierSession::STATUS_CLOSED) {
                        foreach ([
                            'total_income' => $income,
                            'total_expense' => $expense,
                            'total_deposit' => $deposit,
                        ] as $field => $recalculated) {
                            $storedTotal = $session->{$field} !== null ? round((float) $session->{$field}, 2) : null;

                            if ($storedTotal !== null && round($storedTotal - $recalculated, 2) !== 0.0) {
                                $findings[] = $this->finding(
                                    session: $session,
                                    type: 'BALANCE_MISMATCH',
                                    expected: $recalculated,
                                    actual: $storedTotal,
                                    variance: round($storedTotal - $recalculated, 2),
                                    description: sprintf('Sesi %s: snapshot %s (Rp %s) tidak sesuai perhitungan transaksi (Rp %s).', $session->session_number, $field, number_format($storedTotal, 2, ',', '.'), number_format($recalculated, 2, ',', '.')),
                                    meta: ['field' => $field],
                                );
                            }
                        }
                    }

                    // Transaksi kas ganda berdasarkan referensi sumber (satu transaksi
                    // bisnis modul lain hanya boleh menghasilkan satu dampak kas).
                    $duplicates = $posted
                        ->filter(fn ($t) => $t->source_type !== null)
                        ->groupBy(fn ($t) => $t->source_type.'#'.$t->source_id)
                        ->filter(fn ($group) => $group->count() > 1);

                    foreach ($duplicates as $group) {
                        $first = $group->first();
                        $findings[] = $this->finding(
                            session: $session,
                            type: 'DUPLICATE_REFERENCE',
                            expected: 1.0,
                            actual: (float) $group->count(),
                            variance: (float) ($group->count() - 1),
                            description: sprintf('Sesi %s: transaksi sumber %s#%s tercatat %d kali.', $session->session_number, $first->source_type, $first->source_id, $group->count()),
                            meta: [
                                'source_type' => $first->source_type,
                                'source_id' => $first->source_id,
                                'occurrences' => $group->count(),
                            ],
                        );
                    }

                    // Setoran kasir tidak boleh melebihi uang yang diterima sesi
                    // (perpindahan kas internal, bukan penerimaan eksternal).
                    if ($deposit > $income) {
                        $findings[] = $this->finding(
                            session: $session,
                            type: 'INVALID_STATUS',
                            expected: $income,
                            actual: $deposit,
                            variance: round($deposit - $income, 2),
                            description: sprintf('Sesi %s: total setoran kasir (Rp %s) melebihi total penerimaan kas (Rp %s).', $session->session_number, number_format($deposit, 2, ',', '.'), number_format($income, 2, ',', '.')),
                            meta: ['income' => $income, 'deposit' => $deposit],
                        );
                    }
                }
            });

        return [
            'findings' => $findings,
            'checked_rows' => $checkedRows,
            'summary' => [
                'sessions_checked' => $sessionsChecked,
                'transactions_reviewed' => $transactionsChecked,
                'balance_mismatch_count' => count(array_filter($findings, fn ($f) => $f['finding_type'] === 'BALANCE_MISMATCH')),
                'cash_variance_count' => count(array_filter($findings, fn ($f) => $f['finding_type'] === 'CASH_VARIANCE')),
                'duplicate_count' => count(array_filter($findings, fn ($f) => $f['finding_type'] === 'DUPLICATE_REFERENCE')),
                'note' => 'Saldo akhir per sesi tidak dijumlahkan sebagai total kas koperasi; setoran kasir adalah perpindahan internal.',
            ],
        ];
    }

    /**
     * Struktur temuan mentah sebelum dipersistenkan.
     *
     * @param  array<string, mixed>|null  $meta
     * @return array<string, mixed>
     */
    private function finding(CashierSession $session, string $type, ?float $expected, ?float $actual, ?float $variance, string $description, ?array $meta = null): array
    {
        $magnitude = $variance !== null ? abs($variance) : 0;

        return [
            'finding_type' => $type,
            'module' => 'cash',
            'object_type' => CashierSession::class,
            'object_id' => $session->id,
            'object_label' => $session->session_number,
            'expected_value' => $expected,
            'actual_value' => $actual,
            'variance' => $variance,
            'priority' => $magnitude >= 1000000 ? 'HIGH' : ($magnitude >= 10000 ? 'MEDIUM' : 'LOW'),
            'description' => $description,
            'meta' => $meta,
        ];
    }
}
