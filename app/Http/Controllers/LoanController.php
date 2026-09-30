<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\InteractsWithSorting;
use App\Http\Requests\ApproveLoanRequest;
use App\Http\Requests\DisburseLoanRequest;
use App\Http\Requests\RejectLoanRequest;
use App\Http\Requests\StoreLoanRequest;
use App\Models\Loan;
use App\Models\Member;
use App\Services\LoanService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class LoanController extends Controller
{
    use InteractsWithSorting;

    public function __construct(private LoanService $loanService) {}

    /**
     * Daftar pengajuan & status pinjaman.
     */
    public function index(Request $request): Response
    {
        $search = $request->input('search');
        $status = $request->input('status', 'all');

        $query = Loan::with(['member', 'creator'])
            ->when($search, fn ($q) => $q->where('loan_number', 'ilike', "%{$search}%")
                ->orWhereHas('member', fn ($m) => $m->where('full_name', 'ilike', "%{$search}%")))
            ->when($status !== 'all', fn ($q) => $q->where('status', $status));

        $sortStatus = $this->applySorting($query, $request, [
            'loan_number' => 'loan_number',
            'amount' => 'amount',
            'tenor_months' => 'tenor_months',
            'submission_date' => 'submission_date',
            'status' => 'status',
            'created_at' => 'created_at',
        ], [['created_at', 'desc']]);

        $loans = $query->paginate($this->resolvePerPage($request))->withQueryString();

        return Inertia::render('Pinjaman/Index', [
            'loans' => $loans,
            'sort' => $sortStatus,
            'filters' => [
                'search' => $search ?? '',
                'status' => $status,
            ],
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
        ]);
    }

    /**
     * Form pengajuan pinjaman baru.
     */
    public function create(): Response
    {
        return Inertia::render('Pinjaman/Create', [
            'members' => Member::where('status', 'active')->orderBy('full_name')->get(['id', 'full_name', 'member_number']),
            'today_date' => now()->format('Y-m-d'),
        ]);
    }

    /**
     * Simpan pengajuan pinjaman.
     */
    public function store(StoreLoanRequest $request): RedirectResponse
    {
        try {
            $loan = $this->loanService->submit($request->validated(), auth()->id());
        } catch (ValidationException $e) {
            return redirect()->back()->withErrors($e->errors())->withInput();
        }

        return redirect()
            ->route('pinjaman.show', $loan->id)
            ->with('success', 'Pengajuan pinjaman '.$loan->loan_number.' berhasil dicatat.');
    }

    /**
     * Detail pinjaman.
     */
    public function show(Loan $loan): Response
    {
        $loan->load([
            'member',
            'creator',
            'approver',
            'rejector',
            'disburser',
            'disbursement.operator',
            'schedules',
            'payments.operator',
        ]);

        return Inertia::render('Pinjaman/Show', [
            'loan' => $loan,
            'summary' => [
                'total_paid' => $loan->total_paid,
                'remaining_balance' => $loan->remaining_balance,
            ],
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
        ]);
    }

    /**
     * Setujui pengajuan pinjaman.
     */
    public function approve(ApproveLoanRequest $request, Loan $loan): RedirectResponse
    {
        try {
            $this->loanService->approve($loan, auth()->id());
        } catch (ValidationException $e) {
            return redirect()->back()->withErrors($e->errors());
        }

        return redirect()
            ->route('pinjaman.show', $loan->id)
            ->with('success', 'Pinjaman '.$loan->loan_number.' berhasil disetujui.');
    }

    /**
     * Tolak pengajuan pinjaman.
     */
    public function reject(RejectLoanRequest $request, Loan $loan): RedirectResponse
    {
        try {
            $this->loanService->reject($loan, $request->validated()['rejection_reason'], auth()->id());
        } catch (ValidationException $e) {
            return redirect()->back()->withErrors($e->errors());
        }

        return redirect()
            ->route('pinjaman.show', $loan->id)
            ->with('success', 'Pinjaman '.$loan->loan_number.' ditolak.');
    }

    /**
     * Form pencairan pinjaman.
     */
    public function disburseForm(Loan $loan): Response
    {
        // Snapshot metode & suku bunga untuk ditampilkan (read-only) pada form
        // pencairan; nilai berasal dari pinjaman atau default provisional.
        $loan->interest_method = $loan->interest_method ?? config('business-rules.loan.default_interest_method');
        $loan->interest_rate_annual = $loan->interest_rate_annual ?? config('business-rules.loan.default_annual_rate');
        if ($loan->status !== 'APPROVED') {
            abort(403, 'Pinjaman belum disetujui.');
        }

        $loan->load('member');

        return Inertia::render('Pinjaman/Disburse', [
            'loan' => $loan,
            'today_date' => now()->format('Y-m-d'),
            'default_first_due_date' => now()->addMonth()->format('Y-m-d'),
        ]);
    }

    /**
     * Proses pencairan pinjaman.
     */
    public function disburse(DisburseLoanRequest $request, Loan $loan): RedirectResponse
    {
        try {
            $disbursement = $this->loanService->disburse($loan, $request->validated(), auth()->id());
        } catch (ValidationException $e) {
            return redirect()->back()->withErrors($e->errors())->withInput();
        }

        return redirect()
            ->route('pinjaman.show', $loan->id)
            ->with('success', 'Pinjaman '.$loan->loan_number.' telah dicairkan dengan nomor pencairan '.$disbursement->disbursement_number.'.');
    }
}
