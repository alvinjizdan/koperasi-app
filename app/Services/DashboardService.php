<?php

namespace App\Services;

use App\Models\CashierSession;
use App\Models\CashTransaction;
use App\Models\InstallmentPayment;
use App\Models\Loan;
use App\Models\LoanDisbursement;
use App\Models\Member;
use App\Models\SavingsAccount;
use App\Models\SavingsTransaction;
use App\Services\Reports\ReportPeriod;
use Illuminate\Support\Facades\DB;

/**
 * Agregasi dashboard operasional.
 *
 * Seluruh angka berasal langsung dari tabel modul sumber menggunakan agregasi SQL.
 * Posisi saat ini (saldo) dipisahkan dari metrik transaksi dalam periode agar
 * filter tanggal tidak pernah mengubah saldo berjalan.
 */
class DashboardService
{
    /**
     * Label status pinjaman sesuai nilai yang dipakai modul Pinjaman.
     *
     * @var array<string, string>
     */
    private const LOAN_STATUS_LABELS = [
        'PENDING' => 'Diajukan',
        'APPROVED' => 'Disetujui',
        'REJECTED' => 'Ditolak',
        'DISBURSED' => 'Dicairkan',
        'ACTIVE' => 'Aktif',
        'PAID_OFF' => 'Lunas',
        'CANCELLED' => 'Dibatalkan',
    ];

