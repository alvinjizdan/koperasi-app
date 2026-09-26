<?php

namespace App\Http\Controllers;

use App\Http\Requests\CloseCashierSessionRequest;
use App\Http\Requests\OpenCashierSessionRequest;
use App\Models\CashierSession;
use App\Models\CashTransaction;
use App\Models\User;
use App\Services\CashierService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CashierController extends Controller
{
    public function __construct(private CashierService $cashierService) {}

    /**
     * Dashboard kasir: status sesi aktif, aksi cepat, dan ringkasan hari ini.
     */
    public function index(Request $request): Response
    {
        $userId = (int) $request->user()->id;
        $activeSession = $this->cashierService->activeSessionFor($userId);
        $today = now()->format('Y-m-d');

        $todaySessions = CashierSession::query()
            ->withTotals()
            ->with('operator')
            ->whereDate('operational_date', $today)
            ->orderBy('opened_at')
            ->get();

        $todayRows = $todaySessions->map->toSummaryArray()->all();

        return Inertia::render('Kasir/Dashboard', [
            'active_session' => $activeSession !== null
                ? [...$activeSession->load('operator')->toSummaryArray(), 'totals' => $this->cashierService->totals($activeSession)]
                : null,
            'recent_transactions' => $activeSession !== null
                ? CashTransaction::query()
                    ->with(['operator', 'source', 'session'])
                    ->where('cashier_session_id', $activeSession->id)
                    ->orderByDesc('transacted_at')
                    ->limit(10)
                    ->get()
                    ->map->toPayloadArray()
                    ->all()
                : [],
            'today' => [
                'operational_date' => $today,
                'sessions_count' => count($todayRows),
                'open_sessions_count' => $todaySessions->where('status', CashierSession::STATUS_OPEN)->count(),
                'opening_balance' => round(array_sum(array_column($todayRows, 'opening_balance')), 2),
                'income_total' => round(array_sum(array_column($todayRows, 'income_total')), 2),
                'expense_total' => round(array_sum(array_column($todayRows, 'expense_total')), 2),
                'deposit_total' => round(array_sum(array_column($todayRows, 'deposit_total')), 2),
                'system_balance' => round(array_sum(array_column($todayRows, 'system_balance')), 2),
            ],
            'previous_session' => $this->previousClosedSession($userId),
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
        ]);
    }

    /**
     * Riwayat sesi kasir.
     */
    public function sessions(Request $request): Response
    {
        $filters = [
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
            'operator_id' => $request->input('operator_id'),
            'status' => $request->input('status', 'all'),
        ];

        $sessions = CashierSession::query()
            ->withTotals()
            ->with('operator')
            ->when($filters['date_from'], fn ($q, $date) => $q->whereDate('operational_date', '>=', $date))
            ->when($filters['date_to'], fn ($q, $date) => $q->whereDate('operational_date', '<=', $date))
            ->when($filters['operator_id'], fn ($q, $operatorId) => $q->where('opened_by', $operatorId))
            ->when($filters['status'] !== 'all', fn ($q) => $q->where('status', $filters['status']))
            ->orderByDesc('operational_date')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Kasir/Sesi/Index', [
            'sessions' => [
                ...$sessions->toArray(),
                'data' => collect($sessions->items())->map->toSummaryArray()->all(),
            ],
            'operators' => $this->operatorOptions(),
            'filters' => [
                'date_from' => $filters['date_from'] ?? '',
                'date_to' => $filters['date_to'] ?? '',
                'operator_id' => $filters['operator_id'] ?? '',
                'status' => $filters['status'],
            ],
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
        ]);
    }

    /**
     * Detail satu sesi kasir beserta transaksinya.
     */
    public function show(CashierSession $session): Response
    {
        $session->load('operator', 'closer');

        $transactions = CashTransaction::query()
            ->with(['operator', 'source', 'session'])
            ->where('cashier_session_id', $session->id)
            ->orderByDesc('transacted_at')
            ->paginate(20, ['*'], 'transaksi_page')
            ->withQueryString();

        return Inertia::render('Kasir/Sesi/Show', [
            'session' => $session->toSummaryArray(),
            'totals' => $this->cashierService->totals($session),
            'transactions' => [
                ...$transactions->toArray(),
                'data' => collect($transactions->items())->map->toPayloadArray()->all(),
            ],
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
        ]);
    }

    /**
     * Form pembukaan sesi kasir.
     */
    public function createOpen(Request $request): Response|RedirectResponse
    {
        $userId = (int) $request->user()->id;
        $activeSession = $this->cashierService->activeSessionFor($userId);

        if ($activeSession !== null) {
            return redirect()
                ->route('kasir.sesi.show', $activeSession->id)
                ->with('error', 'Anda masih memiliki sesi kasir yang terbuka: '.$activeSession->session_number.'.');
        }

        return Inertia::render('Kasir/Sesi/Create', [
            'today_date' => now()->format('Y-m-d'),
            'previous_session' => $this->previousClosedSession($userId),
        ]);
    }

    /**
     * Buka sesi kasir.
     */
    public function storeOpen(OpenCashierSessionRequest $request): RedirectResponse
    {
        try {
            $session = $this->cashierService->openSession($request->validated(), (int) $request->user()->id);
        } catch (ValidationException $e) {
            return redirect()->back()->withErrors($e->errors())->withInput();
        }

        return redirect()
            ->route('kasir.index')
            ->with('success', 'Sesi kasir '.$session->session_number.' berhasil dibuka dengan saldo awal Rp '.number_format((float) $session->opening_balance, 0, ',', '.').'.');
    }

    /**
     * Form penutupan sesi kasir.
     */
    public function createClose(Request $request, CashierSession $session): Response|RedirectResponse
    {
        if (! $session->isOpen()) {
            return redirect()
                ->route('kasir.sesi.show', $session->id)
                ->with('error', 'Sesi kasir '.$session->session_number.' sudah ditutup.');
        }

        $session->load('operator');

        return Inertia::render('Kasir/Sesi/Close', [
            'session' => $session->toSummaryArray(),
            'totals' => $this->cashierService->totals($session),
            'transactions_count' => $session->transactions()->where('status', CashTransaction::STATUS_POSTED)->count(),
        ]);
    }

    /**
     * Tutup sesi kasir dan hitung selisih kas.
     */
    public function storeClose(CloseCashierSessionRequest $request, CashierSession $session): RedirectResponse
    {
        try {
            $closed = $this->cashierService->closeSession($session, $request->validated(), (int) $request->user()->id);
        } catch (ValidationException $e) {
            return redirect()->back()->withErrors($e->errors())->withInput();
        }

        $difference = round((float) $closed->difference, 2);
        $message = 'Sesi kasir '.$closed->session_number.' ditutup. Saldo akhir sistem Rp '.number_format((float) $closed->system_closing_balance, 0, ',', '.').'.';

        if ($difference !== 0.0) {
            $message .= ' Selisih kas: Rp '.number_format($difference, 0, ',', '.').'.';
        }

        return redirect()
            ->route('kasir.sesi.show', $closed->id)
            ->with($difference === 0.0 ? 'success' : 'error', $message);
    }

    /**
     * Referensi sesi tertutup terakhir milik operator (tanpa menetapkan saldo awal otomatis).
     *
     * @return array<string, mixed>|null
     */
    private function previousClosedSession(int $userId): ?array
    {
        $previous = CashierSession::query()
            ->closed()
            ->where('opened_by', $userId)
            ->orderByDesc('closed_at')
            ->first();

        if ($previous === null) {
            return null;
        }

        return [
            'session_number' => $previous->session_number,
            'operational_date' => $previous->operational_date?->format('Y-m-d'),
            'physical_closing_balance' => round((float) $previous->physical_closing_balance, 2),
            'difference' => round((float) $previous->difference, 2),
            'closed_at' => $previous->closed_at?->toDateTimeString(),
        ];
    }

    /**
     * Pilihan operator untuk filter.
     *
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
}
