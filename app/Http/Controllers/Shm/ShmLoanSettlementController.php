<?php

namespace App\Http\Controllers\Shm;

use App\Http\Controllers\Concerns\AuthorizesShmOwnership;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ShmLoan;
use App\Models\ShmLoanPayment;
use App\Models\ShmLoanSettlement;
use App\Services\Shm\SettlementContractIncomplete;
use App\Services\Shm\ShmLoanSettlementService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * T89B — Halaman & workflow Pelunasan / Settlement Pinjaman SHM (Model B).
 *
 * - index()          : daftar pinjaman layak pelunasan (active + cair + scope).
 * - show()           : PREVIEW read-only (GET) + status workflow settlement.
 * - store()          : PENGAJUAN (POST) → settlement pending + snapshot policy.
 * - approve/reject() : approval (pending → approved / rejected).
 * - recordPayment()  : tautkan Payment T86 (approved → payment_recorded).
 * - verifyPayment()  : verifikasi EKSPLISIT (payment_recorded → verified).
 * - complete()       : penyelesaikan → completed + pinjaman closed.
 *
 * Otorisasi: baca = `shm.view` + scope kepemilikan; seluruh tulisan =
 * `shm.settlement.create` (permission technical, role authority PENDING
 * BUSINESS CONFIRMATION) + scope kepemilikan yang SAMA — endpoint POST
 * bukan bypass. Marketing tidak memiliki permission tersebut.
 */
class ShmLoanSettlementController extends Controller
{
    use AuthorizesShmOwnership;

    public function __construct(private readonly ShmLoanSettlementService $settlements) {}

    /**
     * Daftar pinjaman yang layak diajukan pelunasannya.
     */
    public function index(Request $request): Response
    {
        $query = ShmLoan::query()
            ->where('status', ShmLoan::STATUS_ACTIVE)
            ->whereNotNull('disbursed_at')
            ->with('customer:id,customer_number,name')
            ->when(
                ! $this->canViewAllShm(),
                fn (Builder $loan) => $loan->whereHas(
                    'application',
                    fn (Builder $application) => $application->where('marketing_id', $request->user()->id),
                )
            )
            ->orderByDesc('id')
            ->get([
                'id', 'loan_number', 'principal', 'tenor_months', 'status',
                'disbursed_at', 'shm_loan_application_id', 'shm_customer_id',
            ]);

        return Inertia::render('Shm/Loans/SettlementIndex', [
            'loans' => $query->map(fn (ShmLoan $loan): array => [
                'id' => $loan->id,
                'loan_number' => $loan->loan_number,
                'principal' => $loan->principal,
                'tenor_months' => $loan->tenor_months,
                'disbursed_at' => $loan->disbursed_at?->toDateString(),
                'customer' => [
                    'name' => $loan->customer?->name,
                    'customer_number' => $loan->customer?->customer_number,
                ],
            ])->all(),
        ]);
    }

    /**
     * Preview + status workflow pelunasan — READ-ONLY (GET).
     */
    public function show(Request $request, ShmLoan $loan): Response
    {
        $this->authorizeLoanRecordOwnership($request, $loan);

        $loan->load(['customer', 'product', 'settlement.discountPolicy', 'settlement.verifier']);

        try {
            $preview = $this->settlements->preview($loan);
        } catch (ValidationException $exception) {
            // Settlement SUDAH COMPLETED: pinjaman memang baru saja ditutup
            // oleh penyelesaian — tampilkan HASIL pelunasan + timeline,
            // bukan halaman error. Preview tidak berlaku lagi (pinjaman
            // sudah closed), sehingga preview = null.
            if ($loan->settlement?->status === ShmLoanSettlement::STATUS_COMPLETED) {
                $preview = null;
            } else {
                return Inertia::render('Shm/Loans/Settlement', [
                    'loan' => null,
                    'error' => $this->firstError($exception),
                ]);
            }
        }

        return Inertia::render('Shm/Loans/Settlement', [
            'loan' => [
                'id' => $loan->id,
                'loan_number' => $loan->loan_number,
                'status' => $loan->status,
                'principal' => $loan->principal,
                'tenor_months' => $loan->tenor_months,
                'rate_percent' => $loan->rate_percent,
                'rate_period' => $loan->rate_period,
                'calculation_method' => $loan->calculation_method,
                'disbursed_at' => $loan->disbursed_at?->toDateString(),
                'product' => ['name' => $loan->product?->name],
                'customer' => [
                    'name' => $loan->customer?->name,
                    'customer_number' => $loan->customer?->customer_number,
                ],
            ],
            'preview' => $preview,
            'settlement' => $this->settlementPayload($loan->settlement),
            'payments' => ShmLoanPayment::query()
                ->where('shm_loan_id', $loan->id)
                ->where('status', ShmLoanPayment::STATUS_POSTED)
                ->orderByDesc('payment_date')
                ->orderByDesc('id')
                ->get(['id', 'payment_reference', 'payment_date', 'amount'])
                ->map(fn (ShmLoanPayment $payment): array => [
                    'id' => $payment->id,
                    'payment_reference' => $payment->payment_reference,
                    'payment_date' => $payment->payment_date?->toDateString(),
                    'amount' => $payment->amount,
                ])->all(),
            'timeline' => $this->settlementTimeline($loan),
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
        ]);
    }

