<?php

namespace App\Http\Controllers\Shm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shm\StoreShmCashFlowRequest;
use App\Http\Requests\Shm\UpdateShmCashFlowRequest;
use App\Models\ChartOfAccount;
use App\Models\ShmCashTransaction;
use App\Services\Accounting\ShmCashFlowService;
use App\Support\AccountingContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ShmCashFlowController extends Controller
{
    public function __construct(
        protected ShmCashFlowService $cashFlowService
    ) {}

    /**
     * Display listing for Arus Kas Masuk (Inflow).
     */
    public function indexInflow(Request $request): Response
    {
        return $this->renderIndex($request, ShmCashTransaction::TYPE_INFLOW, 'Shm/Accounting/CashFlow/InflowIndex', 'Arus Kas Masuk');
    }

    /**
     * Store new draft for Arus Kas Masuk.
     */
    public function storeInflow(StoreShmCashFlowRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $this->cashFlowService->createDraft(ShmCashTransaction::TYPE_INFLOW, $data, $request->user()->id);

        return redirect()->route('shm.cash-inflow.index')->with('success', 'Draft Arus Kas Masuk berhasil dibuat.');
    }

    /**
     * Update draft for Arus Kas Masuk.
     */
    public function updateInflow(UpdateShmCashFlowRequest $request, ShmCashTransaction $transaction): RedirectResponse
    {
        $this->ensureType($transaction, ShmCashTransaction::TYPE_INFLOW);
        $data = $request->validated();
        $this->cashFlowService->updateDraft($transaction, $data, $request->user()->id);

        return redirect()->route('shm.cash-inflow.index')->with('success', 'Draft Arus Kas Masuk berhasil diperbarui.');
    }

    /**
     * Delete draft for Arus Kas Masuk.
     */
    public function destroyInflow(Request $request, ShmCashTransaction $transaction): RedirectResponse
    {
        $this->authorizeAction($request, 'accounting.shm.journals.create');
        $this->ensureType($transaction, ShmCashTransaction::TYPE_INFLOW);
        $this->cashFlowService->deleteDraft($transaction, $request->user()->id, $request->input('reason'));

        return redirect()->route('shm.cash-inflow.index')->with('success', 'Draft Arus Kas Masuk berhasil dihapus.');
    }

    /**
     * Post draft for Arus Kas Masuk.
     */
    public function postInflow(Request $request, ShmCashTransaction $transaction): RedirectResponse
    {
        $this->authorizeAction($request, 'accounting.shm.journals.post');
        $this->ensureType($transaction, ShmCashTransaction::TYPE_INFLOW);
        $this->cashFlowService->post($transaction, $request->user()->id);

        return redirect()->route('shm.cash-inflow.index')->with('success', 'Arus Kas Masuk berhasil diposting ke Neraca SHM.');
    }

    /**
     * Reverse posted for Arus Kas Masuk.
     */
    public function reverseInflow(Request $request, ShmCashTransaction $transaction): RedirectResponse
    {
        $this->authorizeAction($request, 'accounting.shm.journals.reverse');
        $this->ensureType($transaction, ShmCashTransaction::TYPE_INFLOW);

        $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $this->cashFlowService->reverse($transaction, $request->user()->id, $request->input('reason'));

        return redirect()->route('shm.cash-inflow.index')->with('success', 'Arus Kas Masuk berhasil dibatalkan/direversi.');
    }

    /**
     * Display listing for Arus Kas Keluar (Outflow).
     */
    public function indexOutflow(Request $request): Response
    {
        return $this->renderIndex($request, ShmCashTransaction::TYPE_OUTFLOW, 'Shm/Accounting/CashFlow/OutflowIndex', 'Arus Kas Keluar');
    }

    /**
     * Store new draft for Arus Kas Keluar.
     */
    public function storeOutflow(StoreShmCashFlowRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $this->cashFlowService->createDraft(ShmCashTransaction::TYPE_OUTFLOW, $data, $request->user()->id);

        return redirect()->route('shm.cash-outflow.index')->with('success', 'Draft Arus Kas Keluar berhasil dibuat.');
    }

    /**
     * Update draft for Arus Kas Keluar.
     */
    public function updateOutflow(UpdateShmCashFlowRequest $request, ShmCashTransaction $transaction): RedirectResponse
    {
        $this->ensureType($transaction, ShmCashTransaction::TYPE_OUTFLOW);
        $data = $request->validated();
        $this->cashFlowService->updateDraft($transaction, $data, $request->user()->id);

        return redirect()->route('shm.cash-outflow.index')->with('success', 'Draft Arus Kas Keluar berhasil diperbarui.');
    }

    /**
     * Delete draft for Arus Kas Keluar.
     */
    public function destroyOutflow(Request $request, ShmCashTransaction $transaction): RedirectResponse
    {
        $this->authorizeAction($request, 'accounting.shm.journals.create');
        $this->ensureType($transaction, ShmCashTransaction::TYPE_OUTFLOW);
        $this->cashFlowService->deleteDraft($transaction, $request->user()->id, $request->input('reason'));

        return redirect()->route('shm.cash-outflow.index')->with('success', 'Draft Arus Kas Keluar berhasil dihapus.');
    }

    /**
     * Post draft for Arus Kas Keluar.
     */
    public function postOutflow(Request $request, ShmCashTransaction $transaction): RedirectResponse
    {
        $this->authorizeAction($request, 'accounting.shm.journals.post');
        $this->ensureType($transaction, ShmCashTransaction::TYPE_OUTFLOW);
        $this->cashFlowService->post($transaction, $request->user()->id);

        return redirect()->route('shm.cash-outflow.index')->with('success', 'Arus Kas Keluar berhasil diposting ke Neraca SHM.');
    }

    /**
     * Reverse posted for Arus Kas Keluar.
     */
    public function reverseOutflow(Request $request, ShmCashTransaction $transaction): RedirectResponse
    {
        $this->authorizeAction($request, 'accounting.shm.journals.reverse');
        $this->ensureType($transaction, ShmCashTransaction::TYPE_OUTFLOW);

        $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $this->cashFlowService->reverse($transaction, $request->user()->id, $request->input('reason'));

        return redirect()->route('shm.cash-outflow.index')->with('success', 'Arus Kas Keluar berhasil dibatalkan/direversi.');
    }

    /**
     * Common render index logic.
     */
    protected function renderIndex(Request $request, string $type, string $component, string $title): Response
    {
        $query = ShmCashTransaction::query()
            ->with(['counterpartAccount', 'cashAccount', 'creator'])
            ->where('type', $type);

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('transaction_number', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhereHas('counterpartAccount', function ($sub) use ($search) {
                        $sub->where('name', 'like', "%{$search}%")
                            ->orWhere('code', 'like', "%{$search}%");
                    });
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('start_date')) {
            $query->whereDate('transaction_date', '>=', $request->input('start_date'));
        }

        if ($request->filled('end_date')) {
            $query->whereDate('transaction_date', '<=', $request->input('end_date'));
        }

        $transactions = $query->orderBy('created_at', 'desc')
            ->paginate(15)
            ->withQueryString()
            ->through(fn ($item) => [
                'id' => $item->id,
                'transaction_number' => $item->transaction_number,
                'type' => $item->type,
                'nama_perkiraan' => $item->counterpartAccount ? "{$item->counterpartAccount->code} - {$item->counterpartAccount->name}" : '-',
                'account_id' => $item->account_id,
                'cash_account_id' => $item->cash_account_id,
                'cash_account_name' => $item->cashAccount ? "{$item->cashAccount->code} - {$item->cashAccount->name}" : '-',
                'amount' => (float) $item->amount,
                'formatted_amount' => 'Rp '.number_format((float) $item->amount, 0, ',', '.'),
                'description' => $item->description,
                'status' => $item->status,
                'transaction_date' => $item->transaction_date->format('Y-m-d'),
                'formatted_transaction_date' => $item->transaction_date->format('d/m/Y'),
                'timestamp' => $item->created_at->format('d/m/Y H:i'),
                'posted_at' => $item->posted_at?->format('d/m/Y H:i'),
                'reversed_at' => $item->reversed_at?->format('d/m/Y H:i'),
                'reversal_reason' => $item->reversal_reason,
            ]);

        // Fetch eligible SHM accounts
        $cashAccounts = ChartOfAccount::query()
            ->where('accounting_context', AccountingContext::SHM)
            ->where('is_active', true)
            ->where('category', 'asset')
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'category']);

        $counterpartAccounts = ChartOfAccount::query()
            ->where('accounting_context', AccountingContext::SHM)
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'category']);

        return Inertia::render($component, [
            'title' => $title,
            'type' => $type,
            'transactions' => $transactions,
            'filters' => $request->only(['search', 'status', 'start_date', 'end_date']),
            'cashAccounts' => $cashAccounts,
            'counterpartAccounts' => $counterpartAccounts,
            'permissions' => [
                'canCreate' => $request->user()?->can('accounting.shm.journals.create') ?? false,
                'canPost' => $request->user()?->can('accounting.shm.journals.post') ?? false,
                'canReverse' => $request->user()?->can('accounting.shm.journals.reverse') ?? false,
            ],
        ]);
    }

    protected function ensureType(ShmCashTransaction $transaction, string $expectedType): void
    {
        if ($transaction->type !== $expectedType) {
            abort(404, 'Tipe transaksi tidak sesuai.');
        }
    }

    protected function authorizeAction(Request $request, string $permission): void
    {
        if (! $request->user()?->can($permission)) {
            abort(403, 'Aksi ini memerlukan kewenangan '.$permission.'.');
        }
    }
}
