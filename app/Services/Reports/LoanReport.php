<?php

namespace App\Services\Reports;

use App\Models\InstallmentPayment;
use App\Models\Loan;
use App\Models\LoanDisbursement;
use App\Support\QuerySorter;
use Generator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Laporan pinjaman.
 *
 * Pinjaman yang diajukan, disetujui, dan dicairkan dibedakan melalui status dan
 * tanggal masing-masing. Nominal dicairkan selalu diambil dari tabel
 * loan_disbursements, bukan dari nominal pengajuan. Sisa kewajiban mengikuti
 * rumus resmi modul Pinjaman (nominal dicairkan dikurangi pembayaran berhasil).
 */
class LoanReport
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

    /**
     * Status angsuran yang masih memiliki kewajiban berjalan.
     *
     * @var array<int, string>
     */
    private const OPEN_SCHEDULE_STATUSES = ['UPCOMING', 'DUE', 'PARTIALLY_PAID', 'OVERDUE'];

    /**
     * @var array<string, string>
     */
    private const STATUS_LABELS = [
        'PENDING' => 'Diajukan',
        'APPROVED' => 'Disetujui',
        'REJECTED' => 'Ditolak',
        'DISBURSED' => 'Dicairkan',
        'ACTIVE' => 'Aktif',
        'PAID_OFF' => 'Lunas',
        'CANCELLED' => 'Dibatalkan',
    ];

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
            ->withSum(['payments as paid_total' => fn ($q) => $q->where('status', 'COMPLETED')], 'amount')
            ->withMin(
                ['schedules as next_due_date' => fn ($q) => $q->whereIn('status', self::OPEN_SCHEDULE_STATUSES)->where('remaining_amount', '>', 0)],
                'due_date'
            );

        $this->sorter->apply($query, $sort, $direction, [
            'loan_number' => 'loan_number',
            'submission_date' => 'submission_date',
            'amount' => 'amount',
            'tenor_months' => 'tenor_months',
            'status' => 'status',
            'paid_total' => 'paid_total',
        ], [['submission_date', 'desc'], ['id', 'desc']]);

        return $query
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (Loan $loan) => $this->toRow($loan));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function summary(array $filters): array
    {
        $base = $this->query($filters);
        $loanIds = $base->select('id');

        $grouped = (clone $base)
            ->select('status', DB::raw('COUNT(*) AS total'), DB::raw('COALESCE(SUM(amount), 0) AS amount'))
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $disbursedAmount = round((float) LoanDisbursement::query()->whereIn('loan_id', $loanIds)->sum('amount'), 2);
        $disbursementCount = LoanDisbursement::query()->whereIn('loan_id', $loanIds)->count();
        $paidTotal = round((float) InstallmentPayment::query()
            ->where('status', 'COMPLETED')
            ->whereIn('loan_id', $loanIds)
            ->sum('amount'), 2);

        return [
            'total' => (clone $base)->count(),
            'submitted_amount' => round((float) (clone $base)->sum('amount'), 2),
            'by_status' => collect(self::STATUS_LABELS)
                ->map(fn (string $label, string $status) => [
                    'status' => $status,
                    'label' => $label,
                    'count' => (int) ($grouped[$status]->total ?? 0),
                    'amount' => round((float) ($grouped[$status]->amount ?? 0), 2),
                ])
                ->values()
                ->all(),
            'pending_count' => (int) ($grouped['PENDING']->total ?? 0),
            'approved_count' => (int) ($grouped['APPROVED']->total ?? 0),
            'disbursed_count' => $disbursementCount,
            'disbursed_amount' => $disbursedAmount,
            'has_disbursements' => $disbursementCount > 0,
            'paid_total' => $paidTotal,
            'outstanding_amount' => round(max(0.0, $disbursedAmount - $paidTotal), 2),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Generator<int, array<int, string|float|int>>
     */
    public function stream(array $filters): Generator
    {
        $query = $this->query($filters)
            ->withSum(['payments as paid_total' => fn ($q) => $q->where('status', 'COMPLETED')], 'amount')
            ->withMin(
                ['schedules as next_due_date' => fn ($q) => $q->whereIn('status', self::OPEN_SCHEDULE_STATUSES)->where('remaining_amount', '>', 0)],
                'due_date'
            );

        foreach ($query->orderBy('loan_number')->lazy() as $loan) {
            $row = $this->toRow($loan);

            yield [
                $row['loan_number'],
                $row['member_number'],
                $row['member_name'],
                $row['submission_date'] ?? '-',
                $row['approved_at'] ?? '-',
                $row['disbursed_at'] ?? '-',
                $row['amount'],
                $row['tenor_months'],
                $row['status_label'],
                $row['paid_total'],
                $row['remaining_balance'] ?? '-',
                $row['next_due_date'] ?? '-',
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
            'Tanggal Pengajuan',
            'Tanggal Persetujuan',
            'Tanggal Pencairan',
            'Nominal Pokok',
            'Tenor (bulan)',
            'Status',
            'Total Pembayaran Berhasil',
            'Sisa Kewajiban',
            'Jatuh Tempo Berikutnya',
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function query(array $filters): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $periodRange = $this->period->dateRange();
        $basis = ($filters['period_basis'] ?? 'submission') === 'disbursement' ? 'disbursement' : 'submission';

        return Loan::query()
            ->with([
                'member:id,member_number,full_name',
                'disbursement:id,loan_id,amount,disbursement_date,method',
            ])
            ->when(
                ($filters['status'] ?? 'all') !== 'all',
                fn (Builder $q) => $q->where('status', $filters['status'])
            )
            ->when(
                $basis === 'disbursement',
                fn (Builder $q) => $q->whereNotNull('disbursed_at')->whereBetween('disbursed_at', $periodRange),
                fn (Builder $q) => $q->whereBetween('submission_date', $periodRange)
            )
            ->when($search !== '', function (Builder $q) use ($search) {
                $term = '%'.mb_strtolower($search).'%';

                $q->where(function (Builder $inner) use ($term) {
                    $inner->whereRaw('LOWER(loan_number) LIKE ?', [$term])
                        ->orWhereHas('member', function (Builder $member) use ($term) {
                            $member->whereRaw('LOWER(full_name) LIKE ?', [$term])
                                ->orWhereRaw('LOWER(member_number) LIKE ?', [$term]);
                        });
                });
            });
    }

    /**
     * @return array<string, mixed>
     */
    private function toRow(Loan $loan): array
    {
        $paidTotal = round((float) ($loan->paid_total ?? 0), 2);
        $disbursedAmount = $loan->disbursement !== null ? round((float) $loan->disbursement->amount, 2) : null;

        return [
            'id' => $loan->id,
            'loan_number' => $loan->loan_number,
            'member_number' => $loan->member?->member_number,
            'member_name' => $loan->member?->full_name,
            'submission_date' => $loan->submission_date?->toDateString(),
            'approved_at' => $loan->approved_at?->toDateString(),
            'disbursed_at' => $loan->disbursed_at?->toDateString(),
            'amount' => round((float) $loan->amount, 2),
            'tenor_months' => (int) $loan->tenor_months,
            'status' => $loan->status,
            'status_label' => self::STATUS_LABELS[$loan->status] ?? $loan->status,
            'disbursed_amount' => $disbursedAmount,
            'disbursement_method' => $loan->disbursement?->method,
            'paid_total' => $paidTotal,
            // Rumus mengikuti Loan::getRemainingBalanceAttribute().
            'remaining_balance' => $disbursedAmount === null ? null : round(max(0.0, $disbursedAmount - $paidTotal), 2),
            'next_due_date' => $loan->next_due_date,
            'url' => route('pinjaman.show', $loan->id),
        ];
    }
}
