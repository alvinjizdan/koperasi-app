<?php

namespace App\Http\Controllers;

use App\Models\CashierSession;
use App\Models\CashTransaction;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CashSummaryController extends Controller
{
    /**
     * Ringkasan kas harian berdasarkan transaksi sah (POSTED).
     */
    public function index(Request $request): Response
    {
        $dateFrom = $request->input('date_from') ?: now()->format('Y-m-d');
        $dateTo = $request->input('date_to') ?: now()->format('Y-m-d');
        $operatorId = $request->input('operator_id');
        $status = $request->input('status', 'all');

        $baseQuery = fn () => CashierSession::query()
            ->whereDate('operational_date', '>=', $dateFrom)
            ->whereDate('operational_date', '<=', $dateTo)
            ->when($operatorId, fn ($q) => $q->where('opened_by', $operatorId))
            ->when($status !== 'all', fn ($q) => $q->where('status', $status));

        $sessions = $baseQuery()
            ->withTotals()
            ->with('operator')
            ->orderByDesc('operational_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $sessionIds = $baseQuery()->pluck('id');

        $postedSums = CashTransaction::query()
            ->whereIn('cashier_session_id', $sessionIds)
            ->where('status', CashTransaction::STATUS_POSTED)
            ->selectRaw('type, COALESCE(SUM(amount), 0) AS total')
            ->groupBy('type')
            ->pluck('total', 'type');

        $openingBalance = round((float) $baseQuery()->sum('opening_balance'), 2);
        $income = round((float) ($postedSums[CashTransaction::TYPE_INCOME] ?? 0), 2);
        $expense = round((float) ($postedSums[CashTransaction::TYPE_EXPENSE] ?? 0), 2);
        $deposit = round((float) ($postedSums[CashTransaction::TYPE_DEPOSIT] ?? 0), 2);

        return Inertia::render('Kasir/Ringkasan', [
            'rows' => [
                ...$sessions->toArray(),
                'data' => collect($sessions->items())->map->toSummaryArray()->all(),
            ],
            'summary' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'sessions_count' => $baseQuery()->count(),
                'open_sessions_count' => $baseQuery()->where('status', CashierSession::STATUS_OPEN)->count(),
                'opening_balance' => $openingBalance,
                'income_total' => $income,
                'expense_total' => $expense,
                'deposit_total' => $deposit,
                'system_balance' => round($openingBalance + $income - $expense - $deposit, 2),
                'difference_total' => round((float) $baseQuery()->where('status', CashierSession::STATUS_CLOSED)->sum('difference'), 2),
            ],
            'filters' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'operator_id' => $operatorId ?? '',
                'status' => $status,
            ],
            'operators' => User::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])
                ->all(),
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
        ]);
    }
}
