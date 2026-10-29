<?php

namespace App\Http\Controllers\Shm;

use App\Http\Controllers\Concerns\AuthorizesShmOwnership;
use App\Http\Controllers\Concerns\InteractsWithSorting;
use App\Http\Controllers\Controller;
use App\Http\Requests\Shm\StoreShmLoanPaymentRequest;
use App\Models\AuditLog;
use App\Models\ShmLoan;
use App\Models\ShmLoanPayment;
use App\Services\Shm\ShmLoanPaymentAllocationService;
use App\Services\Shm\ShmLoanPaymentService;
use App\Services\Shm\ShmRegularPaymentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Halaman Pembayaran Pinjaman SHM — transaksi (T86), alokasi (T87),
 * dan monitoring history/detail (T88).
 *
 * Domain SHM: hanya membaca/menulis `shm_loan_payments` yang berelasi ke
 * `shm_loans` — TIDAK PERNAH menyentuh `installment_payments`,
 * `installment_schedules`, `loans`, maupun `members` milik koperasi.
 *
 * Controller HANYA otorisasi + orkestrasi: logika finansial transaksi berada
 * di ShmLoanPaymentService (T86) dan alokasi di
 * ShmLoanPaymentAllocationService (T87). Sejak T88, index/show adalah
 * MONITORING READ-ONLY: search/filter/sort server-side, allocation status
 * derived dari persisted data (withSum), dan timeline audit — tanpa satu pun
 * mutasi finansial dari endpoint GET.
 *
 * Otorisasi:
 * - baca (index/show): `shm.view` (sama dengan seluruh halaman Pinjaman SHM)
 *   + scope kepemilikan via AuthorizesShmOwnership;
 * - tulis (store): `shm.payment.create` + scope kepemilikan pinjaman yang
 *   sama — permission terpisah dari `shm.view` mengikuti pola BUG-01.
 */
class ShmLoanPaymentController extends Controller
{
    use AuthorizesShmOwnership;
    use InteractsWithSorting;

    public function __construct(
        private readonly ShmLoanPaymentService $payments,
        private readonly ShmLoanPaymentAllocationService $allocation,
        private readonly ShmRegularPaymentService $regularPayments,
    ) {}

    /**
     * Daftar pembayaran (T88 — monitoring history).
     *
     * Query SELALU server-side: search (referensi / no. pinjaman / nasabah),
     * filter status & rentang tanggal & status alokasi, sorting whitelist,
     * pagination — React tidak pernah memfilter array finansial di browser.
     * Status alokasi derived dari persisted allocation rows (withSum), bukan
     * kolom status baru.
     */
    public function index(Request $request): Response
    {
        $filters = [
            'search' => $request->input('search'),
            'status' => $request->input('status', 'all'),
            'allocation' => $request->input('allocation', 'all'),
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
        ];

        $query = ShmLoanPayment::query()
            ->with([
                // shm_customer_id WAJIB ikut dipilih agar relasi customer terisi
                // (belongsTo membaca FK dari atribut yang sudah di-select).
                'loan:id,loan_number,status,disbursed_at,shm_loan_application_id,shm_customer_id',
                'loan.customer:id,customer_number,name',
                'creator:id,name',
            ])
            // Aggregate alokasi per baris (satu query, tanpa N+1).
            ->withSum('allocations as allocated_sum', 'total_amount')
            ->when($filters['search'], function (Builder $query, string $search): void {
                $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($search)).'%';

                $query->where(function (Builder $inner) use ($term): void {
                    $inner->where('payment_reference', 'like', $term)
                        ->orWhereHas('loan', fn (Builder $loan) => $loan
                            ->where('loan_number', 'like', $term)
                            ->orWhereHas('customer', fn (Builder $customer) => $customer
                                ->where('name', 'like', $term)
                                ->orWhere('customer_number', 'like', $term)));
                });
            })
            ->when(
                $filters['status'] !== 'all',
                fn (Builder $query) => $query->where('status', $filters['status'])
            )
            ->when(
                $filters['date_from'],
                fn (Builder $query, string $date) => $query->whereDate('payment_date', '>=', $date)
            )
            ->when(
                $filters['date_to'],
                fn (Builder $query, string $date) => $query->whereDate('payment_date', '<=', $date)
            )
            ->when(
                $filters['allocation'] !== 'all',
                fn (Builder $query) => $this->applyAllocationFilter($query, (string) $filters['allocation'])
            );

