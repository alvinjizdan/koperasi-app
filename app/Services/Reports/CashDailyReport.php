<?php

namespace App\Services\Reports;

use App\Models\CashierSession;
use App\Models\CashTransaction;
use App\Support\QuerySorter;
use Generator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Laporan kas harian.
 *
 * Setiap sesi kasir ditampilkan terpisah. Saldo akhir sistem dihitung dengan
 * rumus resmi modul Kasir (saldo awal + penerimaan - pengeluaran - setoran).
 * Saldo akhir antar-sesi tidak pernah dijumlahkan sebagai total saldo kas
 * koperasi karena uang yang sama tidak boleh dihitung dua kali.
 */
class CashDailyReport
{
    private QuerySorter $sorter;

    /**
     * Status sorting yang diterapkan pada pemanggilan paginate() terakhir.
     *
     * @return array{key: string|null, direction: string|null}
     */
    public function sortStatus(): array
    {
        return $this->sorter->status();
    }

    public function __construct(private readonly ReportPeriod $period)
    {
        $this->sorter = new QuerySorter;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(array $filters, int $perPage, ?string $sort = null, ?string $direction = null): LengthAwarePaginator
    {
        $query = $this->query($filters)
            ->withTotals()
            ->with(['operator:id,name', 'closer:id,name']);

        $this->sorter->apply($query, $sort, $direction, [
            'session_number' => 'session_number',
            'operational_date' => 'operational_date',
            'opening_balance' => 'opening_balance',
            'income_total' => 'income_total',
            'expense_total' => 'expense_total',
            'deposit_total' => 'deposit_total',
            'status' => 'status',
        ], [['operational_date', 'desc'], ['id', 'desc']]);

        return $query
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (CashierSession $session) => $this->toRow($session));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function summary(array $filters): array
    {
        $base = $this->query($filters);
        $sessionIds = $base->select('id');

        $posted = CashTransaction::query()
            ->whereIn('cashier_session_id', $sessionIds)
            ->where('status', CashTransaction::STATUS_POSTED)
            ->selectRaw('type, COALESCE(SUM(amount), 0) AS total')
            ->groupBy('type')
            ->pluck('total', 'type');

        $income = round((float) ($posted[CashTransaction::TYPE_INCOME] ?? 0), 2);
        $expense = round((float) ($posted[CashTransaction::TYPE_EXPENSE] ?? 0), 2);
        $deposit = round((float) ($posted[CashTransaction::TYPE_DEPOSIT] ?? 0), 2);

        // Kas yang masih berada pada sesi terbuka, dihitung per sesi memakai
        // rumus resmi modul Kasir.
        $openSessions = CashierSession::query()
            ->whereIn('id', (clone $base)->where('status', CashierSession::STATUS_OPEN)->select('id'))
            ->get(['id', 'opening_balance']);

        $openPosted = CashTransaction::query()
            ->whereIn('cashier_session_id', $openSessions->pluck('id'))
            ->where('status', CashTransaction::STATUS_POSTED)
            ->selectRaw('type, COALESCE(SUM(amount), 0) AS total')
            ->groupBy('type')
            ->pluck('total', 'type');

        $cashOnHand = round(
            (float) $openSessions->sum('opening_balance')
            + (float) ($openPosted[CashTransaction::TYPE_INCOME] ?? 0)
            - (float) ($openPosted[CashTransaction::TYPE_EXPENSE] ?? 0)
            - (float) ($openPosted[CashTransaction::TYPE_DEPOSIT] ?? 0),
            2
        );

        $closedSessions = (clone $base)->where('status', CashierSession::STATUS_CLOSED);

        return [
            'sessions_count' => (clone $base)->count(),
            'open_sessions_count' => (clone $base)->where('status', CashierSession::STATUS_OPEN)->count(),
            'closed_sessions_count' => (clone $closedSessions)->count(),
            'opening_balance' => round((float) (clone $base)->sum('opening_balance'), 2),
            'income_total' => $income,
            'expense_total' => $expense,
            'deposit_total' => $deposit,
            'difference_total' => round((float) (clone $closedSessions)->sum('difference'), 2),
            'has_closed_session' => (clone $closedSessions)->exists(),
            'cash_on_hand_open_sessions' => $cashOnHand,
            'open_sessions' => $openSessions->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Generator<int, array<int, string|float|int>>
     */
    public function stream(array $filters): Generator
    {
        $query = $this->query($filters)->withTotals()->with(['operator:id,name']);

        foreach ($query->orderBy('operational_date')->orderBy('id')->lazy() as $session) {
            $row = $this->toRow($session);

            yield [
                $row['session_number'],
                $row['operational_date'] ?? '-',
                $row['operator_name'] ?? '-',
                $row['opening_balance'],
                $row['income_total'],
                $row['expense_total'],
                $row['deposit_total'],
                $row['system_balance'],
                $row['physical_closing_balance'] ?? '-',
                $row['difference'] ?? '-',
                $row['status_label'],
            ];
        }
    }

    /**
     * @return array<int, string>
     */
    public function csvHeaders(): array
    {
        return [
            'Nomor Sesi',
            'Tanggal Operasional',
            'Operator',
            'Saldo Awal',
            'Total Penerimaan',
            'Total Pengeluaran',
            'Total Setoran',
            'Saldo Akhir Sistem',
            'Saldo Fisik',
            'Selisih Kas',
            'Status Sesi',
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<CashierSession>
     */
    private function query(array $filters): Builder
    {
        return CashierSession::query()
            ->whereBetween('operational_date', $this->period->dateRange())
            ->when(
                ! empty($filters['operator_id']),
                fn (Builder $q) => $q->where('opened_by', $filters['operator_id'])
            )
            ->when(
                ($filters['status'] ?? 'all') !== 'all',
                fn (Builder $q) => $q->where('status', $filters['status'])
            );
    }

    /**
     * @return array<string, mixed>
     */
    private function toRow(CashierSession $session): array
    {
        return [
            ...$session->toSummaryArray(),
            'operator_name' => $session->operator?->name,
            'closer_name' => $session->closer?->name,
            'status_label' => $session->isOpen() ? 'Terbuka' : 'Ditutup',
            'url' => route('kasir.sesi.show', $session->id),
        ];
    }
}
