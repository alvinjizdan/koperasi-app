<?php

namespace App\Services\Reports;

use App\Models\CashTransaction;
use App\Support\QuerySorter;
use Generator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Laporan transaksi kas.
 *
 * Hanya transaksi berstatus POSTED yang dihitung pada total. Setoran kasir
 * (perpindahan kas antar-lokasi internal) dilaporkan terpisah dan tidak pernah
 * dicampur dengan penerimaan atau pengeluaran eksternal. Ledger kas hanya
 * memuat pergerakan uang tunai, sehingga tidak ada transaksi non-tunai di sini.
 */
class CashTransactionReport
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
            ->with(['operator:id,name', 'session:id,session_number']);

        $this->sorter->apply($query, $sort, $direction, [
            'transaction_number' => 'transaction_number',
            'transaction_date' => 'transaction_date',
            'type' => 'type',
            'category' => 'category',
            'amount' => 'amount',
            'operator_name' => 'operator_name',
        ], [['transaction_date', 'desc'], ['id', 'desc']]);

        return $query
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (CashTransaction $transaction) => $this->toRow($transaction));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function summary(array $filters): array
    {
        $base = $this->query($filters);

        $posted = (clone $base)
            ->where('status', CashTransaction::STATUS_POSTED)
            ->select('type', DB::raw('COUNT(*) AS total_count'), DB::raw('COALESCE(SUM(amount), 0) AS total_amount'))
            ->groupBy('type')
            ->get()
            ->keyBy('type');

        $income = (float) ($posted[CashTransaction::TYPE_INCOME]->total_amount ?? 0);
        $expense = (float) ($posted[CashTransaction::TYPE_EXPENSE]->total_amount ?? 0);
        $deposit = (float) ($posted[CashTransaction::TYPE_DEPOSIT]->total_amount ?? 0);
        $reversed = (clone $base)->where('status', CashTransaction::STATUS_REVERSED);

        return [
            'transaction_count' => (clone $base)->count(),
            'posted_count' => (int) $posted->sum('total_count'),
            'income_total' => round($income, 2),
            'expense_total' => round($expense, 2),
            'deposit_total' => round($deposit, 2),
            // Perubahan kas fisik termasuk perpindahan kas internal (setoran kasir).
            'drawer_movement' => round($income - $expense - $deposit, 2),
            'reversed_count' => (clone $reversed)->count(),
            'reversed_total' => round((float) (clone $reversed)->sum('amount'), 2),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Generator<int, array<int, string|float>>
     */
    public function stream(array $filters): Generator
    {
        $query = $this->query($filters)->with(['operator:id,name', 'session:id,session_number']);

        foreach ($query->orderBy('transaction_date')->orderBy('id')->lazy() as $transaction) {
            $row = $this->toRow($transaction);

            yield [
                $row['transaction_number'],
                $row['transaction_date'] ?? '-',
                $row['transacted_at'] ?? '-',
                $row['type_label'],
                $row['category'] ?? '-',
                $row['amount'],
                $row['operator_name'] ?? '-',
                $row['session_number'] ?? '-',
                $row['source_label'] ?? '-',
                $row['status_label'],
                $row['description'] ?? '',
            ];
        }
    }

    /**
     * @return array<int, string>
     */
    public function csvHeaders(): array
    {
        return [
            'Nomor Transaksi',
            'Tanggal',
            'Waktu Pencatatan',
            'Jenis',
            'Kategori',
            'Nominal',
            'Operator',
            'Nomor Sesi',
            'Referensi Sumber',
            'Status',
            'Keterangan',
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function query(array $filters): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));

        return CashTransaction::query()
            ->whereBetween('transaction_date', $this->period->dateRange())
            ->when(
                ($filters['type'] ?? 'all') !== 'all',
                fn (Builder $q) => $q->where('type', $filters['type'])
            )
            ->when(
                ($filters['category'] ?? 'all') !== 'all',
                fn (Builder $q) => $q->where('category', $filters['category'])
            )
            ->when(
                ($filters['status'] ?? 'all') !== 'all',
                fn (Builder $q) => $q->where('status', $filters['status'])
            )
            ->when(
                ! empty($filters['operator_id']),
                fn (Builder $q) => $q->where('processed_by', $filters['operator_id'])
            )
            ->when($search !== '', fn (Builder $q) => $q->whereRaw('LOWER(transaction_number) LIKE ?', ['%'.mb_strtolower($search).'%']));
    }

    /**
     * @return array<string, mixed>
     */
    private function toRow(CashTransaction $transaction): array
    {
        return [
            'id' => $transaction->id,
            'transaction_number' => $transaction->transaction_number,
            'transaction_date' => $transaction->transaction_date?->toDateString(),
            'transacted_at' => $transaction->transacted_at?->toDateTimeString(),
            'type' => $transaction->type,
            'type_label' => $transaction->type_label,
            'category' => $transaction->category,
            'amount' => round((float) $transaction->amount, 2),
            'increases_cash' => $transaction->increases_cash,
            'operator_name' => $transaction->operator?->name,
            'session_number' => $transaction->session?->session_number,
            'source_label' => $transaction->source_label,
            'status' => $transaction->status,
            'status_label' => $transaction->status === CashTransaction::STATUS_POSTED ? 'Berlaku' : 'Dibatalkan',
            'description' => $transaction->description,
            'document_reference' => $transaction->document_reference,
            'url' => route('kasir.transaksi.show', $transaction->id),
        ];
    }
}
