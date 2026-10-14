<?php

namespace App\Http\Controllers\Shm;

use App\Http\Controllers\Concerns\AuthorizesShmOwnership;
use App\Http\Controllers\Concerns\InteractsWithSorting;
use App\Http\Controllers\Controller;
use App\Http\Requests\Shm\StoreShmCollectionCaseTransitionRequest;
use App\Models\AuditLog;
use App\Models\ShmCollectionActivity;
use App\Models\ShmCollectionCase;
use App\Models\ShmLoan;
use App\Models\ShmLoanApplication;
use App\Models\ShmLoanInstallment;
use App\Models\ShmLoanPayment;
use App\Models\ShmLoanPaymentAllocation;
use App\Models\ShmLoanProduct;
use App\Services\Shm\ShmCollectionCaseService;
use App\Services\Shm\ShmCollectionQueryService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Ticket 90 — Collection monitoring & work queue (READ-ONLY).
 *
 * Halaman kerja operator collection: daftar pinjaman dengan tunggakan
 * aktual + detail monitoring satu pinjaman. SELURUH route di controller ini
 * GET — tidak ada POST/PUT/PATCH/DELETE sama sekali: tidak ada pembayaran,
 * perubahan angsuran/pinjaman/nasabah, settlement, maupun perubahan status
 * yang dapat diluncurkan dari halaman Collection.
 *
 * Kelayakan, agregat, dan ringkasan tunggakan ada di
 * ShmCollectionQueryService (sumber tunggal predikat). Controller hanya:
 * otorisasi (permission route `shm.view` + ownership identik modul pinjaman),
 * parsing filter, sorting whitelist, pagination, dan presentasi props.
 *
 * Business rule yang masih PENDING (penalty, collector, cadence, escalation,
 * notification) TIDAK direpresentasikan sebagai fitur apa pun di sini —
 * tidak ada stage/score/prioritas yang dikarang. Ticket 93 menambahkan
 * pembacaan riwayat aktivitas Collection (case aktif + flag pemilik) pada
 * payload detail; pencatatannya sendiri ada di
 * ShmCollectionActivityController (route terpisah, otorisasi di domain).
 *
 * Ticket 94 menambahkan SATU aksi workflow: transisi status Collection Case
 * (POST) — seluruh logika graph transisi, otorisasi pemilik, resolved_at, dan
 * audit tetap di ShmCollectionCaseService (controller hanya meneruskan
 * `status` dari session aktor). Detail PTP (amount/tanggal/fulfillment)
 * sengaja TIDAK ada — PROMISE_TO_PAY murni status workflow.
 *
 * Ticket 95 menambahkan presentasi progres pembayaran & kesiapan resolusi
 * (angka SEMUA server-derived dari agregat ShmCollectionQueryService) serta
 * guard finansial resolusi di service — tidak ada perhitungan finansial di
 * frontend, tidak ada resolusi otomatis, dan tidak ada pembuatan pembayaran
 * dari halaman Collection.
 */
class ShmCollectionController extends Controller
{
    use AuthorizesShmOwnership;
    use InteractsWithSorting;

    public function __construct(
        private readonly ShmCollectionQueryService $collections,
        private readonly ShmCollectionCaseService $cases,
    ) {}

    /**
     * Daftar work queue Collection (server-side: search/filter/sort/paging).
     *
     * Default urutan: tunggakan TERAWAL lebih dulu (oldest overdue first) —
     * monitoring murni, tanpa priority score karena aturannya belum ada.
     */
    public function index(Request $request): Response
    {
        $filters = [
            'search' => $request->filled('search')
                ? trim((string) $request->input('search'))
                : null,
            'product_id' => $request->filled('product_id')
                ? (int) $request->input('product_id')
                : null,
            'min_days_overdue' => $this->daysOverdueFilter($request->input('min_days_overdue')),
            'max_days_overdue' => $this->daysOverdueFilter($request->input('max_days_overdue')),
        ];

        $query = $this->collections->candidates($filters);

        // Ownership identik dengan modul pinjaman SHM (server-side, bukan
        // sekadar menyembunyikan baris di UI): tanpa `shm.view_all` pengguna
        // hanya melihat pinjaman hasil pengajuan miliknya sendiri.
        if (! $this->canViewAllShm()) {
            $query->ownedBy((int) $request->user()->id);
        }

        $sortStatus = $this->applySorting($query, $request, [
            'loan_number' => 'shm_loans.loan_number',
            'principal' => 'shm_loans.principal',
            'disbursed_at' => 'shm_loans.disbursed_at',
            'earliest_overdue_due_date' => 'earliest_overdue_due_date',
            'overdue_installments_count' => 'overdue_installments_count',
            'outstanding_principal' => 'outstanding_principal_sum',
            'outstanding_interest' => 'outstanding_interest_sum',
        ], [['earliest_overdue_due_date', 'asc']]);

        $loans = $query->paginate($this->resolvePerPage($request))
            ->withQueryString()
            ->through(fn (ShmLoan $loan): array => $this->presentCollectionRow($loan));

        return Inertia::render('Shm/Collection/Index', [
            'loans' => $loans,
            'sort' => $sortStatus,
            'filters' => [
                'search' => $filters['search'] ?? '',
                'product_id' => $filters['product_id'],
                'min_days_overdue' => $filters['min_days_overdue'],
                'max_days_overdue' => $filters['max_days_overdue'],
            ],
            'products' => ShmLoanProduct::query()
                ->orderBy('name')
                ->get(['id', 'code', 'name']),
            'today' => today()->toDateString(),
        ]);
    }

