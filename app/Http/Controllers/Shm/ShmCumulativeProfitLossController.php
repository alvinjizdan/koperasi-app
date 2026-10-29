<?php

namespace App\Http\Controllers\Shm;

use App\Http\Controllers\Controller;
use App\Services\Accounting\FinancialReportService;
use App\Services\Accounting\SimpleXlsxService;
use App\Support\AccountingContext;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ShmCumulativeProfitLossController extends Controller
{
    public function __construct(
        private FinancialReportService $reportService,
        private SimpleXlsxService $xlsx
    ) {}

    /**
     * Display SHM Cumulative Profit & Loss (Laba Rugi Kumulatif) report.
     */
    public function index(Request $request): Response
    {
        $reportDate = $this->resolveReportDate($request);
        $cumulativeStartDate = $this->resolveCumulativeStartDate();

        // Context is strictly server-controlled: AccountingContext::SHM
        // Cumulative calculation spans from inception (or beginning of records) through reportDate
        $report = $this->reportService->incomeStatement(
            startDate: $cumulativeStartDate,
            endDate: $reportDate,
            context: AccountingContext::SHM
        );

        return Inertia::render('Shm/Accounting/CumulativeProfitLoss', [
            'report' => $report,
            'filters' => [
                'report_date' => $reportDate,
                'cumulative_start_date' => $cumulativeStartDate,
            ],
            'meta' => [
                'is_inception_cumulative' => $cumulativeStartDate === null,
                'starting_point_label' => $cumulativeStartDate
                    ? Carbon::parse($cumulativeStartDate)->format('d-m-Y')
                    : 'Awal Pencatatan (Inception)',
            ],
        ]);
    }

    /**
     * Export SHM Cumulative Profit & Loss (Laba Rugi Kumulatif) report to XLSX.
     */
    public function export(Request $request): BinaryFileResponse
    {
        $reportDate = $this->resolveReportDate($request);
        $cumulativeStartDate = $this->resolveCumulativeStartDate();

        // Context is strictly server-controlled: AccountingContext::SHM
        $report = $this->reportService->incomeStatement(
            startDate: $cumulativeStartDate,
            endDate: $reportDate,
            context: AccountingContext::SHM
        );

        $startLabel = $cumulativeStartDate
            ? Carbon::parse($cumulativeStartDate)->format('d-m-Y')
            : 'Awal Pencatatan';
        $reportDateLabel = Carbon::parse($reportDate)->format('d-m-Y');

        $rows = [
            ['Laporan Laba Rugi Kumulatif SHM'],
            ['Entitas: Akuntansi SHM'],
            ['Awal Kumulatif: '.$startLabel],
            ['Per Tanggal Laporan: '.$reportDateLabel],
            [''],
        ];

        // Revenue Section
        $revenueSection = $report['sections']['revenue'] ?? ['label' => 'Pendapatan', 'accounts' => [], 'total' => 0.0];
        $rows[] = ['PENDAPATAN KUMULATIF (CUMULATIVE REVENUE)', '', ''];
        $rows[] = ['Kode Akun', 'Nama Akun', 'Jumlah Kumulatif (Rp)'];
        if (empty($revenueSection['accounts'])) {
            $rows[] = ['-', 'Tidak ada mutasi pendapatan kumulatif hingga tanggal ini', '0.00'];
        } else {
            foreach ($revenueSection['accounts'] as $acc) {
                $rows[] = [
                    $acc['code'],
                    $acc['name'],
                    number_format($acc['balance'], 2, '.', ''),
                ];
            }
        }
        $rows[] = [
            'TOTAL PENDAPATAN KUMULATIF',
            '',
            number_format($revenueSection['total'], 2, '.', ''),
        ];
        $rows[] = [''];

        // Expense Section
        $expenseSection = $report['sections']['expense'] ?? ['label' => 'Beban', 'accounts' => [], 'total' => 0.0];
        $rows[] = ['BEBAN KUMULATIF (CUMULATIVE EXPENSE)', '', ''];
        $rows[] = ['Kode Akun', 'Nama Akun', 'Jumlah Kumulatif (Rp)'];
        if (empty($expenseSection['accounts'])) {
            $rows[] = ['-', 'Tidak ada mutasi beban kumulatif hingga tanggal ini', '0.00'];
        } else {
            foreach ($expenseSection['accounts'] as $acc) {
                $rows[] = [
                    $acc['code'],
                    $acc['name'],
                    number_format($acc['balance'], 2, '.', ''),
                ];
            }
        }
        $rows[] = [
            'TOTAL BEBAN KUMULATIF',
            '',
            number_format($expenseSection['total'], 2, '.', ''),
        ];
        $rows[] = [''];

        // Summary Section
        $summary = $report['summary'];
        $rows[] = ['RINGKASAN HASIL KUMULATIF', '', ''];
        $rows[] = ['Total Pendapatan Kumulatif', '', number_format($summary['total_revenue'], 2, '.', '')];
        $rows[] = ['Total Beban Kumulatif', '', number_format($summary['total_expense'], 2, '.', '')];
        $rows[] = ['Laba / Rugi Kumulatif Bersih', '', number_format($summary['net_profit_loss'], 2, '.', '')];
        $rows[] = ['Status Hasil Kumulatif', '', $summary['status']];

        $dateSuffix = str_replace('-', '', $reportDate);
        $filePath = sys_get_temp_dir().'/shm_laba_rugi_kumulatif_'.$dateSuffix.'_'.uniqid().'.xlsx';
        $this->xlsx->writeRows($filePath, $rows, 'Laba_Rugi_Kumulatif_SHM');

        $fileName = 'Laba_Rugi_Kumulatif_SHM_'.$dateSuffix.'.xlsx';

        return response()->download($filePath, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    private function resolveReportDate(Request $request): string
    {
        $reportDate = $request->query('report_date')
            ?? $request->query('as_of_date')
            ?? $request->query('end_date');

        if ($reportDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $reportDate)) {
            return $reportDate;
        }

        return now()->toDateString();
    }

    /**
     * Resolves the authoritative cumulative starting date.
     * Following existing codebase baseline (see FinancialReportService::balanceSheet):
     * The repository has no fiscal closing / rollover entity, so cumulative calculation
     * spans from inception (null startDate). If the earliest POSTED SHM journal exists,
     * its date provides an informative beginning reference.
     */
    private function resolveCumulativeStartDate(): ?string
    {
        // By architecture, null start date in accountTotals(null, $endDate) includes
        // all transactions up to $endDate.
        return null;
    }
}