    /**
     * @return array<string, mixed>
     */
    public function overview(ReportPeriod $period): array
    {
        $cash = $this->cashMetrics();

        return [
            'members' => $this->memberMetrics($period),
            'savings' => $this->savingsMetrics($period),
            'loans' => $this->loanMetrics($period),
            'cash' => $cash,
            'activities' => $this->activities(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function memberMetrics(ReportPeriod $period): array
    {
        return [
            'total' => Member::count(),
            'active' => Member::where('status', 'active')->count(),
            'inactive' => Member::where('status', 'inactive')->count(),
            'new_in_period' => Member::whereBetween('joined_at', $period->dateRange())->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function savingsMetrics(ReportPeriod $period): array
    {
        $totalAccounts = SavingsAccount::count();
        $activeAccounts = SavingsAccount::where('status', 'active')->count();

        $periodPeriod = $period->dateRange();

        $deposits = (float) SavingsTransaction::query()
            ->where('status', 'completed')
            ->where('type', 'deposit')
            ->whereBetween('transaction_date', $periodPeriod)
            ->sum('amount');

        $withdrawals = (float) SavingsTransaction::query()
            ->where('status', 'completed')
            ->where('type', 'withdrawal')
            ->whereBetween('transaction_date', $periodPeriod)
            ->sum('amount');

        return [
            'total_accounts' => $totalAccounts,
            'active_accounts' => $activeAccounts,
            'total_balance' => round((float) SavingsAccount::where('status', 'active')->sum('balance'), 2),
            'has_accounts' => $totalAccounts > 0,
            'has_active_accounts' => $activeAccounts > 0,
            'period_deposits' => round($deposits, 2),
            'period_withdrawals' => round($withdrawals, 2),
            'period_net' => round($deposits - $withdrawals, 2),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function loanMetrics(ReportPeriod $period): array
    {
        $grouped = Loan::query()
            ->select('status', DB::raw('COUNT(*) AS total'), DB::raw('COALESCE(SUM(amount), 0) AS amount'))
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $totalLoans = Loan::count();

        $byStatus = collect(self::LOAN_STATUS_LABELS)
            ->map(fn (string $label, string $status) => [
                'status' => $status,
                'label' => $label,
                'count' => (int) ($grouped[$status]->total ?? 0),
                'amount' => round((float) ($grouped[$status]->amount ?? 0), 2),
            ])
            ->values()
            ->all();

        $disbursementCount = LoanDisbursement::count();
        $disbursedAmount = round((float) LoanDisbursement::sum('amount'), 2);

        // Sisa kewajiban mengikuti rumus resmi modul Pinjaman:
        // nominal dicairkan dikurangi total pembayaran angsuran yang berhasil.
        $paidTotal = round((float) InstallmentPayment::where('status', 'COMPLETED')->sum('amount'), 2);

        $periodRange = $period->dateRange();

        return [
            'total' => $totalLoans,
            'has_loans' => $totalLoans > 0,
            'by_status' => $byStatus,
            'active_count' => Loan::whereIn('status', ['ACTIVE', 'DISBURSED'])->count(),
            'submitted_amount' => round((float) Loan::sum('amount'), 2),
            'disbursed_amount' => $disbursedAmount,
            'has_disbursements' => $disbursementCount > 0,
            'disbursement_count' => $disbursementCount,
            'paid_total' => $paidTotal,
            'outstanding_amount' => round(max(0.0, $disbursedAmount - $paidTotal), 2),
            'period_submitted_count' => Loan::whereBetween('submission_date', $periodRange)->count(),
            'period_submitted_amount' => round((float) Loan::whereBetween('submission_date', $periodRange)->sum('amount'), 2),
            'period_approved_count' => Loan::whereNotNull('approved_at')->whereBetween('approved_at', $periodRange)->count(),
            'period_disbursed_count' => LoanDisbursement::whereBetween('disbursement_date', $periodRange)->count(),
            'period_disbursed_amount' => round((float) LoanDisbursement::whereBetween('disbursement_date', $periodRange)->sum('amount'), 2),
            'period_payments_count' => InstallmentPayment::where('status', 'COMPLETED')->whereBetween('payment_date', $periodRange)->count(),
            'period_payments_total' => round((float) InstallmentPayment::where('status', 'COMPLETED')->whereBetween('payment_date', $periodRange)->sum('amount'), 2),
        ];
    }

    /**
     * Metrik kas untuk tanggal operasional hari ini.
     *
     * @return array<string, mixed>
     */
    private function cashMetrics(): array
    {
        $operationalDate = ReportPeriod::todayDate();
        $sessions = CashierSession::query()->whereDate('operational_date', $operationalDate)->get();
        $sessionIds = $sessions->pluck('id');

        $posted = CashTransaction::query()
            ->whereIn('cashier_session_id', $sessionIds)
            ->where('status', CashTransaction::STATUS_POSTED)
            ->selectRaw('type, COALESCE(SUM(amount), 0) AS total')
            ->groupBy('type')
            ->pluck('total', 'type');

        $openingBalance = round((float) $sessions->sum('opening_balance'), 2);
        $income = round((float) ($posted[CashTransaction::TYPE_INCOME] ?? 0), 2);
        $expense = round((float) ($posted[CashTransaction::TYPE_EXPENSE] ?? 0), 2);
        $deposit = round((float) ($posted[CashTransaction::TYPE_DEPOSIT] ?? 0), 2);

        $closedSessions = $sessions->where('status', CashierSession::STATUS_CLOSED);
        $openSessions = $sessions->where('status', CashierSession::STATUS_OPEN);

        return [
            'operational_date' => $operationalDate,
            'sessions_count' => $sessions->count(),
            'open_sessions_count' => $openSessions->count(),
            'closed_sessions_count' => $closedSessions->count(),
            'has_sessions' => $sessions->isNotEmpty(),
            'opening_balance' => $openingBalance,
            'income_total' => $income,
            'expense_total' => $expense,
            'deposit_total' => $deposit,
            'closing_balance' => round($openingBalance + $income - $expense - $deposit, 2),
            'difference_total' => round((float) $closedSessions->sum('difference'), 2),
            'has_closed_session' => $closedSessions->isNotEmpty(),
            'status_label' => match (true) {
                $sessions->isEmpty() => 'Belum ada sesi kasir hari ini',
                $openSessions->isNotEmpty() => $openSessions->count().' sesi masih terbuka',
                default => 'Seluruh sesi hari ini sudah ditutup',
            },
        ];
    }

    /**
     * Aktivitas terbaru lintas modul.
     *
     * @return array<string, mixed>
     */
    private function activities(): array
    {
        $savings = SavingsTransaction::query()
            ->with(['savingsAccount.member:id,full_name,member_number'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(6)
            ->get()
            ->map(fn (SavingsTransaction $transaction) => [
                'kind' => 'savings',
                'title' => $transaction->type === 'deposit' ? 'Setoran simpanan' : 'Penarikan simpanan',
                'subtitle' => trim(($transaction->savingsAccount?->account_number ?? '-').' • '.($transaction->savingsAccount?->member?->full_name ?? '-'), ' •'),
                'amount' => round((float) $transaction->amount, 2),
                'increases' => $transaction->type === 'deposit',
                'at' => $transaction->created_at?->toDateTimeString(),
                'url' => $transaction->id ? route('simpanan-transaksi.show', $transaction->id) : null,
            ]);

        $payments = InstallmentPayment::query()
            ->with(['loan.member:id,full_name', 'loan:id,loan_number,member_id'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(6)
            ->get()
            ->map(fn (InstallmentPayment $payment) => [
                'kind' => 'installment',
                'title' => 'Pembayaran angsuran',
                'subtitle' => trim(($payment->loan?->loan_number ?? '-').' • '.($payment->loan?->member?->full_name ?? '-'), ' •'),
                'amount' => round((float) $payment->amount, 2),
                'increases' => true,
                'at' => $payment->created_at?->toDateTimeString(),
                'url' => route('angsuran.payment.show', $payment->id),
            ]);

        $cash = CashTransaction::query()
            ->with('session:id,session_number')
            ->orderByDesc('transacted_at')
            ->orderByDesc('id')
            ->limit(6)
            ->get()
            ->map(fn (CashTransaction $transaction) => [
                'kind' => 'cash',
                'title' => $transaction->type_label.($transaction->category ? ' — '.$transaction->category : ''),
                'subtitle' => $transaction->session?->session_number ?? 'Tanpa sesi',
                'amount' => round((float) $transaction->amount, 2),
                'increases' => $transaction->increases_cash,
                'at' => $transaction->transacted_at?->toDateTimeString(),
                'url' => route('kasir.transaksi.show', $transaction->id),
            ]);

        $feed = $savings
            ->concat($payments)
            ->concat($cash)
            ->filter(fn (array $row) => $row['at'] !== null)
            ->sortByDesc('at')
            ->take(10)
            ->values()
            ->all();

        $recentLoans = Loan::query()
            ->with('member:id,full_name')
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(fn (Loan $loan) => [
                'id' => $loan->id,
                'loan_number' => $loan->loan_number,
                'member_name' => $loan->member?->full_name,
                'amount' => round((float) $loan->amount, 2),
                'status' => $loan->status,
                'status_label' => self::LOAN_STATUS_LABELS[$loan->status] ?? $loan->status,
                'submission_date' => $loan->submission_date?->toDateString(),
                'url' => route('pinjaman.show', $loan->id),
            ])
            ->all();

        $recentMembers = Member::query()
            ->orderByDesc('joined_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(fn (Member $member) => [
                'id' => $member->id,
                'member_number' => $member->member_number,
                'full_name' => $member->full_name,
                'status' => $member->status,
                'joined_at' => $member->joined_at?->toDateString(),
                'url' => route('anggota.show', $member->id),
            ])
            ->all();

        return [
            'transactions' => $feed,
            'loans' => $recentLoans,
            'members' => $recentMembers,
            'payments' => $payments->take(5)->values()->all(),
            'has_activity' => $feed !== [],
        ];
    }
}