    /**
     * Detail monitoring satu pinjaman tunggakan: nasabah, pinjaman,
     * ringkasan tunggakan, jadwal angsuran (presentation state derived),
     * riwayat pembayaran, progres pembayaran overdue (Ticket 95), dan
     * timeline audit yang SUDAH ada.
     *
     * Read-only: method ini tidak pernah menulis data maupun audit.
     */
    public function show(Request $request, ShmLoan $loan): Response
    {
        // Ownership DULU — kelayakan Collection tidak pernah membocorkan
        // keberadaan pinjaman milik marketing lain.
        $this->authorizeLoanRecordOwnership($request, $loan);

        // Case Collection TERBARU pinjaman (bila ada) + riwayat aktivitas
        // terurut — dibaca pada request YANG SAMA lewat eager load (tanpa N+1).
        // Case aktif selalu lebih baru dari case RESOLVED sebelumnya (T92:
        // hanya satu active case; case baru dibuat setelah yang lama RESOLVED),
        // sehingga case terbaru = case aktif bila ada; jika semua sudah
        // RESOLVED, case RESOLVED terakhir tetap tampil sebagai status
        // TERMINAL (Ticket 94: status terlihat, tanpa aksi transisi).
        $latestCase = ShmCollectionCase::query()
            ->where('shm_loan_id', $loan->getKey())
            ->latest('id')
            ->with(['activities' => fn ($activities) => $activities->latestFirst()->with('actor:id,name')])
            ->first();

        // Halaman detail terbuka untuk kandidat Collection ATAU pinjaman yang
        // memiliki Collection Case (Ticket 95). Setelah tunggakan terbayar
        // penuh pinjaman keluar dari kelayakan T90 (dan otomatis dari daftar
        // kerja), tetapi PEMILIK case harus tetap bisa membuka halaman untuk
        // melihat kesiapan resolusi dan menyelesaikan case; case RESOLVED
        // tetap terlihat sebagai status terminal. Predikat kelayakan daftar
        // T90 (candidates/isCandidate) TIDAK diubah — pinjaman tanpa case dan
        // tanpa tunggakan tetap 404.
        abort_unless(
            $this->collections->isCandidate($loan) || $latestCase !== null,
            404,
            'Pinjaman ini tidak memiliki tunggakan pada Collection.'
        );

        // Eager loading sekali jalan: jadwal (terurut), riwayat pembayaran
        // + alokasinya, dan relasi presentasi — tanpa N+1.
        $loan->load([
            'customer:id,customer_number,name,nik,phone,address,is_active,member_id',
            'customer.member:id,full_name,member_number',
            'product:id,code,name',
            'installments' => fn ($installments) => $installments->orderBy('installment_number'),
            'payments.allocations',
        ]);

        // Progres pembayaran overdue: SATU agregat SQL dipakai bersama oleh
        // ringkasan pembayaran (detail) dan kesiapan resolusi (case) sehingga
        // angka yang tampil = angka yang dijaga backend.
        $paymentProgress = $this->collections->overdueObligationProgress($loan);

        return Inertia::render('Shm/Collection/Show', [
            'collection' => $this->presentCollectionDetail($loan, $paymentProgress),
            'collection_case' => $this->presentCollectionCase($latestCase, $request, $paymentProgress),
            'timeline' => $this->collectionTimeline($loan),
            'today' => today()->toDateString(),
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
        ]);
    }

