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

class ShmTrialBalanceController extends Controller
{
    public function __construct(
        private FinancialReportService $reportService,
        private SimpleXlsxService $xlsx
    ) {}

    /**
     * Display SHM Trial Balance (Neraca Saldo) report.
     */
    public function index(Request $request): Response
    {
        $asOfDate = $this->resolveAsOfDate($request);
        $startDate = $request->query('start_date');

        // Context is strictly server-controlled: AccountingContext::SHM
        $report = $this->reportService->trialBalance(
            startDate: $startDate,
            endDate: $asOfDate,
            includeEmpty: true,
            context: AccountingContext::SHM
        );

        return Inertia::render('Shm/Accounting/TrialBalance', [
            'report' => $report,
            'filters' => [
                'as_of_date' => $asOfDate,
                'start_date' => $startDate,
            ],
        ]);
    }

    /**
     * Export SHM Trial Balance (Neraca Saldo) report to XLSX.
     */
    public function export(Request $request): BinaryFileResponse
    {
        $asOfDate = $this->resolveAsOfDate($request);
        $startDate = $request->query('start_date');

        // Context is strictly server-controlled: AccountingContext::SHM
        $report = $this->reportService->trialBalance(
            startDate: $startDate,
            endDate: $asOfDate,
            includeEmpty: true,
            context: AccountingContext::SHM
        );

        $formattedAsOf = Carbon::parse($asOfDate)->format('d-m-Y');

        $rows = [
            ['Neraca Saldo SHM'],
            ['Per tanggal: '.$formattedAsOf],
            [''],
            [
                'Kode Akun',
                'Nama Akun',
                'Tipe Akun',
                'Saldo Normal',
                'Debit',
                'Kredit',
                'Saldo',
            ],
        ];

        foreach ($report['rows'] as $row) {
            $rows[] = [
                $row['code'],
                $row['name'],
                $row['category_label'] ?? $row['category'],
                strtoupper($row['normal_balance'] ?? ''),
                number_format($row['total_debit'], 2, '.', ''),
                number_format($row['total_credit'], 2, '.', ''),
                number_format($row['balance'], 2, '.', ''),
            ];
        }

        $rows[] = [''];
        $rows[] = [
            'TOTAL',
            '',
            '',
            '',
            number_format($report['totals']['debit'], 2, '.', ''),
            number_format($report['totals']['credit'], 2, '.', ''),
            '',
        ];
        $rows[] = [
            'Selisih (Difference)',
            '',
            '',
            '',
            number_format($report['totals']['difference'], 2, '.', ''),
            '',
            '',
        ];
        $rows[] = [
            'Status',
            $report['status'],
            '',
            '',
            '',
            '',
            '',
        ];

        $filePath = sys_get_temp_dir().'/shm_trial_balance_'.str_replace('-', '', $asOfDate).'_'.uniqid().'.xlsx';
        $this->xlsx->writeRows($filePath, $rows, 'Neraca_Saldo_SHM');

        $fileName = 'Neraca_Saldo_SHM_'.str_replace('-', '', $asOfDate).'.xlsx';

        return response()->download($filePath, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    private function resolveAsOfDate(Request $request): string
    {
        $asOfDate = $request->query('as_of_date') ?? $request->query('end_date');

        if ($asOfDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOfDate)) {
            return $asOfDate;
        }

        return now()->toDateString();
    }
}
