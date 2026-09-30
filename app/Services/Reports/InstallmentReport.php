<?php

namespace App\Services\Reports;

use App\Models\InstallmentPayment;
use App\Support\QuerySorter;
use Generator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Laporan pembayaran angsuran.
 *
 * Hanya transaksi berstatus COMPLETED yang masuk ke total pembayaran berhasil.
 * Pembayaran yang dibatalkan (REVERSED) dihitung terpisah dan tidak pernah
 * dijumlahkan dengan total berhasil. Alokasi pokok dan bunga tidak ditampilkan
 * karena aturan alokasinya belum dikonfirmasi.
 */
class InstallmentReport
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
        $query = $this->query($filters);

        $this->sorter->apply($query, $sort, $direction, [
            'payment_number' => 'payment_number',
            'payment_date' => 'payment_date',
            'amount' => 'amount',
            'status' => 'status',
        ], [['payment_date', 'desc'], ['id', 'desc']]);

        return $query
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (InstallmentPayment $payment) => $this->toRow($payment));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function summary(array $filters): array
    {
        $base = $this->query($filters);

        $completed = (clone $base)->where('status', 'COMPLETED');
        $reversed = (clone $base)->where('status', 'REVERSED');

        return [
            'payments_count' => (clone $base)->count(),
            'completed_count' => (clone $completed)->count(),
            'completed_total' => round((float) (clone $completed)->sum('amount'), 2),
            'reversed_count' => (clone $reversed)->count(),
            'reversed_total' => round((float) (clone $reversed)->sum('amount'), 2),
            'loans_count' => (clone $base)->distinct()->count('loan_id'),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Generator<int, array<int, string|float>>
     */
    public function stream(array $filters): Generator
    {
        foreach ($this->query($filters)->orderBy('payment_date')->orderBy('id')->lazy() as $payment) {
            $row = $this->toRow($payment);

            yield [
                $row['payment_number'],
                $row['loan_number'],
                $row['member_number'],
                $row['member_name'],
                $row['payment_date'] ?? '-',
                $row['amount'],
                $row['operator_name'] ?? '-',
                $row['status_label'],
                $row['notes'] ?? '',
            ];
        }
    }

    /**
     * @return array<int, string>
     */
    public function csvHeaders(): array
    {
        return [
            'Nomor Pembayaran',
            'Nomor Pinjaman',
            'Nomor Anggota',
            'Nama Anggota',
            'Tanggal Pembayaran',
            'Nominal Pembayaran',
            'Operator',
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

        return InstallmentPayment::query()
            ->with([
                'operator:id,name',
                'loan:id,loan_number,member_id',
                'loan.member:id,member_number,full_name',
            ])
            ->whereBetween('payment_date', $this->period->dateRange())
            ->when(
                ! empty($filters['operator_id']),
                fn (Builder $q) => $q->where('processed_by', $filters['operator_id'])
            )
            ->when(
                ($filters['status'] ?? 'all') !== 'all',
                fn (Builder $q) => $q->where('status', $filters['status'])
            )
            ->when($search !== '', function (Builder $q) use ($search) {
                $term = '%'.mb_strtolower($search).'%';

                $q->where(function (Builder $inner) use ($term) {
                    $inner->whereRaw('LOWER(payment_number) LIKE ?', [$term])
                        ->orWhereHas('loan', function (Builder $loan) use ($term) {
                            $loan->whereRaw('LOWER(loan_number) LIKE ?', [$term])
                                ->orWhereHas('member', function (Builder $member) use ($term) {
                                    $member->whereRaw('LOWER(full_name) LIKE ?', [$term])
                                        ->orWhereRaw('LOWER(member_number) LIKE ?', [$term]);
                                });
                        });
                });
            });
    }

    /**
     * @return array<string, mixed>
     */
    private function toRow(InstallmentPayment $payment): array
    {
        return [
            'id' => $payment->id,
            'payment_number' => $payment->payment_number,
            'loan_number' => $payment->loan?->loan_number,
            'member_number' => $payment->loan?->member?->member_number,
            'member_name' => $payment->loan?->member?->full_name,
            'payment_date' => $payment->payment_date?->toDateString(),
            'amount' => round((float) $payment->amount, 2),
            'operator_name' => $payment->operator?->name,
            'status' => $payment->status,
            'status_label' => $payment->status === 'COMPLETED' ? 'Berhasil' : 'Dibatalkan',
            'notes' => $payment->notes,
            'url' => route('angsuran.payment.show', $payment->id),
        ];
    }
}