    /**
     * Transisi status workflow Collection Case (Ticket 94/95).
     *
     * Endpoint mutasi SATU-satunya untuk workflow lifecycle. Controller hanya
     * meneruskan `status` target dari Form Request — graph transisi valid,
     * otorisasi pemilik (tanpa fallback admin/shm.view_all), guard finansial
     * resolusi (remaining overdue = 0, Ticket 95), penetapan `resolved_at`,
     * dan audit `shm.collection.case.status_changed` semuanya mutlak di
     * `ShmCollectionCaseService::transition()` (transaksi tunggal).
     * Aktor = user session; identitas/pemilik/pinjaman/resolved_at/readiness
     * TIDAK pernah diterima dari frontend. Tidak ada detail PTP yang diminta.
     */
    public function transition(StoreShmCollectionCaseTransitionRequest $request, ShmCollectionCase $case): RedirectResponse
    {
        $toStatus = (string) $request->validated('status');

        $this->cases->transition($case, $toStatus, (int) $request->user()->id);

        return redirect()
            ->route('shm.collection.show', $case->shm_loan_id)
            ->with('success', sprintf('Status Collection Case berhasil diubah ke %s.', $toStatus));
    }

    /**
     * Case Collection terbaru pinjaman (bila ada) + riwayat aktivitas
     * (Ticket 93/94).
     *
     * `can_record` dihitung di server: pengguna saat ini harus PEMILIK case —
     * `shm.view_all`/admin TIDAK otomatis boleh mencatat. Aktivitas sudah
     * terurut `latestFirst` (activity_at desc, id desc) dari eager load.
     *
     * `allowed_transitions` (Ticket 94) = daftar target valid dari status
     * saat ini menurut `ShmCollectionCase::ALLOWED_TRANSITIONS` — DIHITUNG
     * server dan KOSONG untuk non-pemilik, sehingga React tidak menghitung
     * ulang logika transisi dan tidak pernah menawarkan aksi ke non-owner.
     *
     * Kesiapan resolusi (Ticket 95) — semuanya server-derived dari agregat
     * yang sama dengan guard backend:
     * - `remaining_overdue` = sisa tunggakan overdue (angka finansial);
     * - `resolution_ready` = case BELUM RESOLVED dan remaining = 0;
     * - `can_resolve` = pemilik + belum RESOLVED + ready — SATU-satunya
     *   sumber tampilan tombol Selesaikan di frontend.
     *
     * @param  array{overdue_principal: string, overdue_interest: string, overdue_total: string, allocated_principal: string, allocated_interest: string, allocated_total: string, remaining_overdue: string}  $paymentProgress
     * @return array<string, mixed>|null
     */
    private function presentCollectionCase(?ShmCollectionCase $case, Request $request, array $paymentProgress): ?array
    {
        if ($case === null) {
            return null;
        }

        $isOwner = (int) $request->user()?->id === (int) $case->marketing_id;
        $remainingOverdue = (float) $paymentProgress['remaining_overdue'];
        $isResolved = $case->status === ShmCollectionCase::STATUS_RESOLVED;
        $resolutionReady = ! $isResolved && $remainingOverdue <= 0;

        return [
            'id' => $case->id,
            'status' => $case->status,
            'opened_at' => $case->opened_at->toDateTimeString(),
            'resolved_at' => $case->resolved_at?->toDateTimeString(),
            'marketing_id' => $case->marketing_id,
            'can_record' => $isOwner,
            'allowed_transitions' => $isOwner
                ? (ShmCollectionCase::ALLOWED_TRANSITIONS[$case->status] ?? [])
                : [],
            'remaining_overdue' => round($remainingOverdue, 2),
            'resolution_ready' => $resolutionReady,
            'can_resolve' => $isOwner && $resolutionReady,
            'activities' => $case->activities
                ->map(fn (ShmCollectionActivity $activity): array => [
                    'id' => $activity->id,
                    'activity_type' => $activity->activity_type,
                    'result' => $activity->result,
                    'activity_at' => $activity->activity_at->toDateTimeString(),
                    'notes' => $activity->notes,
                    'next_action' => $activity->next_action,
                    'created_at' => $activity->created_at?->toDateTimeString(),
                    'actor' => [
                        'id' => $activity->actor?->id,
                        'name' => $activity->actor?->name,
                    ],
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Satu baris work queue — seluruh angka dari agregat server-side yang
     * sudah dipilih di query (bukan hasil hitung ulang di frontend).
     *
     * @return array<string, mixed>
     */
    private function presentCollectionRow(ShmLoan $loan): array
    {
        $earliest = $loan->getAttribute('earliest_overdue_due_date');
        // Aggregate date mentah bisa berbentuk 'Y-m-d H:i:s' (SQLite) maupun
        // 'Y-m-d' (PostgreSQL) — dinormalkan ke tanggal ISO props.
        $earliestOrNull = $earliest !== null
            ? Carbon::parse((string) $earliest)->toDateString()
            : null;
        $outstandingPrincipal = (float) $loan->getAttribute('outstanding_principal_sum');
        $outstandingInterest = (float) $loan->getAttribute('outstanding_interest_sum');
        $lastPaymentDate = $loan->getAttribute('last_payment_date');

        return [
            'id' => $loan->id,
            'loan_number' => $loan->loan_number,
            'principal' => (float) $loan->principal,
            'disbursed_at' => $loan->disbursed_at?->toDateString(),
            'status' => $loan->status,
            'customer' => [
                'id' => $loan->customer?->id,
                'customer_number' => $loan->customer?->customer_number,
                'name' => $loan->customer?->name,
                'nik' => $loan->customer?->nik,
            ],
            'product' => [
                'id' => $loan->product?->id,
                'code' => $loan->product?->code,
                'name' => $loan->product?->name,
            ],
            'earliest_overdue_due_date' => $earliestOrNull,
            // Days overdue = hari ini − jatuh tempo overdue terawal; tanpa
            // penalty/grace period (PENDING), murni selisih tanggal.
            'days_overdue' => $earliestOrNull !== null
                ? (int) Carbon::parse($earliestOrNull)->diffInDays(today(), true)
                : 0,
            'overdue' => [
                'installments_count' => (int) $loan->getAttribute('overdue_installments_count'),
                'outstanding_principal' => round($outstandingPrincipal, 2),
                'outstanding_interest' => round($outstandingInterest, 2),
                'total_outstanding' => round($outstandingPrincipal + $outstandingInterest, 2),
            ],
            'last_payment_date' => $lastPaymentDate !== null
                ? Carbon::parse((string) $lastPaymentDate)->toDateString()
                : null,
        ];
    }

    /**
     * Payload detail Collection (ringkasan + jadwal + riwayat pembayaran +
     * progres pembayaran overdue Ticket 95).
     *
     * @param  array{overdue_principal: string, overdue_interest: string, overdue_total: string, allocated_principal: string, allocated_interest: string, allocated_total: string, remaining_overdue: string}  $paymentProgress
     * @return array<string, mixed>
     */
    private function presentCollectionDetail(ShmLoan $loan, array $paymentProgress): array
    {
        $today = today();

        return [
            'loan' => [
                'id' => $loan->id,
                'loan_number' => $loan->loan_number,
                'status' => $loan->status,
                'principal' => (float) $loan->principal,
                'tenor_months' => $loan->tenor_months,
                'rate_percent' => (float) $loan->rate_percent,
                'rate_period' => $loan->rate_period,
                'calculation_method' => $loan->calculation_method,
                'disbursed_at' => $loan->disbursed_at?->toDateString(),
                'first_due_date' => $loan->first_due_date?->toDateString(),
                'purpose' => $loan->purpose,
                'location' => $loan->location,
                'product' => [
                    'id' => $loan->product?->id,
                    'code' => $loan->product?->code,
                    'name' => $loan->product?->name,
                ],
                'customer' => [
                    'id' => $loan->customer?->id,
                    'customer_number' => $loan->customer?->customer_number,
                    'name' => $loan->customer?->name,
                    'nik' => $loan->customer?->nik,
                    'phone' => $loan->customer?->phone,
                    'address' => $loan->customer?->address,
                    'is_active' => $loan->customer?->is_active,
                    // Kaitan ke anggota koperasi lewat relasi existing
                    // (ShmCustomer::member) — tanpa konversi/efek samping.
                    // Nama anggota memakai kolom `full_name` (bukan `name`
                    // — tabel members tidak punya kolom `name`).
                    'member' => $loan->customer?->member !== null
                        ? [
                            'id' => $loan->customer->member->id,
                            'member_number' => $loan->customer->member->member_number,
                            'name' => $loan->customer->member->full_name,
                        ]
                        : null,
                ],
            ],
            // Ringkasan dari service — predikat & formula yang SAMA dengan
            // daftar, sehingga angka detail tidak pernah menyimpang.
            'overdue' => $this->collections->overdueSummary($loan->installments),
            'schedule' => $loan->installments
                ->map(fn (ShmLoanInstallment $installment): array => $this->presentInstallment($installment, $today))
                ->values()
                ->all(),
            'payments' => $loan->payments
                ->map(fn (ShmLoanPayment $payment): array => $this->presentCollectionPayment($payment))
                ->values()
                ->all(),
            // Rantai kewajiban → alokasi → sisa (Ticket 95) — seluruh angka
            // dari SATU agregat SQL server-side; frontend tidak menghitung.
            'payment_progress' => $this->presentPaymentProgress($loan, $paymentProgress),
        ];
    }

    /**
     * Progres pembayaran overdue + pembayaran terakhir (Ticket 95).
     *
     * Nilai finansial diteruskan apa adanya dari agregat query service;
     * pembayaran terakhir diambil dari baris yang sudah dimuat (posted saja —
     * konsisten dengan last_payment_date pada daftar T90), tanpa query
     * tambahan per pembayaran.
     *
     * @param  array{overdue_principal: string, overdue_interest: string, overdue_total: string, allocated_principal: string, allocated_interest: string, allocated_total: string, remaining_overdue: string}  $paymentProgress
     * @return array<string, mixed>
     */
    private function presentPaymentProgress(ShmLoan $loan, array $paymentProgress): array
    {
        $latestPayment = $loan->payments
            ->filter(fn (ShmLoanPayment $payment): bool => $payment->status === ShmLoanPayment::STATUS_POSTED)
            ->sortBy([['payment_date', 'desc'], ['id', 'desc']])
            ->first();

        return [
            'overdue_principal' => round((float) $paymentProgress['overdue_principal'], 2),
            'overdue_interest' => round((float) $paymentProgress['overdue_interest'], 2),
            'overdue_total' => round((float) $paymentProgress['overdue_total'], 2),
            'allocated_principal' => round((float) $paymentProgress['allocated_principal'], 2),
            'allocated_interest' => round((float) $paymentProgress['allocated_interest'], 2),
            'allocated_total' => round((float) $paymentProgress['allocated_total'], 2),
            'remaining_overdue' => round((float) $paymentProgress['remaining_overdue'], 2),
            'latest_payment' => $latestPayment !== null
                ? [
                    'payment_date' => $latestPayment->payment_date?->format('Y-m-d'),
                    'payment_reference' => $latestPayment->payment_reference,
                ]
                : null,
        ];
    }

    /**
     * Satu baris jadwal angsuran.
     *
     * `state` adalah presentation state yang DERIVED dari amounts +
     * tanggal (paid / overdue / partial / scheduled). TIDAK ada status DB
     * baru — enum angsuran tetap hanya scheduled|paid; status lanjutan
     * (partially_paid, overdue) masih PENDING BUSINESS CONFIRMATION.
     *
     * @return array<string, mixed>
     */
    private function presentInstallment(ShmLoanInstallment $installment, Carbon $today): array
    {
        $outstandingPrincipal = (float) $installment->outstandingPrincipal();
        $outstandingInterest = (float) $installment->outstandingInterest();
        $outstandingTotal = round($outstandingPrincipal + $outstandingInterest, 2);

        $state = match (true) {
            $outstandingTotal <= 0 => 'paid',
            $this->collections->isOverdueInstallment($installment, $today) => 'overdue',
            (float) $installment->paid_amount > 0 => 'partial',
            default => 'scheduled',
        };

        return [
            'installment_number' => $installment->installment_number,
            'due_date' => $installment->due_date?->format('Y-m-d'),
            'principal_amount' => (float) $installment->principal_amount,
            'interest_amount' => (float) $installment->interest_amount,
            'paid_principal_amount' => (float) $installment->paid_principal_amount,
            'paid_interest_amount' => (float) $installment->paid_interest_amount,
            'outstanding_principal' => round($outstandingPrincipal, 2),
            'outstanding_interest' => round($outstandingInterest, 2),
            'outstanding_total' => $outstandingTotal,
            'state' => $state,
            'overdue_days' => $state === 'overdue'
                ? (int) $installment->due_date->diffInDays($today, true)
                : null,
        ];
    }

    /**
     * Satu baris riwayat pembayaran — agregat alokasi dari baris yang sudah
     * dimuat (eager `payments.allocations`), bukan query per pembayaran.
     *
     * `allocated_penalty` selalu 0 selama formula penalty masih PENDING;
     * kolomnya ditampilkan apa adanya, bukan dihitung sendiri.
     *
     * @return array<string, mixed>
     */
    private function presentCollectionPayment(ShmLoanPayment $payment): array
    {
        $allocations = $payment->allocations;
        $amount = (float) $payment->amount;

        $allocatedPrincipal = (float) $allocations->sum(
            fn (ShmLoanPaymentAllocation $allocation): float => (float) $allocation->principal_amount,
        );
        $allocatedInterest = (float) $allocations->sum(
            fn (ShmLoanPaymentAllocation $allocation): float => (float) $allocation->interest_amount,
        );
        $allocatedPenalty = (float) $allocations->sum(
            fn (ShmLoanPaymentAllocation $allocation): float => (float) $allocation->penalty_amount,
        );
        $allocatedTotal = (float) $allocations->sum(
            fn (ShmLoanPaymentAllocation $allocation): float => (float) $allocation->total_amount,
        );

        return [
            'id' => $payment->id,
            'payment_reference' => $payment->payment_reference,
            'payment_date' => $payment->payment_date?->format('Y-m-d'),
            'amount' => round($amount, 2),
            'status' => $payment->status,
            'allocated_principal' => round($allocatedPrincipal, 2),
            'allocated_interest' => round($allocatedInterest, 2),
            'allocated_penalty' => round($allocatedPenalty, 2),
            'allocated_total' => round($allocatedTotal, 2),
            'unallocated' => round($amount - $allocatedTotal, 2),
        ];
    }

    /**
     * Garis waktu read-only dari data audit YANG SUDAH ADA: riwayat
     * pengajuan asal, event pinjaman (jadwal dll.), event pembayaran, dan
     * event Collection Case pinjaman ini (pembuatan + perubahan status
     * lifecycle — Ticket 94, tanpa event lain).
     *
     * Tidak pernah menulis audit — GET Collection tidak menghasilkan
     * mutation apa pun.
     *
     * @return array<int, array{id: int, action: string, description: string|null, actor: string|null, created_at: Carbon|null}>
     */
    private function collectionTimeline(ShmLoan $loan): array
    {
        $applicationId = $loan->shm_loan_application_id;
        $paymentIds = $loan->payments->pluck('id');
        $caseIds = ShmCollectionCase::query()
            ->where('shm_loan_id', $loan->getKey())
            ->pluck('id');

        return AuditLog::query()
            ->with('actor:id,name')
            ->where(function (Builder $query) use ($loan, $applicationId, $paymentIds, $caseIds): void {
                $query
                    ->where(fn (Builder $loanEvents) => $loanEvents
                        ->where('object_type', ShmLoan::class)
                        ->where('object_id', $loan->id))
                    ->when(
                        $applicationId !== null,
                        fn (Builder $events) => $events->orWhere(fn (Builder $applicationEvents) => $applicationEvents
                            ->where('object_type', ShmLoanApplication::class)
                            ->where('object_id', $applicationId)),
                    )
                    ->when(
                        $paymentIds->isNotEmpty(),
                        fn (Builder $events) => $events->orWhere(fn (Builder $paymentEvents) => $paymentEvents
                            ->where('object_type', ShmLoanPayment::class)
                            ->whereIn('object_id', $paymentIds)),
                    )
                    ->when(
                        $caseIds->isNotEmpty(),
                        fn (Builder $events) => $events->orWhere(fn (Builder $caseEvents) => $caseEvents
                            ->where('object_type', ShmCollectionCase::class)
                            ->whereIn('object_id', $caseIds)),
                    );
            })
            ->orderByDesc('created_at')
            ->limit(30)
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
     * Parsing input rentang days overdue: hanya bilangan bulat ≥ 0 yang
     * diterima, selain itu diabaikan (null = tanpa filter).
     *
     * Ini murni parsing input — bukan penetapan aturan bisnis; angka
     * rentangnya dikirim operator, bukan ditentukan sistem.
     */
    private function daysOverdueFilter(mixed $value): ?int
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '' || ! ctype_digit($value)) {
            return null;
        }

        return (int) $value;
    }
}