        // Scope kepemilikan di QUERY (server-side): tanpa shm.view_all hanya
        // pembayaran atas pinjaman hasil pengajuan milik sendiri.
        if (! $this->canViewAllShm()) {
            $query->whereHas(
                'loan.application',
                fn (Builder $application) => $application->where('marketing_id', $request->user()->id)
            );
        }

        $sortStatus = $this->applySorting($query, $request, [
            'payment_reference' => 'payment_reference',
            'payment_date' => 'payment_date',
            'amount' => 'amount',
            'status' => 'status',
            'created_at' => 'created_at',
        ], [['payment_date', 'desc'], ['id', 'desc']]);

        $payments = $query->paginate($this->resolvePerPage($request))
            ->withQueryString()
            ->through(fn (ShmLoanPayment $payment): array => $this->presentPayment($payment));

        return Inertia::render('Shm/Loans/Payments', [
            'payments' => $payments,
            'loans' => $this->payableLoans($request),
            'sort' => $sortStatus,
            'filters' => $filters,
            'today' => now()->format('Y-m-d'),
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
        ]);
    }

    /**
     * Catat SATU transaksi pembayaran.
     *
     * Guard pinjaman (ada/telah cair/aktif), idempotensi, referensi, dan audit
     * seluruhnya di service; controller hanya scope kepemilikan + flash.
     */
    public function store(StoreShmLoanPaymentRequest $request): RedirectResponse
    {
        $loan = ShmLoan::query()->findOrFail($request->validated('loan_id'));

        // Scope IDENTIK dengan halaman detail pinjaman — endpoint POST bukan
        // bypass visibility.
        $this->authorizeLoanRecordOwnership($request, $loan);

        $result = $this->regularPayments->pay(
            $loan,
            $request->validated(),
            (int) $request->user()->id,
        );

        $payment = $result['payment'];

        if ($result['was_replayed']) {
            return redirect()
                ->route('shm.loans.payments.index')
                ->with('success', sprintf('Pembayaran %s sudah tercatat sebelumnya.', $payment->payment_reference));
        }

        $message = sprintf(
            'Pembayaran %s berhasil dicatat: Rp %s dialokasikan ke angsuran.',
            $payment->payment_reference,
            number_format($result['allocated_amount'], 0, ',', '.'),
        );

        if ($result['excess_amount'] > 0.005) {
            $accountNumber = $result['savings_account']?->account_number ?? '-';
            $message .= sprintf(
                ' Lebih bayar Rp %s disetor ke Simpanan Pokok (%s).',
                number_format($result['excess_amount'], 0, ',', '.'),
                $accountNumber,
            );
        }

        if ($result['loan_closed']) {
            $message .= sprintf(' Seluruh kewajiban lunas — pinjaman %s ditutup otomatis.', $loan->loan_number);
        } else {
            $message .= sprintf(
                ' Sisa kewajiban pinjaman: Rp %s.',
                number_format($result['remaining_total_owed'], 0, ',', '.'),
            );
        }

        return redirect()
            ->route('shm.loans.payments.index')
            ->with('success', $message);
    }

    /**
     * Ticket 87 — Alokasikan SATU pembayaran ke angsuran pinjaman.
     *
     * Idempoten: panggilan ulang terhadap pembayaran yang sudah teralokasi
     * penuh tidak membuat alokasi/audit baru. Guard kelayakan (posted,
     * pinjaman aktif/tertcair) ada di service (server-side).
     */
    public function allocate(Request $request, ShmLoanPayment $payment): RedirectResponse
    {
        // Scope IDENTIK dengan halaman detail — endpoint POST bukan bypass.
        $this->authorizeLoanRecordOwnership($request, $payment->loan);

        $result = $this->allocation->allocate($payment);

        return redirect()
            ->route('shm.loans.payments.show', $payment)
            ->with(
                'success',
                $result['allocated']
                    ? sprintf(
                        'Alokasi %s selesai: Rp %s teralokasi, sisa belum teralokasi Rp %s.',
                        $payment->payment_reference,
                        number_format((float) $result['allocated_amount'], 0, ',', '.'),
                        number_format((float) $result['unallocated_amount'], 0, ',', '.'),
                    )
                    : sprintf(
                        'Pembayaran %s sudah teralokasi penuh — tidak ada perubahan.',
                        $payment->payment_reference,
                    ),
            );
    }

    /**
     * Detail satu transaksi pembayaran (T88 — monitoring read-only).
     *
     * Menampilkan pembayaran + pinjaman + nasabah + breakdown alokasi
     * (dengan jatuh tempo angsuran) + timeline audit (created/allocated).
     * TIDAK ada satu pun mutasi finansial dari endpoint GET ini.
     */
    public function show(Request $request, ShmLoanPayment $payment): Response
    {
        $payment->load([
            'loan.customer',
            'loan.product',
            'loan.application',
            'creator:id,name',
            // Alokasi + angsuran tujuan (termasuk due_date) untuk breakdown.
            'allocations.installment:id,installment_number,due_date,interest_amount,principal_amount,paid_interest_amount,paid_principal_amount,remaining_amount,status',
        ]);

        // Scope kepemilikan mengikuti pinjaman pemilik pembayaran.
        $this->authorizeLoanRecordOwnership($request, $payment->loan);

        return Inertia::render('Shm/Loans/PaymentShow', [
            'payment' => $this->presentPaymentDetail($payment),
            'timeline' => $this->paymentTimeline($payment),
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
        ]);
    }

    /**
     * Timeline audit pembayaran (T88) — event created (T86) dan allocated
     * (T87) yang SUDAH tercatat. Read-only: membuka halaman tidak pernah
     * membuat audit baru.
     *
     * @return array<int, array<string, mixed>>
     */
    private function paymentTimeline(ShmLoanPayment $payment): array
    {
        return AuditLog::query()
            ->where('object_type', ShmLoanPayment::class)
            ->where('object_id', $payment->id)
            ->whereIn('action', [
                'shm.loan.payment.created',
                'shm.loan.payment.allocated',
                'shm.loan.payment.applied',
            ])
            ->with('actor:id,name')
            ->orderBy('id')
            ->get()
            ->map(fn (AuditLog $log): array => [
                'id' => $log->id,
                'action' => $log->action,
                'description' => $log->description,
                'actor' => $log->actor?->name,
                'created_at' => $log->created_at,
            ])
            ->all();
    }

    /**
     * Filter status alokasi (T88) berdasarkan aggregate persisted allocation,
     * bukan kolom status baru. Perbandingan desimal dilakukan di database.
     *
     * - none:    tidak ada baris alokasi;
     * - partial: ada alokasi tetapi Σ < amount;
     * - full:    Σ alokasi ≥ amount.
     */
    private function applyAllocationFilter(Builder $query, string $filter): Builder
    {
        $sum = '(select coalesce(sum(total_amount), 0) from shm_loan_payment_allocations where shm_loan_payment_id = shm_loan_payments.id)';

        // Catatan: closure whereExists/whereNotExists menerima Query Builder
        // (bukan Eloquent Builder) — tanpa type hint agar tidak TypeError.
        return match ($filter) {
            'none' => $query->whereNotExists(function ($inner): void {
                $inner->selectRaw('1')
                    ->from('shm_loan_payment_allocations')
                    ->whereColumn('shm_loan_payment_id', 'shm_loan_payments.id');
            }),
            'partial' => $query->whereExists(function ($inner): void {
                $inner->selectRaw('1')
                    ->from('shm_loan_payment_allocations')
                    ->whereColumn('shm_loan_payment_id', 'shm_loan_payments.id');
            })->whereRaw("$sum < amount"),
            'full' => $query->whereRaw("$sum >= amount"),
            default => $query,
        };
    }

    /**
     * Pinjaman yang boleh dipilih pada form pembayaran: sudah dicairkan dan
     * masih berjalan, dengan scope kepemilikan yang sama.
     *
     * @return array<int, array<string, mixed>>
     */
    private function payableLoans(Request $request): array
    {
        return ShmLoan::query()
            ->where('status', ShmLoan::STATUS_ACTIVE)
            ->whereNotNull('disbursed_at')
            ->with('customer:id,customer_number,name')
            ->when(
                ! $this->canViewAllShm(),
                fn (Builder $loan) => $loan->whereHas(
                    'application',
                    fn (Builder $application) => $application->where('marketing_id', $request->user()->id)
                )
            )
            ->orderByDesc('id')
            ->get([
                'id',
                'loan_number',
                'principal',
                'status',
                'disbursed_at',
                'shm_loan_application_id',
                // Wajib untuk relasi customer (belongsTo membaca FK ini).
                'shm_customer_id',
            ])
            ->map(fn (ShmLoan $loan): array => [
                'id' => $loan->id,
                'loan_number' => $loan->loan_number,
                'principal' => $loan->principal,
                'status' => $loan->status,
                'disbursed_at' => $loan->disbursed_at?->toDateString(),
                'customer' => [
                    'name' => $loan->customer?->name,
                    'customer_number' => $loan->customer?->customer_number,
                ],
            ])
            ->all();
    }

    /**
     * Bentuk satu baris pembayaran untuk props daftar (T88).
     *
     * Allocation status DERIVED dari aggregate persisted allocation
     * (`allocated_sum` dari withSum di query index) vs amount — tidak ada
     * kolom status alokasi baru, tidak ada perhitungan floating-point di
     * React. Perbandingan memakai selisih epsilon desimal yang aman.
     *
     * @return array<string, mixed>
     */
    private function presentPayment(ShmLoanPayment $payment): array
    {
        $allocated = (float) ($payment->getAttribute('allocated_sum') ?? 0);
        $amount = (float) $payment->amount;
        $unallocated = $amount - $allocated;

        $allocationStatus = match (true) {
            $allocated <= 0 => 'none',
            // Epsilon 0.005 — batas representable desimal(18,2).
            $unallocated < 0.005 => 'full',
            default => 'partial',
        };

        return [
            'id' => $payment->id,
            'payment_reference' => $payment->payment_reference,
            'payment_date' => $payment->payment_date?->toDateString(),
            'amount' => $payment->amount,
            'payment_channel' => $payment->payment_channel,
            'status' => $payment->status,
            'notes' => $payment->notes,
            'created_at' => $payment->created_at,
            // Derived (bukan kolom tersimpan): aman terhadap perubahan alokasi.
            'allocation_status' => $allocationStatus,
            'allocated_amount' => number_format($allocated, 2, '.', ''),
            'unallocated_amount' => number_format(max(0, $unallocated), 2, '.', ''),
            'loan' => [
                'id' => $payment->loan?->id,
                'loan_number' => $payment->loan?->loan_number,
                'status' => $payment->loan?->status,
            ],
            'customer' => [
                'name' => $payment->loan?->customer?->name,
                'customer_number' => $payment->loan?->customer?->customer_number,
            ],
            'creator' => [
                'id' => $payment->creator?->id,
                'name' => $payment->creator?->name,
            ],
        ];
    }

    /**
     * Bentuk detail pembayaran. SELURUH nilai berasal dari record yang sudah
     * tersimpan — tidak ada perhitungan alokasi (Ticket 87).
     *
     * @return array<string, mixed>
     */
    private function presentPaymentDetail(ShmLoanPayment $payment): array
    {
        $loan = $payment->loan;

        return array_merge($this->presentPayment($payment), [
            'creator' => [
                'id' => $payment->creator?->id,
                'name' => $payment->creator?->name,
                'email' => $payment->creator?->email,
            ],
            // Breakdown alokasi (T87) — SELURUH nilai dari record tersimpan,
            // tanpa perhitungan React. Jatuh tempo angsuran termasuk (T88).
            'allocations' => $payment->allocations
                ->sortBy('id')
                ->values()
                ->map(fn ($allocation): array => [
                    'id' => $allocation->id,
                    'installment_number' => $allocation->installment?->installment_number,
                    'due_date' => $allocation->installment?->due_date?->toDateString(),
                    'penalty_amount' => $allocation->penalty_amount,
                    'interest_amount' => $allocation->interest_amount,
                    'principal_amount' => $allocation->principal_amount,
                    'total_amount' => $allocation->total_amount,
                    'created_at' => $allocation->created_at,
                ]),
            'allocation_summary' => [
                'allocated_amount' => $payment->allocatedAmount(),
                'unallocated_amount' => $payment->unallocatedAmount(),
                'is_fully_allocated' => $payment->isFullyAllocated(),
            ],
            'loan' => [
                'id' => $loan?->id,
                'loan_number' => $loan?->loan_number,
                'status' => $loan?->status,
                'principal' => $loan?->principal,
                'outstanding' => $loan?->outstanding(),
                'tenor_months' => $loan?->tenor_months,
                // Rate persisted (T82/T83) — TIDAK dihitung ulang (T88).
                'rate_percent' => $loan?->rate_percent,
                'rate_period' => $loan?->rate_period,
                'calculation_method' => $loan?->calculation_method,
                'first_due_date' => $loan?->first_due_date?->toDateString(),
                'disbursed_at' => $loan?->disbursed_at?->toDateString(),
                'product' => [
                    'name' => $loan?->product?->name,
                    'code' => $loan?->product?->code,
                ],
                'customer' => [
                    'id' => $loan?->customer?->id,
                    'customer_number' => $loan?->customer?->customer_number,
                    'name' => $loan?->customer?->name,
                    'phone' => $loan?->customer?->phone,
                ],
            ],
        ]);
    }
}
