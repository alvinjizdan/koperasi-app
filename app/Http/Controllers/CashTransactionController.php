<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\InteractsWithSorting;
use App\Http\Requests\ReverseCashTransactionRequest;
use App\Http\Requests\StoreCashDepositRequest;
use App\Http\Requests\StoreCashExpenseRequest;
use App\Http\Requests\StoreCashIncomeRequest;
use App\Models\CashTransaction;
use App\Models\User;
use App\Services\CashierService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CashTransactionController extends Controller
{
    use InteractsWithSorting;

    public function __construct(private CashierService $cashierService) {}

    /**
     * Daftar transaksi kas dengan filter dan pagination server-side.
     */
    public function index(Request $request): Response
    {
        $filters = [
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
            'type' => $request->input('type', 'all'),
            'category' => $request->input('category', 'all'),
            'status' => $request->input('status', 'all'),
            'operator_id' => $request->input('operator_id'),
            'session_id' => $request->input('session_id'),
        ];

        $transactionsQuery = CashTransaction::query()
            ->with(['operator', 'source', 'session'])
            ->when($filters['date_from'], fn ($q, $date) => $q->whereDate('transaction_date', '>=', $date))
            ->when($filters['date_to'], fn ($q, $date) => $q->whereDate('transaction_date', '<=', $date))
            ->when($filters['type'] !== 'all', fn ($q) => $q->where('type', $filters['type']))
            ->when($filters['category'] !== 'all', fn ($q) => $q->where('category', $filters['category']))
            ->when($filters['status'] !== 'all', fn ($q) => $q->where('status', $filters['status']))
            ->when($filters['operator_id'], fn ($q, $operatorId) => $q->where('processed_by', $operatorId))
            ->when($filters['session_id'], fn ($q, $sessionId) => $q->where('cashier_session_id', $sessionId));

        $sortStatus = $this->applySorting($transactionsQuery, $request, [
            'transaction_number' => 'cash_transactions.transaction_number',
            'transaction_date' => 'cash_transactions.transaction_date',
            'type' => 'cash_transactions.type',
            'category' => 'cash_transactions.category',
            'amount' => 'cash_transactions.amount',
            'created_at' => 'cash_transactions.id',
        ], [['cash_transactions.transaction_date', 'desc'], ['cash_transactions.id', 'desc']]);

        $transactions = $transactionsQuery->paginate($this->resolvePerPage($request))->withQueryString();

        return Inertia::render('Kasir/Transaksi/Index', [
            'transactions' => [
                ...$transactions->toArray(),
                'data' => collect($transactions->items())->map->toPayloadArray()->all(),
            ],
            'sort' => $sortStatus,
            'filters' => [
                'date_from' => $filters['date_from'] ?? '',
                'date_to' => $filters['date_to'] ?? '',
                'type' => $filters['type'],
                'category' => $filters['category'],
                'status' => $filters['status'],
                'operator_id' => $filters['operator_id'] ?? '',
                'session_id' => $filters['session_id'] ?? '',
            ],
            'category_options' => $this->categoryOptions(),
            'operators' => $this->operatorOptions(),
            'active_session' => $this->activeSessionPayload((int) $request->user()->id),
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
        ]);
    }

    /**
     * Detail transaksi kas.
     */
    public function show(Request $request, CashTransaction $transaction): Response
    {
        $transaction->load(['operator', 'session.operator', 'source']);

        return Inertia::render('Kasir/Transaksi/Show', [
            'transaction' => [
                ...$transaction->toPayloadArray(),
                'reversed_at' => $transaction->reversed_at?->toDateTimeString(),
                'reversal_reason' => $transaction->reversal_reason,
                'can_be_corrected' => $transaction->isPosted() && $transaction->source_type === null,
            ],
            'active_session' => $this->activeSessionPayload((int) $request->user()->id),
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
        ]);
    }

    /**
     * Form penerimaan kas.
     */
    public function createIncome(Request $request): Response|RedirectResponse
    {
        $activeSession = $this->cashierService->activeSessionFor((int) $request->user()->id);

        if ($activeSession === null) {
            return $this->redirectToOpenSession();
        }

        return Inertia::render('Kasir/Transaksi/Income', [
            'today_date' => now()->format('Y-m-d'),
            'categories' => config('cashier.categories.income'),
            'active_session' => $this->activeSessionPayload((int) $request->user()->id),
        ]);
    }

    /**
     * Catat penerimaan kas.
     */
    public function storeIncome(StoreCashIncomeRequest $request): RedirectResponse
    {
        $activeSession = $this->cashierService->activeSessionFor((int) $request->user()->id);

        if ($activeSession === null) {
            return $this->redirectToOpenSession();
        }

        try {
            $transaction = $this->cashierService->recordIncome($activeSession, $request->validated(), (int) $request->user()->id);
        } catch (ValidationException $e) {
            return redirect()->back()->withErrors($e->errors())->withInput();
        }

        return redirect()
            ->route('kasir.transaksi.show', $transaction->id)
            ->with('success', 'Penerimaan kas '.$transaction->transaction_number.' berhasil dicatat.');
    }

    /**
     * Form pengeluaran kas.
     */
    public function createExpense(Request $request): Response|RedirectResponse
    {
        $activeSession = $this->cashierService->activeSessionFor((int) $request->user()->id);

        if ($activeSession === null) {
            return $this->redirectToOpenSession();
        }

        return Inertia::render('Kasir/Transaksi/Expense', [
            'today_date' => now()->format('Y-m-d'),
            'categories' => config('cashier.categories.expense'),
            'active_session' => $this->activeSessionPayload((int) $request->user()->id),
        ]);
    }

    /**
     * Catat pengeluaran kas.
     */
    public function storeExpense(StoreCashExpenseRequest $request): RedirectResponse
    {
        $activeSession = $this->cashierService->activeSessionFor((int) $request->user()->id);

        if ($activeSession === null) {
            return $this->redirectToOpenSession();
        }

        try {
            $transaction = $this->cashierService->recordExpense($activeSession, $request->validated(), (int) $request->user()->id);
        } catch (ValidationException $e) {
            return redirect()->back()->withErrors($e->errors())->withInput();
        }

        return redirect()
            ->route('kasir.transaksi.show', $transaction->id)
            ->with('success', 'Pengeluaran kas '.$transaction->transaction_number.' berhasil dicatat.');
    }

    /**
     * Form setoran kasir.
     */
    public function createDeposit(Request $request): Response|RedirectResponse
    {
        $activeSession = $this->cashierService->activeSessionFor((int) $request->user()->id);

        if ($activeSession === null) {
            return $this->redirectToOpenSession();
        }

        return Inertia::render('Kasir/Transaksi/Deposit', [
            'today_date' => now()->format('Y-m-d'),
            'deposit_category' => config('cashier.deposit.category'),
            'active_session' => $this->activeSessionPayload((int) $request->user()->id),
        ]);
    }

    /**
     * Catat setoran kasir.
     */
    public function storeDeposit(StoreCashDepositRequest $request): RedirectResponse
    {
        $activeSession = $this->cashierService->activeSessionFor((int) $request->user()->id);

        if ($activeSession === null) {
            return $this->redirectToOpenSession();
        }

        try {
            $transaction = $this->cashierService->recordDeposit($activeSession, $request->validated(), (int) $request->user()->id);
        } catch (ValidationException $e) {
            return redirect()->back()->withErrors($e->errors())->withInput();
        }

        return redirect()
            ->route('kasir.transaksi.show', $transaction->id)
            ->with('success', 'Setoran kasir '.$transaction->transaction_number.' berhasil dicatat.');
    }

    /**
     * Koreksi transaksi kas melalui reversal yang tercatat.
     */
    public function reverse(ReverseCashTransactionRequest $request, CashTransaction $transaction): RedirectResponse
    {
        try {
            $reversed = $this->cashierService->reverse($transaction, $request->validated()['reason'], (int) $request->user()->id);
        } catch (ValidationException $e) {
            return redirect()->back()->withErrors($e->errors());
        }

        return redirect()
            ->route('kasir.transaksi.show', $reversed->id)
            ->with('success', 'Transaksi '.$reversed->transaction_number.' dibatalkan melalui koreksi tercatat. Saldo kas sesi telah disesuaikan.');
    }

    private function redirectToOpenSession(): RedirectResponse
    {
        return redirect()
            ->route('kasir.index')
            ->with('error', 'Buka sesi kasir terlebih dahulu sebelum mencatat transaksi kas.');
    }

    /**
     * @return array<int, string>
     */
    private function categoryOptions(): array
    {
        return collect(config('cashier.categories.income'))
            ->merge(config('cashier.categories.expense'))
            ->push(config('cashier.deposit.category'))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function operatorOptions(): array
    {
        return User::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function activeSessionPayload(int $userId): ?array
    {
        $activeSession = $this->cashierService->activeSessionFor($userId);

        if ($activeSession === null) {
            return null;
        }

        return [
            ...$activeSession->load('operator')->toSummaryArray(),
            'totals' => $this->cashierService->totals($activeSession),
        ];
    }
}
