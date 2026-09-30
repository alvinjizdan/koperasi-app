<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReportFilterRequest;
use App\Models\SavingProduct;
use App\Models\User;
use App\Services\Reports\CashDailyReport;
use App\Services\Reports\CashTransactionReport;
use App\Services\Reports\InstallmentReport;
use App\Services\Reports\LoanPositionReport;
use App\Services\Reports\LoanReport;
use App\Services\Reports\MemberReport;
use App\Services\Reports\OverdueReport;
use App\Services\Reports\ReportExporter;
use App\Services\Reports\ReportPeriod;
use App\Services\Reports\SavingsReport;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Laporan dasar koperasi.
 *
 * Seluruh laporan hanya membaca data dari modul sumber tanpa mengubahnya.
 * Angka posisi (saldo) dan angka mutasi periode selalu dipisahkan, dan total
 * hanya dihitung dari transaksi berstatus sah.
 */
class ReportController extends Controller
{
    /**
     * Halaman daftar laporan dasar.
     */
    public function index(): Response
    {
        return Inertia::render('Laporan/Index', [
            'operational_timezone' => ReportPeriod::timezone(),
        ]);
    }

    /**
     * Laporan anggota.
     */
    public function members(ReportFilterRequest $request): Response
    {
        $filters = $request->filters();
        $report = new MemberReport;

        return Inertia::render('Laporan/Anggota', [
            'rows' => $report->paginate($filters, $request->perPage(), $request->sort(), $request->sortDirection()),
            'summary' => $report->summary($filters),
            'filters' => [
                'status' => $filters['status'],
                'search' => $filters['search'],
                'date_from' => $filters['date_from'] ?? '',
                'date_to' => $filters['date_to'] ?? '',
            ],
            'options' => [
                'statuses' => [
                    ['value' => 'all', 'label' => 'Semua Status'],
                    ['value' => 'active', 'label' => 'Aktif'],
                    ['value' => 'inactive', 'label' => 'Nonaktif'],
                ],
            ],
            'sort' => $report->sortStatus(),
            'report_slug' => 'anggota',
            'export_route' => 'laporan.export',
            'operational_timezone' => ReportPeriod::timezone(),
        ]);
    }

    /**
     * Laporan simpanan.
     */
    public function savings(ReportFilterRequest $request): Response
    {
        $filters = $request->filters();
        $period = ReportPeriod::fromFilters($filters);
        $report = new SavingsReport($period);

        return Inertia::render('Laporan/Simpanan', [
            'rows' => $report->paginate($filters, $request->perPage(), $request->sort(), $request->sortDirection()),
            'summary' => $report->summary($filters),
            'period' => $period->toArray(),
            'filters' => $this->periodFilters($filters) + [
                'product_id' => $filters['product_id'] ?? '',
                'account_status' => $filters['account_status'],
            ],
            'options' => [
                'products' => SavingProduct::query()->orderBy('name')->get(['id', 'name'])
                    ->map(fn (SavingProduct $product) => ['value' => $product->id, 'label' => $product->name])
                    ->all(),
                'accountStatuses' => [
                    ['value' => 'all', 'label' => 'Semua Status'],
                    ['value' => 'active', 'label' => 'Aktif'],
                    ['value' => 'closed', 'label' => 'Ditutup'],
                ],
            ],
            'sort' => $report->sortStatus(),
            'report_slug' => 'simpanan',
            'export_route' => 'laporan.export',
            'operational_timezone' => ReportPeriod::timezone(),
        ]);
    }

    /**
     * Laporan pinjaman.
     */
    public function loans(ReportFilterRequest $request): Response
    {
        $filters = $request->filters();
        $period = ReportPeriod::fromFilters($filters);
        $report = new LoanReport($period);

        return Inertia::render('Laporan/Pinjaman', [
            'rows' => $report->paginate($filters, $request->perPage(), $request->sort(), $request->sortDirection()),
            'summary' => $report->summary($filters),
            'period' => $period->toArray(),
            'filters' => $this->periodFilters($filters) + [
                'status' => $filters['status'],
                'period_basis' => $filters['period_basis'],
            ],
            'options' => [
                'statuses' => [
                    ['value' => 'all', 'label' => 'Semua Status'],
                    ['value' => 'PENDING', 'label' => 'Diajukan'],
                    ['value' => 'APPROVED', 'label' => 'Disetujui'],
                    ['value' => 'REJECTED', 'label' => 'Ditolak'],
                    ['value' => 'ACTIVE', 'label' => 'Aktif'],
                    ['value' => 'DISBURSED', 'label' => 'Dicairkan'],
                    ['value' => 'PAID_OFF', 'label' => 'Lunas'],
                ],
                'periodBasis' => [
                    ['value' => 'submission', 'label' => 'Tanggal Pengajuan'],
                    ['value' => 'disbursement', 'label' => 'Tanggal Pencairan'],
                ],
            ],
            'sort' => $report->sortStatus(),
            'report_slug' => 'pinjaman',
            'export_route' => 'laporan.export',
            'operational_timezone' => ReportPeriod::timezone(),
        ]);
    }

