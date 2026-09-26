<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReportFilterRequest;
use App\Models\SavingProduct;
use App\Models\User;
use App\Services\Reports\CashDailyReport;
use App\Services\Reports\CashTransactionReport;
use App\Services\Reports\InstallmentReport;
use App\Services\Reports\LoanReport;
use App\Services\Reports\MemberReport;
use App\Services\Reports\OverdueReport;
use App\Services\Reports\ReportPeriod;
use App\Services\Reports\SavingsReport;
use Inertia\Inertia;
use Inertia\Response;

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
            'rows' => $report->paginate($filters, $request->perPage()),
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
            'rows' => $report->paginate($filters, $request->perPage()),
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
            'rows' => $report->paginate($filters, $request->perPage()),
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
            'rows' => $report->paginate($filters, $request->perPage()),
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
            'rows' => $report->paginate($filters, $request->perPage()),
            'summary' => $report->summary($filters),
            'filters' => [
                'search' => $filters['search'],
                'as_of_date' => $filters['as_of_date'] ?? ReportPeriod::todayDate(),
                'minimum_days_late' => $filters['minimum_days_late'],
            ],
            'options' => [],
            'report_slug' => 'tunggakan',
            'export_route' => 'laporan.export',
            'operational_timezone' => ReportPeriod::timezone(),
        ]);
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
            'rows' => $report->paginate($filters, $request->perPage()),
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
            'rows' => $report->paginate($filters, $request->perPage()),
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