    /**
     * Pengajuan pelunasan (POST) → settlement PENDING.
     */
    public function store(Request $request, ShmLoan $loan): RedirectResponse
    {
        $this->authorizeLoanRecordOwnership($request, $loan);

        $data = $request->validate([
            'penalty' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->run(
            fn () => $this->settlements->request($loan, $data, (int) $request->user()->id),
            $loan,
            'Pengajuan pelunasan berhasil dibuat dan menunggu approval.',
        );
    }

    /**
     * Approval settlement (POST): pending → approved.
     */
    public function approve(Request $request, ShmLoan $loan): RedirectResponse
    {
        $this->authorizeLoanRecordOwnership($request, $loan);

        return $this->run(
            fn () => $this->settlements->approve($loan, (int) $request->user()->id),
            $loan,
            'Pelunasan disetujui. Lanjutkan dengan pencatatan pembayaran pelunasan.',
        );
    }

    /**
     * Penolakan settlement (POST): pending → rejected.
     */
    public function reject(Request $request, ShmLoan $loan): RedirectResponse
    {
        $this->authorizeLoanRecordOwnership($request, $loan);

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->run(
            fn () => $this->settlements->reject($loan, $data['reason'] ?? null, (int) $request->user()->id),
            $loan,
            'Pelunasan ditolak.',
        );
    }

    /**
     * Tautkan pembayaran pelunasan (Payment T86): approved → payment_recorded.
     */
    public function recordPayment(Request $request, ShmLoan $loan): RedirectResponse
    {
        $this->authorizeLoanRecordOwnership($request, $loan);

        $data = $request->validate([
            'payment_id' => ['required', 'integer', 'exists:shm_loan_payments,id'],
        ]);

        return $this->run(
            fn () => $this->settlements->recordPayment($loan, (int) $data['payment_id'], (int) $request->user()->id),
            $loan,
            'Pembayaran ditautkan ke pelunasan — menunggu verifikasi.',
        );
    }

    /**
     * Verifikasi pembayaran pelunasan (POST): payment_recorded → verified.
     */
    public function verifyPayment(Request $request, ShmLoan $loan): RedirectResponse
    {
        $this->authorizeLoanRecordOwnership($request, $loan);

        return $this->run(
            fn () => $this->settlements->verifyPayment($loan, (int) $request->user()->id),
            $loan,
            'Pembayaran pelunasan terverifikasi.',
        );
    }

    /**
     * Penyelesaian pelunasan (POST): verified → completed + pinjaman closed.
     */
    public function complete(Request $request, ShmLoan $loan): RedirectResponse
    {
        $this->authorizeLoanRecordOwnership($request, $loan);

        return $this->run(
            fn () => $this->settlements->complete($loan, (int) $request->user()->id),
            $loan,
            'Pelunasan selesai dan pinjaman ditutup.',
        );
    }

    /**
     * Jalankan satu mutasi settlement: flash sukses, atau flash error
     * deterministik untuk penolakan bisnis (SettlementContractIncomplete)
     * dan guard validasi — tanpa asumsi hasil finansial.
     *
     * @param  callable(): ShmLoanSettlement  $action
     */
    private function run(callable $action, ShmLoan $loan, string $successMessage): RedirectResponse
    {
        try {
            $action();
        } catch (SettlementContractIncomplete $exception) {
            return redirect()
                ->route('shm.loans.pelunasan.show', $loan)
                ->with('error', $exception->getMessage());
        } catch (ValidationException $exception) {
            return redirect()
                ->route('shm.loans.pelunasan.show', $loan)
                ->with('error', $this->firstError($exception));
        }

        return redirect()
            ->route('shm.loans.pelunasan.show', $loan)
            ->with('success', $successMessage);
    }

    /**
     * Pesan error pertama dari ValidationException (lintas key).
     */
    private function firstError(ValidationException $exception): string
    {
        foreach ($exception->errors() as $messages) {
            foreach ($messages as $message) {
                return (string) $message;
            }
        }

        return 'Permintaan pelunasan tidak dapat diproses.';
    }

    /**
     * Payload settlement untuk halaman (workflow + snapshot discount).
     *
     * @return array<string, mixed>|null
     */
    private function settlementPayload(?ShmLoanSettlement $settlement): ?array
    {
        if ($settlement === null) {
            return null;
        }

        return [
            'id' => $settlement->id,
            'settlement_reference' => $settlement->settlement_reference,
            'settlement_date' => $settlement->settlement_date?->toDateString(),
            'status' => $settlement->status,
            'outstanding_principal' => $settlement->outstanding_principal,
            'outstanding_interest' => $settlement->outstanding_interest,
            'outstanding_penalty' => $settlement->outstanding_penalty,
            'unallocated_payment_amount' => $settlement->unallocated_payment_amount,
            'settlement_amount' => $settlement->settlement_amount,
            'overpayment_amount' => $settlement->overpayment_amount,
            'notes' => $settlement->notes,
            'discount' => [
                'eligible' => $settlement->discount_eligible,
                'applied' => $settlement->discount_applied,
                'policy_id' => $settlement->discount_policy_id,
                // Label DIBANGUN dari kolom snapshot — BUKAN dari relasi
                // policy live (yang bisa berubah setelah settlement dibuat;
                // AC6: snapshot immutable dan tampil konsisten).
                'policy_label' => $settlement->discount_percent !== null
                    ? sprintf(
                        'sisa %d bulan @ %s%%',
                        (int) $settlement->remaining_months,
                        rtrim(rtrim(number_format((float) $settlement->discount_percent, 2, '.', ''), '0'), '.'),
                    )
                    : null,
                'remaining_months' => $settlement->remaining_months,
                'percent' => $settlement->discount_percent !== null ? (string) $settlement->discount_percent : null,
                'base_amount' => $settlement->discount_base_amount,
                'amount' => $settlement->discount_amount,
                'interest_after_discount' => $settlement->interest_after_discount_amount,
            ],
            'payment' => $settlement->payment === null ? null : [
                'id' => $settlement->payment->id,
                'payment_reference' => $settlement->payment->payment_reference,
                'payment_date' => $settlement->payment->payment_date?->toDateString(),
                'amount' => $settlement->payment->amount,
                'recorded_at' => $settlement->payment_recorded_at,
                'verified_at' => $settlement->payment_verified_at,
                'verified_by' => $settlement->verifier?->name,
            ],
            'created_at' => $settlement->created_at,
        ];
    }

    /**
     * Timeline audit settlement (requested/approved/rejected/payment/
     * completed/closed). Read-only — membuka halaman tidak pernah membuat
     * audit baru.
     *
     * @return array<int, array<string, mixed>>
     */
    private function settlementTimeline(ShmLoan $loan): array
    {
        $settlement = $loan->settlement;

        $query = AuditLog::query()
            ->with('actor:id,name')
            ->orderBy('id')
            ->where(function ($builder) use ($settlement, $loan): void {
                $builder->where(function ($builder) use ($settlement): void {
                    $builder->where('object_type', ShmLoanSettlement::class);
                    if ($settlement !== null) {
                        $builder->where('object_id', $settlement->id);
                    } else {
                        $builder->whereRaw('1 = 0');
                    }
                })->orWhere(function ($builder) use ($loan): void {
                    $builder->where('object_type', ShmLoan::class)
                        ->where('object_id', $loan->id)
                        ->where('action', 'shm.loan.closed');
                });
            })
            ->whereIn('action', [
                'shm.loan.settlement.requested',
                'shm.loan.settlement.approved',
                'shm.loan.settlement.rejected',
                'shm.loan.settlement.payment_recorded',
                'shm.loan.settlement.payment_verified',
                'shm.loan.settled',
                'shm.loan.closed',
            ]);

        return $query->get()
            ->map(fn (AuditLog $log): array => [
                'id' => $log->id,
                'action' => $log->action,
                'description' => $log->description,
                'actor' => $log->actor?->name,
                'created_at' => $log->created_at,
            ])
            ->all();
    }
}
