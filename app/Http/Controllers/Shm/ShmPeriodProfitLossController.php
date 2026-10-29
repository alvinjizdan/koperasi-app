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

class ShmPeriodProfitLossController extends Controller
{
    public function __construct(
        private FinancialReportService $reportService,
        private SimpleXlsxService $xlsx
    ) {}

    /**
     * Display SHM Period Profit & Loss (Laba Rugi Periode) report.
     */
    public function index(Request $request): Response
    {
        [$startDate, $endDate] = $this->resolvePeriod($request);

        // Context is strictly server-controlled: AccountingContext::SHM
        $report = $this->reportService->incomeStatement(
            startDate: $startDate,
            endDate: $endDate,
            context: AccountingContext::SHM
        );

        return Inertia::render('Shm/Accounting/PeriodProfitLoss', [
            'report' => $report,
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
        ]);
    }

    /**
     * Export SHM Period Profit & Loss (Laba Rugi Periode) report to XLSX.
     */
    public function export(Request $request): BinaryFileResponse
    {
        [$startDate, $endDate] = $this->resolvePeriod($request);

        // Context is strictly server-controlled: AccountingContext::SHM
        $report = $this->reportService->incomeStatement(
            startDate: $startDate,
            endDate: $endDate,
            context: AccountingContext::SHM
        );

        $periodLabel = 'Periode: '.($startDate ? Carbon::parse($startDate)->format('d-m-Y') : 'Awal').' s.d. '.($endDate ? Carbon::parse($endDate)->format('d-m-Y') : 'Sekarang');

        $rows = [
            ['Laporan Laba Rugi Periode SHM'],
            [$periodLabel],
            ['Entitas: Akuntansi SHM'],
            [''],
        ];

        // Revenue Section
        $revenueSection = $report['sections']['revenue'] ?? ['label' => 'Pendapatan', 'accounts' => [], 'total' => 0.0];
        $rows[] = ['PENDAPATAN (REVENUE)', '', ''];
        $rows[] = ['Kode Akun', 'Nama Akun', 'Jumlah (Rp)'];
        if (empty($revenueSection['accounts'])) {
            $rows[] = ['-', 'Tidak ada mutasi pendapatan pada periode ini', '0.00'];
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
            'TOTAL PENDAPATAN',
            '',
            number_format($revenueSection['total'], 2, '.', ''),
        ];
        $rows[] = [''];

        // Expense Section
        $expenseSection = $report['sections']['expense'] ?? ['label' => 'Beban', 'accounts' => [], 'total' => 0.0];
        $rows[] = ['BEBAN (EXPENSE)', '', ''];
        $rows[] = ['Kode Akun', 'Nama Akun', 'Jumlah (Rp)'];
        if (empty($expenseSection['accounts'])) {
            $rows[] = ['-', 'Tidak ada mutasi beban pada periode ini', '0.00'];
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
            'TOTAL BEBAN',
            '',
            number_format($expenseSection['total'], 2, '.', ''),
        ];
        $rows[] = [''];

        // Summary Section
        $summary = $report['summary'];
        $rows[] = ['RINGKASAN HASIL PERIODE', '', ''];
        $rows[] = ['Total Pendapatan', '', number_format($summary['total_revenue'], 2, '.', '')];
        $rows[] = ['Total Beban', '', number_format($summary['total_expense'], 2, '.', '')];
        $rows[] = ['Laba / Rugi Bersih (Net Profit/Loss)', '', number_format($summary['net_profit_loss'], 2, '.', '')];
        $rows[] = ['Status Hasil', '', $summary['status']];

        $dateSuffix = ($startDate ? str_replace('-', '', $startDate) : 'AWAL').'_'.($endDate ? str_replace('-', '', $endDate) : 'SEKARANG');
        $filePath = sys_get_temp_dir().'/shm_laba_rugi_'.$dateSuffix.'_'.uniqid().'.xlsx';
        $this->xlsx->writeRows($filePath, $rows, 'Laba_Rugi_SHM');

        $fileName = 'Laba_Rugi_Periode_SHM_'.$dateSuffix.'.xlsx';

        return response()->download($filePath, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function resolvePeriod(Request $request): array
    {
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');

        if ($startDate && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
            $startDate = null;
        }

        if ($endDate && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
            $endDate = null;
        }

        // If dates are inverted, flip them gracefully
        if ($startDate && $endDate && $startDate > $endDate) {
            [$startDate, $endDate] = [$endDate, $startDate];
        }

        return [$startDate, $endDate];
    }
}
