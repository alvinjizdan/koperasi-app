<?php

namespace App\Http\Controllers\Shm;

use App\Http\Controllers\Controller;
use App\Models\ChartOfAccount;
use App\Services\Accounting\ShmDailyJournalService;
use App\Services\Accounting\SimpleXlsxService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ShmDailyJournalController extends Controller
{
    public function __construct(
        private ShmDailyJournalService $journalService,
        private SimpleXlsxService $xlsx
    ) {}

    /**
     * Display SHM Daily Journal (Jurnal Harian) report.
     */
    public function index(Request $request): Response
    {
        $filters = $this->resolveFilters($request);
        $accounts = ChartOfAccount::query()->shm()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']);

        $paginatedEntries = $this->journalService->paginate($filters, 25);
        $totals = $this->journalService->calculateTotals($filters);

        return Inertia::render('Shm/Accounting/DailyJournal', [
            'entries' => $paginatedEntries,
            'totals' => $totals,
            'filters' => $filters,
            'accounts' => $accounts,
        ]);
    }

    /**
     * Export SHM Daily Journal (Jurnal Harian) report to XLSX.
     */
    public function export(Request $request): BinaryFileResponse
    {
        $filters = $this->resolveFilters($request);
        $entries = $this->journalService->getForExport($filters);

        // Header metadata sesuai contoh5.xlsx
        $startDateFormatted = $filters['start_date'] ? Carbon::parse($filters['start_date'])->isoFormat('DD MMMM YYYY') : 'Awal';
        $endDateFormatted = $filters['end_date'] ? Carbon::parse($filters['end_date'])->isoFormat('DD MMMM YYYY') : 'Sekarang';
        $periodString = "{$startDateFormatted} s.d. {$endDateFormatted}";

        $accountLabel = 'Semua Perkiraan';
        if (! empty($filters['account_id'])) {
            $selectedAccount = ChartOfAccount::query()->shm()->find($filters['account_id']);
            if ($selectedAccount) {
                $accountLabel = "{$selectedAccount->code} - {$selectedAccount->name}";
            }
        }

        $rows = [
            ['Jurnal Harian'],
            ['Periode   : '.$periodString],
            ['Perkiraan : '.$accountLabel],
            ['Kantor    : Semua Kantor'],
            [
                'No',
                'Tanggal',
                'Kode Perk.',
                'Perkiraan',
                'Voucher',
                'No Slip',
                'Keterangan',
                'Debet',
                'Kredit',
                'Nama',
                'No. Agt',
                'Rek. Agt',
            ],
        ];

        $groupNo = 1;
        $totalDebitAll = 0.0;
        $totalCreditAll = 0.0;

        foreach ($entries as $entry) {
            $firstLine = true;
            foreach ($entry['lines'] as $line) {
                $debitVal = $line['debit'] !== null ? number_format((float) $line['debit'], 2, '.', ',') : '';
                $creditVal = $line['credit'] !== null ? number_format((float) $line['credit'], 2, '.', ',') : '';

                if ($line['debit'] !== null) {
                    $totalDebitAll += (float) $line['debit'];
                }
                if ($line['credit'] !== null) {
                    $totalCreditAll += (float) $line['credit'];
                }

                if ($firstLine) {
                    $rows[] = [
                        (string) $groupNo,
                        $entry['formatted_date'] ?? '',
                        $line['account_code'],
                        $line['account_name'],
                        $entry['voucher'],
                        $entry['slip_number'],
                        $line['description'] ?? $entry['description'] ?? '',
                        $debitVal,
                        $creditVal,
                        $entry['customer_name'],
                        $entry['member_number'],
                        $entry['account_number'],
                    ];
                    $firstLine = false;
                } else {
                    $rows[] = [
                        '',
                        '',
                        $line['account_code'],
                        $line['account_name'],
                        '',
                        '',
                        $line['description'] ?? $entry['description'] ?? '',
                        $debitVal,
                        $creditVal,
                        '',
                        '',
                        '',
                    ];
                }
            }
            $groupNo++;
        }

        // Summary row
        $rows[] = [
            '',
            '',
            '',
            'TOTAL',
            '',
            '',
            '',
            number_format($totalDebitAll, 2, '.', ','),
            number_format($totalCreditAll, 2, '.', ','),
            '',
            '',
            '',
        ];

        $filePath = sys_get_temp_dir().'/jurnal_harian_shm_'.uniqid().'.xlsx';
        $sheetTitle = 'Jurnal_Harian_'.($filters['start_date'] ?? 'all').'_'.($filters['end_date'] ?? 'all');
        $safeSheetTitle = substr(preg_replace('/[^A-Za-z0-9_-]/', '_', $sheetTitle), 0, 31);

        $this->xlsx->writeRows($filePath, $rows, $safeSheetTitle ?: 'Jurnal_Harian');

        $fileName = 'Jurnal_Harian_SHM_'.($filters['start_date'] ?? 'all').'_'.($filters['end_date'] ?? 'all').'.xlsx';

        return response()->download($filePath, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /**
     * @return array{
     *     start_date: ?string,
     *     end_date: ?string,
     *     account_id: ?int,
     *     search: ?string,
     * }
     */
    private function resolveFilters(Request $request): array
    {
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');
        $accountId = $request->query('account_id');
        $search = $request->query('search');

        if ($startDate && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
            $startDate = null;
        }

        if ($endDate && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
            $endDate = null;
        }

        if ($startDate && $endDate && $startDate > $endDate) {
            [$startDate, $endDate] = [$endDate, $startDate];
        }

        return [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'account_id' => $accountId ? (int) $accountId : null,
            'search' => $search ? trim((string) $search) : null,
        ];
    }
}