    /**
     * Laporan pembayaran angsuran.
     */
    public function installments(ReportFilterRequest $request): Response
    {
        $filters = $request->filters();
        $period = ReportPeriod::fromFilters($filters);
        $report = new InstallmentReport($period);

        return Inertia::render('Laporan/Angsuran', [
            'rows' => $report->paginate($filters, $request->perPage(), $request->sort(), $request->sortDirection()),
            'summary' => $report->summary($filters),
            'period' => $period->toArray(),
            'filters' => $this->periodFilters($filters) + [
                'status' => $filters['status'],
                'operator_id' => $filters['operator_id'] ?? '',
            ],
            'options' => [
                'operators' => $this->operatorOptions(),
                'statuses' => [
                    ['value' => 'all', 'label' => 'Semua Status'],
                    ['value' => 'COMPLETED', 'label' => 'Berhasil'],
                    ['value' => 'REVERSED', 'label' => 'Dibatalkan'],
                ],
            ],
            'sort' => $report->sortStatus(),
            'report_slug' => 'angsuran',
            'export_route' => 'laporan.export',
            'operational_timezone' => ReportPeriod::timezone(),
        ]);
    }

    /**
     * Laporan tunggakan angsuran.
     */
    public function overdue(ReportFilterRequest $request): Response
    {
        $filters = $request->filters();
        $report = new OverdueReport;

        return Inertia::render('Laporan/Tunggakan', [
            'rows' => $report->paginate($filters, $request->perPage(), $request->sort(), $request->sortDirection()),
            'summary' => $report->summary($filters),
            'filters' => [
                'search' => $filters['search'],
                'as_of_date' => $filters['as_of_date'] ?? ReportPeriod::todayDate(),
                'minimum_days_late' => $filters['minimum_days_late'],
            ],
            'options' => [],
            'sort' => $report->sortStatus(),
            'report_slug' => 'tunggakan',
            'export_route' => 'laporan.export',
            'operational_timezone' => ReportPeriod::timezone(),
        ]);
    }

    /**
     * Laporan Posisi Pinjaman per Tanggal (Ticket 26).
     *
     * Sumber data = snapshot tersimpan — read-only murni; tidak ada perhitungan
     * ulang, tidak ada fallback ke saldo hari ini, tidak ada akrual penalti.
     */
    public function loanPositions(ReportFilterRequest $request): Response
    {
        $filters = $request->filters();
        $report = new LoanPositionReport;

        $asOfDate = $filters['as_of_date'] ?? ReportPeriod::todayDate();
        $searchFilters = ['as_of_date' => $asOfDate, 'search' => $filters['search']];

        $rows = $report->paginate($searchFilters, $request->perPage(), $request->sort(), $request->sortDirection())->through(fn ($snapshot) => [
            'id' => $snapshot->id,
            'loan_id' => $snapshot->loan_id,
            'as_of_date' => (string) $snapshot->as_of_date,
            'captured_at' => $snapshot->created_at->toDateTimeString(),
            'captured_after_as_of' => $snapshot->created_at->toDateString() > (string) $snapshot->as_of_date,
            'verified_historical' => $snapshot->created_at->toDateString() <= (string) $snapshot->as_of_date,
            'status' => $snapshot->status,
            'outstanding_principal' => (float) $snapshot->outstanding_principal,
            'outstanding_interest' => (float) $snapshot->outstanding_interest,
            'outstanding_penalty' => (float) $snapshot->outstanding_penalty,
            'total_owed' => (float) $snapshot->total_owed,
            'total_paid' => $snapshot->total_paid !== null ? (float) $snapshot->total_paid : null,
            'loan' => $snapshot->loan ? [
                'id' => $snapshot->loan->id,
                'loan_number' => $snapshot->loan->loan_number,
                'member' => $snapshot->loan->member ? [
                    'member_number' => $snapshot->loan->member->member_number,
                    'full_name' => $snapshot->loan->member->full_name,
                ] : null,
            ] : null,
        ]);

        return Inertia::render('Laporan/PosisiPinjaman', [
            'rows' => $rows,
            'sort' => $report->sortStatus(),
            'summary' => $report->summary($searchFilters),
            'availability' => $report->availability($asOfDate),
            'filters' => [
                'search' => $filters['search'],
                'as_of_date' => $asOfDate,
            ],
            'operational_timezone' => ReportPeriod::timezone(),
        ]);
    }

