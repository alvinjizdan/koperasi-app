<?php

namespace App\Http\Controllers\Shm;

use App\Http\Controllers\Controller;
use App\Models\AccountingImportBatch;
use App\Models\AccountingImportedReport;
use App\Models\JournalEntry;
use App\Services\Accounting\JournalService;
use App\Services\Accounting\ShmJournalImportService;
use App\Services\Accounting\ShmReportImportService;
use App\Support\AccountingContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ShmAccountingImportController extends Controller
{
    public function __construct(
        private readonly ShmJournalImportService $journalImportService,
        private readonly ShmReportImportService $reportImportService,
        private readonly JournalService $journalService,
    ) {}

    /**
     * Display Import Center page (Batches, Journal & Report imports).
     */
    public function index(Request $request): Response
    {
        $type = $request->query('type', 'all');
        $query = AccountingImportBatch::query()
            ->with('importer:id,name')
            ->where('accounting_context', AccountingContext::SHM)
            ->latest('id');

        if ($type !== 'all' && in_array($type, [AccountingImportBatch::TYPE_JOURNAL, AccountingImportBatch::TYPE_REPORT], true)) {
            $query->where('import_type', $type);
        }

        $batches = $query->paginate(15)->withQueryString();

        return Inertia::render('Shm/Accounting/ImportCenter', [
            'batches' => $batches,
            'filters' => [
                'type' => $type,
            ],
            'availableReportTypes' => [
                ['value' => AccountingImportBatch::REPORT_BALANCE_SHEET, 'label' => 'Neraca (Balance Sheet)'],
                ['value' => AccountingImportBatch::REPORT_PERIOD_PROFIT_LOSS, 'label' => 'Laba Rugi Periode'],
                ['value' => AccountingImportBatch::REPORT_CUMULATIVE_PROFIT_LOSS, 'label' => 'Laba Rugi Kumulatif'],
                ['value' => AccountingImportBatch::REPORT_TRIAL_BALANCE, 'label' => 'Neraca Saldo (Trial Balance)'],
                ['value' => AccountingImportBatch::REPORT_DAILY_JOURNAL, 'label' => 'Buku Jurnal Harian (Daily Journal)'],
            ],
        ]);
    }

    /**
     * Preview XLSX Journal before importing.
     */
    public function previewJournal(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx', 'max:10240'],
        ]);

        $preview = $this->journalImportService->preview($request->file('file'));

        return response()->json($preview);
    }

    /**
     * Execute Journal Import.
     */
    public function storeJournal(Request $request): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx', 'max:10240'],
        ]);

        $batch = $this->journalImportService->import($request->file('file'), (int) auth()->id());

        return redirect()->route('shm.accounting-import.index')
            ->with('success', sprintf('Berhasil mengimpor %d jurnal DRAFT (Batch #%d).', $batch->success_count, $batch->id));
    }

    /**
     * Preview XLSX Report before importing.
     */
    public function previewReport(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx', 'max:10240'],
            'report_type' => ['required', 'string'],
        ]);

        $preview = $this->reportImportService->preview($request->file('file'), $request->input('report_type'));

        return response()->json($preview);
    }

    /**
     * Execute Report Import.
     */
    public function storeReport(Request $request): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx', 'max:10240'],
            'report_type' => ['required', 'string'],
        ]);

        $batch = $this->reportImportService->import(
            $request->file('file'),
            $request->input('report_type'),
            (int) auth()->id()
        );

        return redirect()->route('shm.accounting-import.index')
            ->with('success', sprintf('Berhasil mengimpor laporan %s (Batch #%d).', $batch->report_type, $batch->id));
    }

    /**
     * Show Batch Details and Data.
     */
    public function show(AccountingImportBatch $batch): Response
    {
        abort_unless($batch->accounting_context === AccountingContext::SHM, 404);

        $batch->load('importer:id,name');

        $journalEntries = null;
        $reportRows = null;

        if ($batch->import_type === AccountingImportBatch::TYPE_JOURNAL) {
            $journalEntries = JournalEntry::query()
                ->where('import_batch_id', $batch->id)
                ->with(['lines.account:id,code,name', 'creator:id,name', 'poster:id,name'])
                ->paginate(20);
        } else {
            $reportRows = AccountingImportedReport::query()
                ->where('batch_id', $batch->id)
                ->orderBy('row_index')
                ->paginate(50);
        }

        return Inertia::render('Shm/Accounting/ImportDetail', [
            'batch' => $batch,
            'journalEntries' => $journalEntries,
            'reportRows' => $reportRows,
        ]);
    }

    /**
     * Post a specific DRAFT journal entry manually.
     */
    public function postJournal(Request $request, JournalEntry $entry): RedirectResponse
    {
        abort_unless($entry->accounting_context === AccountingContext::SHM, 404);

        $this->journalService->post($entry, (int) auth()->id());

        return back()->with('success', sprintf('Jurnal %s berhasil diposting ke Buku Besar.', $entry->journal_number));
    }

    /**
     * Download Journal template.
     */
    public function journalTemplate(): BinaryFileResponse
    {
        $filePath = $this->journalImportService->generateTemplateXlsx();

        return response()->download($filePath, 'Template_Jurnal_SHM.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }
}
