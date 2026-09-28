<?php

namespace App\Services\Accounting;

use App\Models\CashTransaction;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use Illuminate\Support\Facades\DB;

/**
 * Layanan laporan keuangan (Ticket 19): neraca saldo, neraca, laba rugi,
 * dan audit kesiapan arus kas.
 *
 * Prinsip:
 * - SATU sumber perhitungan: baris jurnal POSTED (konsisten dengan
 *   JournalService::ledger — tanpa tabel saldo terpisah, tanpa duplikasi).
 * - Saldo berjalan per akun = saldo_normal × (debit − kredit).
 * - Read-only: laporan tidak pernah mengubah jurnal maupun transaksi sumber.
 * - Setiap hasil membawa metadata status/keterbatasan agar laporan tidak
 *   disalahartikan sebagai laporan keuangan resmi koperasi.
 */
class FinancialReportService
{
    /**
     * Agregasi inti: total debit & kredit per akun atas jurnal POSTED
     * dalam rentang tanggal (inklusif). Satu query, tanpa N+1.
     *
     * @return array<int, array<string, mixed>> kode, nama, kategori, saldo normal, debit, kredit
     */
    private function accountTotals(?string $startDate, ?string $endDate): array
    {
        return DB::table('journal_lines')
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->join('chart_of_accounts', 'journal_lines.account_id', '=', 'chart_of_accounts.id')
            ->where('journal_entries.status', JournalEntry::STATUS_POSTED)
            ->when($startDate !== null, fn ($q) => $q->where('journal_entries.transaction_date', '>=', $startDate))
            ->when($endDate !== null, fn ($q) => $q->where('journal_entries.transaction_date', '<=', $endDate))
            ->groupBy('chart_of_accounts.id', 'chart_of_accounts.code', 'chart_of_accounts.name', 'chart_of_accounts.category', 'chart_of_accounts.normal_balance')
            ->orderBy('chart_of_accounts.code')
            ->get([
                'chart_of_accounts.id',
                'chart_of_accounts.code',
                'chart_of_accounts.name',
                'chart_of_accounts.category',
                'chart_of_accounts.normal_balance',
                DB::raw('COALESCE(SUM(journal_lines.debit), 0) AS total_debit'),
                DB::raw('COALESCE(SUM(journal_lines.credit), 0) AS total_credit'),
            ])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'code' => $row->code,
                'name' => $row->name,
                'category' => $row->category,
                'normal_balance' => $row->normal_balance,
                'total_debit' => round((float) $row->total_debit, 2),
                'total_credit' => round((float) $row->total_credit, 2),
            ])
            ->all();
    }

    /**
     * NERACA SALDO: seluruh akun (termasuk tanpa mutasi bila diminta),
     * dengan total mutasi debit/kredit dan saldo per sisi.
     *
     * Saldo debit = mutasi debit − mutasi kredit bila positif menurut saldo
     * normal; saldo kredit = kebalikannya. Aturan perhitungan eksplisit agar
     * tidak tertukar dengan saldo normal akun.
     *
     * @return array{rows: array<int, array<string, mixed>>, totals: array{debit: float, credit: float, balance_debit: float, balance_credit: float}, balanced: bool, meta: array<string, mixed>}
     */
    public function trialBalance(?string $startDate, ?string $endDate, bool $includeEmpty = true): array
    {
        $accounts = $this->accountTotals($startDate, $endDate);

        // Akun tanpa mutasi tetap bisa ditampilkan dengan angka nol.
        if ($includeEmpty) {
            $usedIds = array_column($accounts, 'id');
            $allAccounts = ChartOfAccount::query()
                ->whereNotIn('id', $usedIds === [] ? [0] : $usedIds)
                ->orderBy('code')
                ->get(['id', 'code', 'name', 'category', 'normal_balance']);

            foreach ($allAccounts as $account) {
                $accounts[] = [
                    'id' => $account->id,
                    'code' => $account->code,
                    'name' => $account->name,
                    'category' => $account->category,
                    'normal_balance' => $account->normal_balance,
                    'total_debit' => 0.0,
                    'total_credit' => 0.0,
                ];
            }

            usort($accounts, fn ($a, $b) => strcmp($a['code'], $b['code']));
        }

        $rows = [];
        $totalDebit = 0.0;
        $totalCredit = 0.0;
        $totalBalanceDebit = 0.0;
        $totalBalanceCredit = 0.0;

        foreach ($accounts as $account) {
            $debit = $account['total_debit'];
            $credit = $account['total_credit'];
            $net = round($debit - $credit, 2);

            // Saldo jatuh pada sisi yang lebih besar (net = debit − kredit);
            // saldo normal akun bersifat informatif: saldo pada sisi normal
            // berarti wajar, saldo pada sisi berlawanan berarti anomali yang
            // tetap ditampilkan apa adanya (tidak disembunyikan).
            $balanceDebit = max(0.0, $net);
            $balanceCredit = max(0.0, -$net);

            $totalDebit = round($totalDebit + $debit, 2);
            $totalCredit = round($totalCredit + $credit, 2);
            $totalBalanceDebit = round($totalBalanceDebit + $balanceDebit, 2);
            $totalBalanceCredit = round($totalBalanceCredit + $balanceCredit, 2);

            $rows[] = [
                'id' => $account['id'],
                'code' => $account['code'],
                'name' => $account['name'],
                'category' => $account['category'],
                'category_label' => ChartOfAccount::CATEGORIES[$account['category']]['label'] ?? $account['category'],
                'normal_balance' => $account['normal_balance'],
                'total_debit' => $debit,
                'total_credit' => $credit,
                'balance_debit' => $balanceDebit,
                'balance_credit' => $balanceCredit,
            ];
        }

        return [
            'rows' => $rows,
            'totals' => [
                'debit' => $totalDebit,
                'credit' => $totalCredit,
                'balance_debit' => $totalBalanceDebit,
                'balance_credit' => $totalBalanceCredit,
            ],
            'balanced' => $totalDebit === $totalCredit,
            'meta' => $this->reportMeta($startDate, $endDate),
        ];
    }

    /**
     * NERACA: aset, liabilitas, ekuitas per akun (akun induk dengan baris
     * jurnal langsung tetap dihitung; akun induk tanpa baris tidak
     * menduplikasi jumlah anak karena agregasi dilakukan per akun aktual).
     *
     * Surplus/defisit periode TIDAK dimasukkan ke ekuitas (tidak ada
     * penutupan buku); ditampilkan terpisah sebagai keterangan.
     *
     * @return array{sections: array<string, array<string, mixed>>, summary: array<string, mixed>, balanced: bool, meta: array<string, mixed>}
     */
    public function balanceSheet(string $asOfDate): array
    {
        $accounts = $this->accountTotals(null, $asOfDate);

        $sections = [];
        $totals = [];

        foreach ([ChartOfAccount::CATEGORY_ASSET, ChartOfAccount::CATEGORY_LIABILITY, ChartOfAccount::CATEGORY_EQUITY] as $category) {
            $sectionRows = [];
            $sectionTotal = 0.0;

            foreach ($accounts as $account) {
                if ($account['category'] !== $category) {
                    continue;
                }

                $net = round($account['total_debit'] - $account['total_credit'], 2);
                $normal = $account['normal_balance'] === ChartOfAccount::NORMAL_DEBIT ? 1 : -1;
                $balance = round($net * $normal, 2); // nilai sesuai saldo normal

                $sectionTotal = round($sectionTotal + $balance, 2);
                $sectionRows[] = [
                    'id' => $account['id'],
                    'code' => $account['code'],
                    'name' => $account['name'],
                    'balance' => $balance,
                ];
            }

            $sections[$category] = [
                'label' => ChartOfAccount::CATEGORIES[$category]['label'],
                'accounts' => $sectionRows,
                'total' => $sectionTotal,
            ];
            $totals[$category] = $sectionTotal;
        }

        $totalAssets = $totals[ChartOfAccount::CATEGORY_ASSET];
        $totalLiabilities = $totals[ChartOfAccount::CATEGORY_LIABILITY];
        $totalEquity = $totals[ChartOfAccount::CATEGORY_EQUITY];
        $difference = round($totalAssets - ($totalLiabilities + $totalEquity), 2);

        // Surplus/defisit periode berjalan (pemanfaatan-laba belum diposting
        // ke ekuitas): keterangan saja, bukan komponen neraca.
        $result = $this->incomeStatement(null, $asOfDate);

        return [
            'sections' => $sections,
            'summary' => [
                'total_assets' => $totalAssets,
                'total_liabilities' => $totalLiabilities,
                'total_equity' => $totalEquity,
                'difference' => $difference,
                'current_period_result' => $result['summary']['surplus_deficit'],
                'balanced_with_result' => round($difference - $result['summary']['surplus_deficit'], 2) === 0.0,
            ],
            'balanced' => $difference === 0.0,
            'meta' => $this->reportMeta(null, $asOfDate),
        ];
    }

    /**
     * LABA RUGI: pendapatan dan beban dalam rentang tanggal (inklusif),
     * surplus/defisit sebagai hasil perhitungan periode.
     *
     * @return array{sections: array<string, array<string, mixed>>, summary: array<string, mixed>, meta: array<string, mixed>}
     */
    public function incomeStatement(?string $startDate, ?string $endDate): array
    {
        $accounts = $this->accountTotals($startDate, $endDate);

        $sections = [];
        $totals = [];

        foreach ([ChartOfAccount::CATEGORY_REVENUE, ChartOfAccount::CATEGORY_EXPENSE] as $category) {
            $sectionRows = [];
            $sectionTotal = 0.0;

            foreach ($accounts as $account) {
                if ($account['category'] !== $category) {
                    continue;
                }

                $net = round($account['total_debit'] - $account['total_credit'], 2);
                $normal = $account['normal_balance'] === ChartOfAccount::NORMAL_DEBIT ? 1 : -1;
                $balance = round($net * $normal, 2);

                $sectionTotal = round($sectionTotal + $balance, 2);
                $sectionRows[] = [
                    'id' => $account['id'],
                    'code' => $account['code'],
                    'name' => $account['name'],
                    'balance' => $balance,
                ];
            }

            $sections[$category] = [
                'label' => ChartOfAccount::CATEGORIES[$category]['label'],
                'accounts' => $sectionRows,
                'total' => $sectionTotal,
            ];
            $totals[$category] = $sectionTotal;
        }

        $surplus = round($totals[ChartOfAccount::CATEGORY_REVENUE] - $totals[ChartOfAccount::CATEGORY_EXPENSE], 2);

        return [
            'sections' => $sections,
            'summary' => [
                'total_revenue' => $totals[ChartOfAccount::CATEGORY_REVENUE],
                'total_expense' => $totals[ChartOfAccount::CATEGORY_EXPENSE],
                'surplus_deficit' => $surplus,
            ],
            'meta' => $this->reportMeta($startDate, $endDate),
        ];
    }

    /**
     * AUDIT KESIAPAN ARUS KAS (Tahap 5).
     *
     * Klasifikasi operasi/investasi/pendanaan TIDAK tersedia (tidak ada
     * mapping akun resmi), dan transaksi kasir belum diposting ke jurnal
     * (auto-posting belum aktif) — sehingga laporan arus kas akuntansi
     * TIDAK dibangun. Yang disediakan: data kas terverifikasi dari
     * cash_transactions POSTED (sumber operasional, bukan jurnal) plus
     * prasyarat implementasi.
     *
     * @return array{available: bool, operational_summary: array<string, mixed>, prerequisites: array<int, string>, meta: array<string, mixed>}
     */
    public function cashFlowReadiness(?string $startDate, ?string $endDate): array
    {
        $query = CashTransaction::query()->where('status', CashTransaction::STATUS_POSTED);

        $period = clone $query;
        if ($startDate !== null) {
            $period->whereDate('transaction_date', '>=', $startDate);
        }
        if ($endDate !== null) {
            $period->whereDate('transaction_date', '<=', $endDate);
        }

        $byType = (clone $period)
            ->selectRaw('type, COALESCE(SUM(amount), 0) AS total')
            ->groupBy('type')
            ->pluck('total', 'type');

        $income = round((float) ($byType[CashTransaction::TYPE_INCOME] ?? 0), 2);
        $expense = round((float) ($byType[CashTransaction::TYPE_EXPENSE] ?? 0), 2);
        $deposit = round((float) ($byType[CashTransaction::TYPE_DEPOSIT] ?? 0), 2);

        $unpostedJournals = JournalEntry::query()
            ->where('status', JournalEntry::STATUS_POSTED)
            ->count() === 0;

        return [
            'available' => false,
            'operational_summary' => [
                'total_income' => $income,
                'total_expense' => $expense,
                'total_deposit' => $deposit,
                'net_cash' => round($income - $expense - $deposit, 2),
                'note' => 'Ringkasan kas operasional dari cash_transactions POSTED — bukan laporan arus kas akuntansi. Setoran kasir adalah perpindahan internal.',
            ],
            'prerequisites' => [
                'COA resmi koperasi dikonfirmasi (saat ini template).',
                'Mapping akun per jenis transaksi kas ditetapkan.',
                'Auto-posting transaksi kasir ke jurnal diaktifkan (saat ini belum ada jurnal sumber).',
                'Klasifikasi aktivitas operasi/investasi/pendanaan disepakati koperasi.',
                'Mekanisme saldo awal kas yang terdokumentasi.',
            ],
            'business_journals_posted' => ! $unpostedJournals,
            'meta' => $this->reportMeta($startDate, $endDate),
        ];
    }

    /**
     * Metadata status & keterbatasan laporan (dilampirkan ke setiap hasil).
     *
     * @return array<string, mixed>
     */
    private function reportMeta(?string $startDate, ?string $endDate): array
    {
        return [
            'period' => [
                'start' => $startDate,
                'end' => $endDate,
            ],
            'source' => 'Jurnal POSTED saja; DRAFT tidak dihitung; jurnal REVERSED tetap terhitung bersama jurnal pembaliknya.',
            'limitations' => [
                'COA yang dipakai masih TEMPLATE (bukan COA resmi koperasi).',
                'Auto-posting transaksi bisnis belum aktif — jurnal hanya berasal dari input manual.',
                'Laporan ini bukan laporan keuangan resmi koperasi.',
            ],
        ];
    }
}
