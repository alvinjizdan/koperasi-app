<?php

namespace App\Services\Reports;

use App\Models\InstallmentSchedule;
use App\Support\QuerySorter;
use Generator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Laporan tunggakan.
 *
 * Tunggakan hanya mencakup angsuran yang sudah melewati tanggal jatuh tempo dan
 * masih memiliki sisa tagihan. Angsuran yang belum jatuh tempo tidak dihitung
 * sebagai tunggakan. Denda keterlambatan tidak dihitung karena aturannya belum
 * dikonfirmasi; yang disediakan hanya jumlah hari keterlambatan.
 */
class OverdueReport
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

    public function __construct()
    {
        $this->sorter = new QuerySorter;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(array $filters, int $perPage, ?string $sort = null, ?string $direction = null): LengthAwarePaginator
    {
        $asOf = $this->asOfDate($filters);
        $query = $this->query($filters, $asOf);

        $this->sorter->apply($query, $sort, $direction, [
            'loan_number' => 'loan_number',
            'installment_number' => 'installment_number',
            'due_date' => 'due_date',
            'total_amount' => 'total_amount',
            'paid_amount' => 'paid_amount',
            'remaining_amount' => 'remaining_amount',
            'days_late' => 'due_date',
            'status' => 'status',
        ], [['due_date', 'asc'], ['installment_number', 'asc']]);

        return $query
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (InstallmentSchedule $schedule) => $this->toRow($schedule, $asOf));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function summary(array $filters): array
    {
        $asOf = $this->asOfDate($filters);
        $base = $this->query($filters, $asOf);

        $oldestDueDate = (clone $base)->min('due_date');

        return [
            'as_of_date' => $asOf->toDateString(),
            'schedules_count' => (clone $base)->count(),
            'loans_count' => (clone $base)->distinct()->count('loan_id'),
            'total_remaining' => round((float) (clone $base)->sum('remaining_amount'), 2),
            'total_billed' => round((float) (clone $base)->sum('total_amount'), 2),
            'total_paid' => round((float) (clone $base)->sum('paid_amount'), 2),
            'oldest_due_date' => $oldestDueDate,
            'max_days_late' => $oldestDueDate === null
                ? 0
                : (int) Carbon::parse($oldestDueDate)->startOfDay()->diffInDays($asOf),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Generator<int, array<int, string|float|int>>
     */
    public function stream(array $filters): Generator
    {
        $asOf = $this->asOfDate($filters);

        foreach ($this->query($filters, $asOf)->orderBy('due_date')->orderBy('id')->lazy() as $schedule) {
            $row = $this->toRow($schedule, $asOf);

            yield [
                $row['loan_number'],
                $row['member_number'],
                $row['member_name'],
                $row['installment_number'],
                $row['due_date'] ?? '-',
                $row['total_amount'],
                $row['paid_amount'],
                $row['remaining_amount'],
                $row['status_label'],
                $row['days_late'],
            ];
        }
    }

    /**
     * @return array<int, string>
     */
    public function csvHeaders(): array
    {
        return [
            'Nomor Pinjaman',
            'Nomor Anggota',
            'Nama Anggota',
            'Angsuran Ke',
            'Jatuh Tempo',
            'Nominal Tagihan',
            'Sudah Dialokasikan',
            'Sisa Tagihan',
            'Status Angsuran',
            'Hari Keterlambatan',
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<InstallmentSchedule>
     */
    private function query(array $filters, Carbon $asOf): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $minimumDays = (int) ($filters['minimum_days_late'] ?? 0);

        return InstallmentSchedule::query()
            ->with([
                'loan:id,loan_number,member_id',
                'loan.member:id,member_number,full_name',
            ])
            ->where('status', '!=', 'PAID')
            ->where('remaining_amount', '>', 0)
            // Jatuh tempo harus sudah lewat dari tanggal operasional laporan.
            ->whereDate('due_date', '<', $asOf->toDateString())
            ->when($minimumDays > 0, fn (Builder $q) => $q->whereDate('due_date', '<=', $asOf->copy()->subDays($minimumDays)->toDateString()))
            ->when($search !== '', function (Builder $q) use ($search) {
                $term = '%'.mb_strtolower($search).'%';

                $q->whereHas('loan', function (Builder $loan) use ($term) {
                    $loan->whereRaw('LOWER(loan_number) LIKE ?', [$term])
                        ->orWhereHas('member', function (Builder $member) use ($term) {
                            $member->whereRaw('LOWER(full_name) LIKE ?', [$term])
                                ->orWhereRaw('LOWER(member_number) LIKE ?', [$term]);
                        });
                });
            });
    }

    /**
     * Tanggal operasional yang dipakai sebagai dasar perhitungan tunggakan.
     *
     * @param  array<string, mixed>  $filters
     */
    private function asOfDate(array $filters): Carbon
    {
        $value = $filters['as_of_date'] ?? null;

        if (is_string($value) && $value !== '') {
            try {
                return Carbon::parse($value)->startOfDay();
            } catch (\Throwable) {
                // jatuh ke tanggal operasional hari ini
            }
        }

        return ReportPeriod::today();
    }

    /**
     * @return array<string, mixed>
     */
    private function toRow(InstallmentSchedule $schedule, Carbon $asOf): array
    {
        $statusLabels = [
            'UPCOMING' => 'Belum Jatuh Tempo',
            'DUE' => 'Jatuh Tempo',
            'PARTIALLY_PAID' => 'Dibayar Sebagian',
            'OVERDUE' => 'Terlambat',
        ];

        $dueDate = $schedule->due_date;

        return [
            'id' => $schedule->id,
            'loan_id' => $schedule->loan_id,
            'loan_number' => $schedule->loan?->loan_number,
            'member_number' => $schedule->loan?->member?->member_number,
            'member_name' => $schedule->loan?->member?->full_name,
            'installment_number' => (int) $schedule->installment_number,
            'due_date' => $dueDate?->toDateString(),
            'total_amount' => round((float) $schedule->total_amount, 2),
            'paid_amount' => round((float) $schedule->paid_amount, 2),
            'remaining_amount' => round((float) $schedule->remaining_amount, 2),
            'status' => $schedule->status,
            'status_label' => $statusLabels[$schedule->status] ?? $schedule->status,
            // Hitung selisih dari tanggal murni (bukan timestamp) agar hasil tidak
            // tergeser oleh perbedaan zona waktu antara cast tanggal dan zona operasional.
            'days_late' => $dueDate === null
                ? 0
                : (int) round((strtotime($asOf->toDateString()) - strtotime($dueDate->toDateString())) / 86400),
            'loan_url' => $schedule->loan_id !== null ? route('pinjaman.show', $schedule->loan_id) : null,
            'schedule_url' => $schedule->loan_id !== null ? route('angsuran.schedule', $schedule->loan_id) : null,
        ];
    }
}
