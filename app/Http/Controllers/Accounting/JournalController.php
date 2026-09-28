<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Services\Accounting\JournalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Jurnal umum (Ticket 18): daftar, form DRAFT, detail, posting, reversal.
 */
class JournalController extends Controller
{
    public function __construct(private JournalService $journals) {}

    public function index(): Response
    {
        $entries = JournalEntry::query()
            ->with(['creator:id,name', 'poster:id,name'])
            ->withCount('lines')
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (JournalEntry $entry) => [
                'id' => $entry->id,
                'journal_number' => $entry->journal_number,
                'transaction_date' => $entry->transaction_date->toDateString(),
                'description' => $entry->description,
                'status' => $entry->status,
                'posting_type' => $entry->posting_type,
                'source_type' => $entry->source_type,
                'source_id' => $entry->source_id,
                'reversed_by_entry_id' => $entry->reversed_by_entry_id,
                'line_count' => $entry->lines_count,
                'created_by' => $entry->creator?->name,
                'posted_by' => $entry->poster?->name,
                'posted_at' => $entry->posted_at?->format('Y-m-d H:i'),
            ]);

        return Inertia::render('Akuntansi/Jurnal/Index', [
            'entries' => $entries,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Akuntansi/Jurnal/Create', [
            'accounts' => $this->activeAccounts(),
        ]);
    }

    public function store(): RedirectResponse
    {
        [$data, $lines] = $this->validatedPayload();

        $entry = $this->journals->createDraft($data, $lines, (int) auth()->id());

        return redirect()->route('akuntansi.jurnal.show', $entry->id)
            ->with('success', 'Jurnal DRAFT '.$entry->journal_number.' dibuat. Periksa keseimbangan sebelum memposting.');
    }

    public function show(JournalEntry $entry): Response
    {
        $entry->load(['lines.account:id,code,name,category,normal_balance', 'creator:id,name', 'poster:id,name', 'reversedBy:id,journal_number']);

        return Inertia::render('Akuntansi/Jurnal/Show', [
            'entry' => [
                'id' => $entry->id,
                'journal_number' => $entry->journal_number,
                'transaction_date' => $entry->transaction_date->toDateString(),
                'description' => $entry->description,
                'status' => $entry->status,
                'posting_type' => $entry->posting_type,
                'source_type' => $entry->source_type,
                'source_id' => $entry->source_id,
                'reversed_by_entry_id' => $entry->reversed_by_entry_id,
                'reversed_by_number' => $entry->reversedBy?->journal_number,
                'created_by' => $entry->creator?->name,
                'posted_by' => $entry->poster?->name,
                'posted_at' => $entry->posted_at?->format('Y-m-d H:i'),
                'total_debit' => $entry->totalDebit(),
                'total_credit' => $entry->totalCredit(),
                'is_balanced' => $entry->isBalanced(),
                'lines' => $entry->lines->map(fn ($line) => [
                    'id' => $line->id,
                    'account_id' => $line->account_id,
                    'account_code' => $line->account?->code,
                    'account_name' => $line->account?->name,
                    'description' => $line->description,
                    'debit' => (float) $line->debit,
                    'credit' => (float) $line->credit,
                    'line_order' => $line->line_order,
                ]),
            ],
        ]);
    }

    public function update(JournalEntry $entry): RedirectResponse
    {
        [$data, $lines] = $this->validatedPayload();

        $this->journals->updateDraft($entry, $data, $lines);

        return redirect()->route('akuntansi.jurnal.show', $entry->id)
            ->with('success', 'Jurnal DRAFT diperbarui.');
    }

    public function post(JournalEntry $entry): RedirectResponse
    {
        try {
            $posted = $this->journals->post($entry, (int) auth()->id());
        } catch (ValidationException $e) {
            return redirect()->back()->withErrors($e->errors());
        }

        return redirect()->route('akuntansi.jurnal.show', $posted->id)
            ->with('success', 'Jurnal '.$posted->journal_number.' telah diposting ke buku besar.');
    }

    public function reverse(JournalEntry $entry): RedirectResponse
    {
        $reason = (string) request()->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ])['reason'];

        try {
            $reversal = $this->journals->reverse($entry, (int) auth()->id(), $reason);
        } catch (ValidationException $e) {
            return redirect()->back()->withErrors($e->errors());
        }

        return redirect()->route('akuntansi.jurnal.show', $reversal->id)
            ->with('success', 'Jurnal pembalik '.$reversal->journal_number.' telah diposting.');
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<int, array<string, mixed>>}
     */
    private function validatedPayload(): array
    {
        $validated = request()->validate([
            'transaction_date' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account_id' => ['required', 'integer', 'exists:chart_of_accounts,id'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.debit' => ['required', 'numeric', 'min:0'],
            'lines.*.credit' => ['required', 'numeric', 'min:0'],
        ]);

        $lines = array_map(fn (array $line) => [
            'account_id' => (int) $line['account_id'],
            'description' => $line['description'] ?? null,
            'debit' => (float) $line['debit'],
            'credit' => (float) $line['credit'],
        ], $validated['lines']);

        unset($validated['lines']);

        return [$validated, $lines];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function activeAccounts(): array
    {
        return ChartOfAccount::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'category', 'normal_balance'])
            ->map(fn (ChartOfAccount $account) => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'category' => $account->category,
                'category_label' => $account->categoryLabel(),
                'normal_balance' => $account->normal_balance,
            ])
            ->all();
    }
}
