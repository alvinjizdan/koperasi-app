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

class ShmBalanceSheetController extends Controller
{
    public function __construct(
        private FinancialReportService $reportService,
        private SimpleXlsxService $xlsx
    ) {}

    /**
     * Display SHM Balance Sheet (Neraca) report.
     */
    public function index(Request $request): Response
    {
        $asOfDate = $this->resolveAsOfDate($request);

        // Context is strictly server-controlled: AccountingContext::SHM
        $report = $this->reportService->balanceSheet(
            asOfDate: $asOfDate,
            context: AccountingContext::SHM
        );

        return Inertia::render('Shm/Accounting/BalanceSheet', [
            'report' => $report,
            'filters' => [
                'as_of_date' => $asOfDate,
            ],
        ]);
    }

    /**
     * Export SHM Balance Sheet (Neraca) report to XLSX.
     */
    public function export(Request $request): BinaryFileResponse
    {
        $asOfDate = $this->resolveAsOfDate($request);

        // Context is strictly server-controlled: AccountingContext::SHM
        $report = $this->reportService->balanceSheet(
            asOfDate: $asOfDate,
            context: AccountingContext::SHM
        );

        $formattedAsOf = Carbon::parse($asOfDate)->format('d-m-Y');

        $rows = [
            ['Neraca SHM (Balance Sheet)'],
            ['Entitas: Akuntansi SHM'],
            ['Posisi Per Tanggal: '.$formattedAsOf],
            [''],
        ];

        // 1. Assets Section
        $assets = $report['sections']['asset'] ?? ['label' => 'Aset', 'accounts' => [], 'total' => 0.0];
        $rows[] = ['ASET (ASSETS)', '', ''];
        $rows[] = ['Kode Akun', 'Nama Akun', 'Saldo (Rp)'];
        if (empty($assets['accounts'])) {
            $rows[] = ['-', 'Tidak ada mutasi aset s.d. tanggal ini', '0.00'];
        } else {
            foreach ($assets['accounts'] as $acc) {
                $rows[] = [
                    $acc['code'],
                    $acc['name'],
                    number_format($acc['balance'], 2, '.', ''),
                ];
            }
        }
        $rows[] = ['TOTAL ASET', '', number_format($assets['total'], 2, '.', '')];
        $rows[] = [''];

        // 2. Liabilities Section
        $liabilities = $report['sections']['liability'] ?? ['label' => 'Liabilitas', 'accounts' => [], 'total' => 0.0];
        $rows[] = ['LIABILITAS (LIABILITIES)', '', ''];
        $rows[] = ['Kode Akun', 'Nama Akun', 'Saldo (Rp)'];
        if (empty($liabilities['accounts'])) {
            $rows[] = ['-', 'Tidak ada mutasi liabilitas s.d. tanggal ini', '0.00'];
        } else {
            foreach ($liabilities['accounts'] as $acc) {
                $rows[] = [
                    $acc['code'],
                    $acc['name'],
                    number_format($acc['balance'], 2, '.', ''),
                ];
            }
        }
        $rows[] = ['TOTAL LIABILITAS', '', number_format($liabilities['total'], 2, '.', '')];
        $rows[] = [''];

        // 3. Equity Section
        $equity = $report['sections']['equity'] ?? ['label' => 'Ekuitas', 'accounts' => [], 'total' => 0.0];
        $rows[] = ['EKUITAS (EQUITY)', '', ''];
        $rows[] = ['Kode Akun', 'Nama Akun', 'Saldo (Rp)'];
        if (empty($equity['accounts'])) {
            $rows[] = ['-', 'Tidak ada mutasi ekuitas s.d. tanggal ini', '0.00'];
        } else {
            foreach ($equity['accounts'] as $acc) {
                $rows[] = [
                    $acc['code'],
                    $acc['name'],
                    number_format($acc['balance'], 2, '.', ''),
                ];
            }
        }
        $rows[] = ['TOTAL EKUITAS', '', number_format($equity['total'], 2, '.', '')];
        $rows[] = [''];

        // 4. Accounting Equation Summary
        $summary = $report['summary'];
        $rows[] = ['RINGKASAN PERSAMAAN AKUNTANSI (ASSETS = LIABILITIES + EQUITY)', '', ''];
        $rows[] = ['Total Aset (A)', '', number_format($summary['total_assets'], 2, '.', '')];
        $rows[] = ['Total Liabilitas (L)', '', number_format($summary['total_liabilities'], 2, '.', '')];
        $rows[] = ['Total Ekuitas (E)', '', number_format($summary['total_equity'], 2, '.', '')];
        $rows[] = ['Total Liabilitas + Ekuitas (L + E)', '', number_format($summary['total_liabilities_and_equity'], 2, '.', '')];
        $rows[] = ['Selisih A - (L + E)', '', number_format($summary['difference'], 2, '.', '')];
        $rows[] = ['Status Keseimbangan', '', $summary['status']];
        $rows[] = ['Hasil Operasional Kumulatif Berjalan', '', number_format($summary['current_period_result'], 2, '.', '')];

        $dateSuffix = str_replace('-', '', $asOfDate);
        $filePath = sys_get_temp_dir().'/shm_neraca_'.$dateSuffix.'_'.uniqid().'.xlsx';
        $this->xlsx->writeRows($filePath, $rows, 'Neraca_SHM');

        $fileName = 'Neraca_SHM_'.$dateSuffix.'.xlsx';

        return response()->download($filePath, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    private function resolveAsOfDate(Request $request): string
    {
        $asOfDate = $request->query('as_of_date')
            ?? $request->query('report_date')
            ?? $request->query('date');

        if ($asOfDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOfDate)) {
            return $asOfDate;
        }

        return now()->toDateString();
    }
}
