<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\InteractsWithSorting;
use App\Http\Requests\ReverseInstallmentPaymentRequest;
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
    use InteractsWithSorting;

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

        $loansQuery->when($search, fn ($q) => $q->where('loan_number', 'ilike', "%{$search}%")
            ->orWhereHas('member', fn ($m) => $m->where('full_name', 'ilike', "%{$search}%")));

        $sortStatus = $this->applySorting($loansQuery, $request, [
            'loan_number' => 'loan_number',
            'amount' => 'amount',
            'remaining_balance' => 'remaining_balance',
            'created_at' => 'created_at',
        ], [['created_at', 'desc']]);

        $loans = $loansQuery->paginate($this->resolvePerPage($request))->withQueryString();

        // Update schedule statuses for listed loans
        foreach ($loans->items() as $loanItem) {
            $this->paymentService->updateScheduleStatuses($loanItem);
        }

        // `remaining_balance` adalah accessor (tanpa $appends) sehingga tidak
        // ikut ter-serialize oleh paginator model mentah; dikirim eksplisit
        // agar kolom "Sisa Kewajiban" menampilkan nilai backend (T104).
        $loans->getCollection()->transform(fn (Loan $loan) => array_merge($loan->toArray(), [
            'remaining_balance' => $loan->remaining_balance,
        ]));

        return Inertia::render('Angsuran/Index', [
            'loans' => $loans,
            'sort' => $sortStatus,
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

        // Ticket 24 (C.2): endpoint GET TIDAK memicu akrual denda — pembacaan
        // jadwal bebas efek samping finansial (refresh/navigasi tidak mengubah
        // kewajiban). Akrual hanya melalui jalur eksplisit:
        // `php artisan penalties:accrue` (lihat app/Console/Commands).
        // updateScheduleStatuses() di atas hanya merapikan label status
        // UPCOMING/DUE/OVERDUE berdasarkan tanggal — bukan mutasi finansial.

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

        // `remaining_balance` dan `total_owed` adalah accessor (tanpa $appends),
        // sehingga tidak ikut ter-serialize oleh model mentah; dikirim eksplisit
        // agar UI membandingkan nominal terhadap kewajiban hasil hitung backend
        // (`total_owed` = batas yang divalidasi InstallmentPaymentService).
        return Inertia::render('Angsuran/Pay', [
            'loan' => array_merge($loan->toArray(), [
                'remaining_balance' => $loan->remaining_balance,
                'total_owed' => $loan->total_owed,
            ]),
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
        $payment->load(['loan.member', 'schedule', 'operator', 'reversedOriginal', 'reversals']);

        return Inertia::render('Angsuran/ShowPayment', [
            'payment' => $payment,
        ]);
    }

    /**
     * Reversal pembayaran angsuran (Ticket 21).
     */
    public function reversePayment(ReverseInstallmentPaymentRequest $request, InstallmentPayment $payment): RedirectResponse
    {
        try {
            $reversal = $this->paymentService->reverse($payment, (int) auth()->id(), $request->validated('reason'));
        } catch (ValidationException $e) {
            return redirect()->back()->withErrors($e->errors())->withInput();
        }

        return redirect()
            ->route('angsuran.payment.show', $reversal->id)
            ->with('success', 'Reversal pembayaran berhasil diproses. Nomor transaksi: '.$reversal->payment_number);
    }
}
