<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreInstallmentPaymentRequest;
use App\Models\InstallmentPayment;
use App\Models\Loan;
use App\Services\InstallmentPaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class InstallmentController extends Controller
{
    public function __construct(private InstallmentPaymentService $paymentService) {}

    /**
     * Halaman Utama Modul Angsuran & Daftar Pinjaman Aktif/Tunggakan
     */
    public function index(Request $request): Response
    {
        $search = $request->input('search');
        $tab = $request->input('tab', 'active'); // active | overdue

        $loansQuery = Loan::with(['member', 'schedules', 'disbursement'])
            ->whereIn('status', ['ACTIVE', 'DISBURSED']);

        if ($tab === 'overdue') {
            $loansQuery->whereHas('schedules', function ($q) {
                $q->whereIn('status', ['OVERDUE', 'DUE']);
            });
        }

        $loans = $loansQuery
            ->when($search, fn ($q) => $q->where('loan_number', 'ilike', "%{$search}%")
                ->orWhereHas('member', fn ($m) => $m->where('full_name', 'ilike', "%{$search}%")))
            ->orderBy('created_at', 'desc')
            ->paginate(15)
            ->withQueryString();

        // Update schedule statuses for listed loans
        foreach ($loans->items() as $loanItem) {
            $this->paymentService->updateScheduleStatuses($loanItem);
        }

        return Inertia::render('Angsuran/Index', [
            'loans' => $loans,
            'filters' => [
                'search' => $search ?? '',
                'tab' => $tab,
            ],
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
        ]);
    }

    /**
     * Detail Jadwal Angsuran Pinjaman
     */
    public function showSchedule(Loan $loan): Response
    {
        $loan->load(['member', 'disbursement', 'schedules', 'payments.operator']);
        $this->paymentService->updateScheduleStatuses($loan);

        return Inertia::render('Angsuran/Schedule', [
            'loan' => $loan,
            'summary' => [
                'total_paid' => $loan->total_paid,
                'remaining_balance' => $loan->remaining_balance,
                'unpaid_schedules_count' => $loan->schedules->whereIn('status', ['UPCOMING', 'DUE', 'PARTIALLY_PAID', 'OVERDUE'])->count(),
                'overdue_schedules_count' => $loan->schedules->where('status', 'OVERDUE')->count(),
            ],
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
        ]);
    }

    /**
     * Form Pembayaran Angsuran
     */
    public function paymentForm(Loan $loan): Response
    {
        if (! in_array($loan->status, ['ACTIVE', 'DISBURSED'], true)) {
            abort(403, 'Pinjaman tidak aktif atau sudah lunas.');
        }

        $loan->load(['member', 'schedules']);
        $this->paymentService->updateScheduleStatuses($loan);

        // Rekomendasi tagihan angsuran terdekat
        $nextSchedule = $loan->schedules
            ->whereIn('status', ['UPCOMING', 'DUE', 'PARTIALLY_PAID', 'OVERDUE'])
            ->sortBy('installment_number')
            ->first();

        $suggestedAmount = $nextSchedule ? (float) $nextSchedule->remaining_amount : (float) $loan->remaining_balance;

        return Inertia::render('Angsuran/Pay', [
            'loan' => $loan,
            'next_schedule' => $nextSchedule,
            'suggested_amount' => $suggestedAmount,
            'today_date' => now()->format('Y-m-d'),
        ]);
    }

    /**
     * Proses Pembayaran Angsuran
     */
    public function processPayment(StoreInstallmentPaymentRequest $request, Loan $loan): RedirectResponse
    {
        try {
            $payment = $this->paymentService->pay($loan, $request->validated(), auth()->id());
        } catch (ValidationException $e) {
            return redirect()->back()->withErrors($e->errors())->withInput();
        }

        return redirect()
            ->route('angsuran.schedule', $loan->id)
            ->with('success', 'Pembayaran angsuran berhasil dicatat. Nomor transaksi: '.$payment->payment_number);
    }

    /**
     * Detail Transaksi Pembayaran Angsuran
     */
    public function showPayment(InstallmentPayment $payment): Response
    {
        $payment->load(['loan.member', 'schedule', 'operator']);

        return Inertia::render('Angsuran/ShowPayment', [
            'payment' => $payment,
        ]);
    }
}