    /**
     * Ekspor CSV Posisi Pinjaman per Tanggal (Ticket 27).
     *
     * Sumber data & filter identik dengan laporan UI; streaming read-only dari
     * snapshot tersimpan. Permission `laporan.export` terpisah di route —
     * melihat laporan tidak otomatis memberi hak ekspor.
     */
    public function exportLoanPositions(ReportFilterRequest $request): StreamedResponse
    {
        $filters = $request->filters();
        $asOfDate = $filters['as_of_date'] ?? ReportPeriod::todayDate();
        $report = new LoanPositionReport;

        return app(ReportExporter::class)->download(
            sprintf('posisi-pinjaman-%s.csv', $asOfDate),
            $report->csvHeaders(),
            $report->stream(['as_of_date' => $asOfDate, 'search' => $filters['search']]),
        );
    }

    /**
     * Laporan transaksi kas.
     */
    public function cashTransactions(ReportFilterRequest $request): Response
    {
        $filters = $request->filters();
        $period = ReportPeriod::fromFilters($filters);
        $report = new CashTransactionReport($period);

        return Inertia::render('Laporan/TransaksiKas', [
            'rows' => $report->paginate($filters, $request->perPage(), $request->sort(), $request->sortDirection()),
            'summary' => $report->summary($filters),
            'period' => $period->toArray(),
            'filters' => $this->periodFilters($filters) + [
                'type' => $filters['type'],
                'category' => $filters['category'],
                'status' => $filters['status'],
                'operator_id' => $filters['operator_id'] ?? '',
            ],
            'options' => [
                'types' => [
                    ['value' => 'all', 'label' => 'Semua Jenis'],
                    ['value' => 'INCOME', 'label' => 'Penerimaan'],
                    ['value' => 'EXPENSE', 'label' => 'Pengeluaran'],
                    ['value' => 'DEPOSIT', 'label' => 'Setoran Kasir'],
                ],
                'categories' => collect(config('cashier.categories.income'))
                    ->merge(config('cashier.categories.expense'))
                    ->push(config('cashier.deposit.category'))
                    ->unique()
                    ->values()
                    ->map(fn (string $category) => ['value' => $category, 'label' => $category])
                    ->all(),
                'statuses' => [
                    ['value' => 'all', 'label' => 'Semua Status'],
                    ['value' => 'POSTED', 'label' => 'Berlaku'],
                    ['value' => 'REVERSED', 'label' => 'Dibatalkan'],
                ],
                'operators' => $this->operatorOptions(),
            ],
            'sort' => $report->sortStatus(),
            'report_slug' => 'transaksi-kas',
            'export_route' => 'laporan.export',
            'operational_timezone' => ReportPeriod::timezone(),
        ]);
    }

    /**
     * Laporan kas harian (satu baris per sesi kasir).
     */
    public function cashDaily(ReportFilterRequest $request): Response
    {
        $filters = $request->filters();
        $period = ReportPeriod::fromFilters([
            ...$filters,
            'period' => $request->input('period', ReportPeriod::PRESET_TODAY),
        ]);
        $report = new CashDailyReport($period);

        return Inertia::render('Laporan/KasHarian', [
            'rows' => $report->paginate($filters, $request->perPage(), $request->sort(), $request->sortDirection()),
            'summary' => $report->summary($filters),
            'period' => $period->toArray(),
            'filters' => $this->periodFilters($filters) + [
                'status' => $filters['status'],
                'operator_id' => $filters['operator_id'] ?? '',
            ],
            'options' => [
                'operators' => $this->operatorOptions(),
                'statuses' => [
                    ['value' => 'all', 'label' => 'Semua Status'],
                    ['value' => 'OPEN', 'label' => 'Terbuka'],
                    ['value' => 'CLOSED', 'label' => 'Ditutup'],
                ],
            ],
            'sort' => $report->sortStatus(),
            'report_slug' => 'kas-harian',
            'export_route' => 'laporan.export',
            'operational_timezone' => ReportPeriod::timezone(),
        ]);
    }

    /**
     * Filter periode yang dipakai bersama laporan berbasis transaksi.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function periodFilters(array $filters): array
    {
        return [
            'period' => $filters['period'],
            'date_from' => $filters['date_from'] ?? '',
            'date_to' => $filters['date_to'] ?? '',
            'search' => $filters['search'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function operatorOptions(): array
    {
        return User::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $user) => ['value' => $user->id, 'label' => $user->name])
            ->all();
    }
}
