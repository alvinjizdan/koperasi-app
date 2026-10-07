<?php

namespace App\Http\Controllers\Shm;

use App\Enums\ShmApplicationStatus;
use App\Enums\ShmCreditCheckOutcome;
use App\Enums\ShmCreditCheckStatus;
use App\Http\Controllers\Concerns\AuthorizesShmOwnership;
use App\Http\Controllers\Concerns\BuildsAuditHistory;
use App\Http\Controllers\Concerns\InteractsWithSorting;
use App\Http\Controllers\Controller;
use App\Http\Requests\Shm\CompleteShmCreditCheckRequest;
use App\Http\Requests\Shm\TransitionShmApplicationRequest;
use App\Http\Requests\Shm\UpdateShmCreditCheckRequest;
use App\Models\ShmApplication;
use App\Models\ShmCreditCheck;
use App\Models\User;
use App\Services\ShmWorkflowService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Credit Checking SHM sebagai workflow operasional manual.
 *
 * Menu yang dilayani:
 * - Proses Marketing : daftar pengajuan yang sedang/perlu dikerjakan.
 * - Hasil Marketing  : hanya credit checking yang sudah selesai.
 *
 * PENTING: ini BUKAN integrasi BI Checking/SLIK/OJK dan BUKAN penilaian
 * kredit. Tidak ada skor, ambang angka, blacklist, atau keputusan otomatis.
 * `outcome` hanya hasil pencatatan manual petugas. Menyelesaikan credit
 * checking TIDAK mengubah status pengajuan menjadi approved/rejected; keputusan
 * itu tetap milik workflow Approval.
 *
 * Seluruh perpindahan status hanya melalui ShmWorkflowService. Satu aksi
 * credit checking menghasilkan tepat satu event audit pada baris credit
 * checking; pemindahan tahap pengajuan (bila memang diperlukan saat memulai)
 * adalah perubahan objek yang berbeda dan tercatat pada audit pengajuan.
 */
class ShmCreditCheckController extends Controller
{
    use AuthorizesShmOwnership;
    use BuildsAuditHistory;
    use InteractsWithSorting;

    /**
     * Tahap pengajuan saat credit checking relevan dikerjakan.
     *
     * Tahap `Submitted` ikut disertakan supaya pengajuan yang baru dikirim
     * Marketing langsung muncul pada daftar Proses Marketing dan dapat dimulai
     * pengerjaannya. Setiap baris pada daftar ini memang dapat ditindaklanjuti.
     */
    private const PROCESS_STAGES = [
        ShmApplicationStatus::Submitted,
        ShmApplicationStatus::CreditChecking,
        ShmApplicationStatus::Memorandum,
        ShmApplicationStatus::Approval,
    ];

    public function __construct(
        private readonly ShmWorkflowService $workflow,
    ) {}

    /**
     * Proses Marketing: daftar pengajuan yang perlu/sedang diperiksa.
     *
     * Daftar berbasis PENGAJUAN, bukan credit checking, supaya pengajuan yang
     * belum memiliki baris credit checking tetap muncul dengan aksi
     * "Mulai Checking". Tidak ada baris credit checking dummy yang dibuat
     * otomatis.
     */
    public function index(Request $request): Response
    {
        $filters = [
            'search' => $request->input('search'),
            'status' => $request->input('status', 'all'),
            'outcome' => $request->input('outcome', 'all'),
            'checker_id' => $request->input('checker_id', 'all'),
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
        ];

        $query = $this->processQuery($request)
            ->when($filters['search'], fn (Builder $q, string $search) => $this->applySearch($q, $search))
            ->when(
                $filters['status'] === 'not_started',
                fn (Builder $q) => $q->where(function (Builder $inner): void {
                    $inner->whereNull('shm_credit_checks.id')
                        ->orWhere('shm_credit_checks.status', ShmCreditCheckStatus::Pending->value);
                })
            )
            ->when(
                $filters['status'] === ShmCreditCheckStatus::InProgress->value,
                fn (Builder $q) => $q->where('shm_credit_checks.status', ShmCreditCheckStatus::InProgress->value)
            )
            ->when(
                $filters['status'] === ShmCreditCheckStatus::Completed->value,
                fn (Builder $q) => $q->where('shm_credit_checks.status', ShmCreditCheckStatus::Completed->value)
            )
            ->when(
                $filters['outcome'] !== 'all',
                fn (Builder $q) => $q->where('shm_credit_checks.outcome', $filters['outcome'])
            )
            ->when(
                $filters['checker_id'] !== 'all',
                fn (Builder $q) => $q->where('shm_credit_checks.filled_by', (int) $filters['checker_id'])
            )
            // Rentang tanggal mengikuti waktu mulai pengerjaan. Baris yang belum
            // dimulai memang tidak memiliki started_at, sehingga tidak muncul
            // saat rentang tanggal dipakai.
            ->when(
                $filters['date_from'],
                fn (Builder $q, string $date) => $q->whereDate('shm_credit_checks.started_at', '>=', $date)
            )
            ->when(
                $filters['date_to'],
                fn (Builder $q, string $date) => $q->whereDate('shm_credit_checks.started_at', '<=', $date)
            );

        // Whitelist kolom yang boleh diurutkan. Kolom yang ditampilkan sebagai
        // header sortable pada halaman wajib ada di sini; kolom di luar daftar
        // ini diabaikan tanpa error (lihat QuerySorter).
        $sortStatus = $this->applySorting($query, $request, [
            'application_number' => 'shm_applications.application_number',
            'customer' => 'shm_customers.name',
            'status' => 'shm_credit_checks.status',
            'started_at' => 'shm_credit_checks.started_at',
            'completed_at' => 'shm_credit_checks.checked_at',
            'updated_at' => 'shm_credit_checks.updated_at',
            'created_at' => 'shm_applications.created_at',
        ], [['shm_applications.created_at', 'desc']]);

        $creditChecks = $query->paginate($this->resolvePerPage($request))
            ->withQueryString()
            ->through(fn (ShmApplication $row): array => $this->processRow($row));

        return Inertia::render('Shm/CreditChecks/Index', [
            'credit_checks' => $creditChecks,
            'sort' => $sortStatus,
            'filters' => $filters,
            'statuses' => $this->statusOptions(),
            'outcomes' => $this->outcomeOptions(),
            'checkers' => $this->checkerOptions($request),
            'can_update' => Gate::allows('shm.credit_checking.update'),
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
        ]);
    }

    /**
     * Hasil Marketing: hanya credit checking yang sudah COMPLETED.
     *
     * Data yang masih pending atau sedang berjalan tidak pernah ditampilkan di
     * sini supaya tidak terbaca sebagai hasil final.
     */
    public function results(Request $request): Response
    {
        $filters = [
            'search' => $request->input('search'),
            'outcome' => $request->input('outcome', 'all'),
            'checker_id' => $request->input('checker_id', 'all'),
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
        ];

        $query = $this->completedQuery($request)
            ->when($filters['search'], fn (Builder $q, string $search) => $this->applySearch($q, $search))
            ->when(
                $filters['outcome'] !== 'all',
                fn (Builder $q) => $q->where('shm_credit_checks.outcome', $filters['outcome'])
            )
            ->when(
                $filters['checker_id'] !== 'all',
                fn (Builder $q) => $q->where('shm_credit_checks.filled_by', (int) $filters['checker_id'])
            )
            ->when(
                $filters['date_from'],
                fn (Builder $q, string $date) => $q->whereDate('shm_credit_checks.checked_at', '>=', $date)
            )
            ->when(
                $filters['date_to'],
                fn (Builder $q, string $date) => $q->whereDate('shm_credit_checks.checked_at', '<=', $date)
            );

        $sortStatus = $this->applySorting($query, $request, [
            'application_number' => 'shm_applications.application_number',
            'customer' => 'shm_customers.name',
            'outcome' => 'shm_credit_checks.outcome',
            'completed_at' => 'shm_credit_checks.checked_at',
            'updated_at' => 'shm_credit_checks.updated_at',
        ], [['shm_credit_checks.checked_at', 'desc']]);

        $results = $query->paginate($this->resolvePerPage($request))
            ->withQueryString()
            ->through(fn (ShmCreditCheck $creditCheck): array => [
                'id' => $creditCheck->id,
                'application_id' => $creditCheck->shm_application_id,
                'application_number' => $creditCheck->application_number,
                'customer_name' => $creditCheck->customer_name,
                'customer_number' => $creditCheck->customer_number,
                'outcome' => $creditCheck->outcome?->value,
                'outcome_label' => $creditCheck->outcome?->label(),
                'outcome_badge' => $creditCheck->outcome?->badgeKey(),
                'checker' => $creditCheck->checker_name,
                'started_at' => $creditCheck->started_at,
                'completed_at' => $creditCheck->checked_at,
                'result' => $creditCheck->result,
            ]);

        return Inertia::render('Shm/CreditChecks/Results', [
            'results' => $results,
            'sort' => $sortStatus,
            'filters' => $filters,
            'outcomes' => $this->outcomeOptions(),
            'checkers' => $this->checkerOptions($request),
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
        ]);
    }

    /**
     * Detail credit checking sebagai pusat operasional.
     */
    public function show(Request $request, ShmCreditCheck $creditCheck): Response
    {
        $creditCheck->load(['application.customer', 'checker:id,name']);

        $application = $creditCheck->application;

        $this->authorizeShmOwnership($request, $application);

        return Inertia::render('Shm/CreditChecks/Show', [
            'credit_check' => [
                'id' => $creditCheck->id,
                'status' => $creditCheck->status->value,
                'status_label' => $creditCheck->statusLabel(),
                'status_badge' => $creditCheck->status->badgeKey(),
                'outcome' => $creditCheck->outcome?->value,
                'outcome_label' => $creditCheck->outcome?->label(),
                'outcome_badge' => $creditCheck->outcome?->badgeKey(),
                'checker' => $creditCheck->checker?->name,
                'started_at' => $creditCheck->started_at,
                'completed_at' => $creditCheck->checked_at,
                'result' => $creditCheck->result,
                'notes' => $creditCheck->notes,
                'created_at' => $creditCheck->created_at,
                'updated_at' => $creditCheck->updated_at,
            ],
            'application' => [
                'id' => $application->id,
                'application_number' => $application->application_number,
                'status' => $application->status->value,
                'status_label' => $application->status->label(),
                'created_at' => $application->created_at,
            ],
            'customer' => $application->customer === null ? null : [
                'id' => $application->customer->id,
                'customer_number' => $application->customer->customer_number,
                'name' => $application->customer->name,
                'nik' => $application->customer->nik,
                'phone' => $application->customer->phone,
                'email' => $application->customer->email,
                'address' => $application->customer->address,
            ],
            'timeline' => $this->auditHistory(ShmCreditCheck::class, $creditCheck->id),
            'outcomes' => $this->outcomeChoices(),
            'permissions' => [
                'can_manage' => Gate::allows('shm.credit_checking.update'),
            ],
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
        ]);
    }

    /**
     * Mulai pengerjaan credit checking sebuah pengajuan.
     *
     * Membuat baris credit checking secara eksplisit. Pengajuan harus berada
     * pada tahap yang memang relevan; hasilnya TIDAK mengubah status pengajuan.
     */
    public function start(
        TransitionShmApplicationRequest $request,
        ShmApplication $application,
    ): RedirectResponse {
        $this->authorizeShmOwnership($request, $application);

        // Pengajuan yang baru dikirim Marketing masih berstatus "Diajukan" dan
        // belum melewati tahap credit checking. Aksi "Mulai Checking"
        // sekaligus memindahkan pengajuan ke tahap tersebut supaya proses dapat
        // dijalankan langsung dari daftar Proses Marketing tanpa langkah
        // tersembunyi. Perpindahan tahap tetap melalui ShmWorkflowService,
        // sehingga peta transisi Ticket 45 tetap dihormati (Submitted ->
        // CreditChecking memang satu-satunya arah yang sah dari status itu).
        if ($application->status === ShmApplicationStatus::Submitted) {
            $application = $this->workflow->startCreditChecking($application, $request->user()->id);
        }

        $this->workflow->guardStage($application, self::PROCESS_STAGES, 'Memulai credit checking');

        $creditCheck = $this->workflow->startCreditCheck($application, $request->user()->id);

        return to_route('shm.credit-checks.show', $creditCheck->id)
            ->with('success', 'Credit checking dimulai.');
    }

    /**
     * Perbarui hasil sementara credit checking.
     */
    public function update(UpdateShmCreditCheckRequest $request, ShmCreditCheck $creditCheck): RedirectResponse
    {
        $creditCheck->loadMissing('application');

        $this->authorizeShmOwnership($request, $creditCheck->application);

        $this->workflow->updateCreditCheck(
            $creditCheck,
            $request->outcomeValue(),
            $request->resultValue(),
            $request->notesValue(),
            $request->user()->id,
        );

        return back()->with('success', 'Hasil credit checking berhasil diperbarui.');
    }

    /**
     * Selesaikan credit checking.
     *
     * Status pengajuan SHM tidak ikut berubah; keputusan setuju/tolak tetap
     * melalui workflow Approval.
     */
    public function complete(CompleteShmCreditCheckRequest $request, ShmCreditCheck $creditCheck): RedirectResponse
    {
        $creditCheck->loadMissing('application');

        $this->authorizeShmOwnership($request, $creditCheck->application);

        $this->workflow->completeCreditCheck(
            $creditCheck,
            $request->outcomeValue(),
            $request->resultValue(),
            $request->notesValue(),
            $request->user()->id,
        );

        return to_route('shm.credit-checks.show', $creditCheck->id)
            ->with('success', 'Credit checking diselesaikan.');
    }

    /**
     * Query dasar Proses Marketing: pengajuan pada tahap pengerjaan, dengan
     * data nasabah dan credit checking-nya di-join sekali jalan.
     *
     * @return Builder<ShmApplication>
     */
    private function processQuery(Request $request): Builder
    {
        $query = ShmApplication::query()
            ->leftJoin('shm_customers', 'shm_customers.id', '=', 'shm_applications.shm_customer_id')
            ->leftJoin('shm_credit_checks', 'shm_credit_checks.shm_application_id', '=', 'shm_applications.id')
            ->leftJoin('users as credit_checkers', 'credit_checkers.id', '=', 'shm_credit_checks.filled_by')
            ->whereIn('shm_applications.status', array_map(
                static fn (ShmApplicationStatus $status): string => $status->value,
                self::PROCESS_STAGES,
            ))
            ->select([
                'shm_applications.id',
                'shm_applications.application_number',
                'shm_applications.shm_customer_id',
                'shm_applications.status as application_status',
                'shm_applications.created_at',
                'shm_customers.customer_number',
                'shm_customers.name as customer_name',
                'shm_customers.nik as customer_nik',
                'shm_credit_checks.id as credit_check_id',
                'shm_credit_checks.status as credit_check_status',
                'shm_credit_checks.outcome as credit_check_outcome',
                'shm_credit_checks.started_at as credit_check_started_at',
                'shm_credit_checks.checked_at as credit_check_completed_at',
                'shm_credit_checks.updated_at as credit_check_updated_at',
                'credit_checkers.name as checker_name',
            ]);

        // Tanpa shm.view_all, Marketing hanya melihat pengajuan miliknya sendiri.
        if (! $this->canViewAllShm()) {
            $query->where('shm_applications.marketing_id', $request->user()->id);
        }

        return $query;
    }

    /**
     * Query dasar Hasil Marketing: hanya credit checking COMPLETED.
     *
     * @return Builder<ShmCreditCheck>
     */
    private function completedQuery(Request $request): Builder
    {
        $query = ShmCreditCheck::query()
            ->join('shm_applications', 'shm_applications.id', '=', 'shm_credit_checks.shm_application_id')
            ->leftJoin('shm_customers', 'shm_customers.id', '=', 'shm_applications.shm_customer_id')
            ->leftJoin('users as credit_checkers', 'credit_checkers.id', '=', 'shm_credit_checks.filled_by')
            // Penjagaan utama: hasil final hanya yang benar-benar selesai.
            ->where('shm_credit_checks.status', ShmCreditCheckStatus::Completed->value)
            ->select([
                'shm_credit_checks.id',
                'shm_credit_checks.shm_application_id',
                'shm_credit_checks.status',
                'shm_credit_checks.outcome',
                'shm_credit_checks.result',
                'shm_credit_checks.started_at',
                'shm_credit_checks.checked_at',
                'shm_credit_checks.updated_at',
                'shm_applications.application_number',
                'shm_customers.customer_number',
                'shm_customers.name as customer_name',
                'credit_checkers.name as checker_name',
            ]);

        if (! $this->canViewAllShm()) {
            $query->where('shm_applications.marketing_id', $request->user()->id);
        }

        return $query;
    }

    /**
     * Bentuk satu baris Proses Marketing untuk frontend.
     *
     * @return array<string, mixed>
     */
    private function processRow(ShmApplication $row): array
    {
        $status = $row->credit_check_status === null
            ? null
            : ShmCreditCheckStatus::from((string) $row->credit_check_status);

        $outcome = $row->credit_check_outcome === null
            ? null
            : ShmCreditCheckOutcome::from((string) $row->credit_check_outcome);

        $action = match (true) {
            $row->credit_check_id === null => 'start',
            $status === ShmCreditCheckStatus::Pending => 'start',
            $status === ShmCreditCheckStatus::InProgress => 'continue',
            default => 'view',
        };

        $applicationStatus = ShmApplicationStatus::from((string) $row->application_status);

        return [
            'application_id' => $row->id,
            'application_number' => $row->application_number,
            'application_status' => $applicationStatus->value,
            'application_status_label' => $applicationStatus->label(),
            'customer_id' => $row->shm_customer_id,
            'customer_number' => $row->customer_number,
            'customer_name' => $row->customer_name,
            'customer_nik' => $row->customer_nik,
            'credit_check_id' => $row->credit_check_id,
            'status' => $status?->value,
            'status_label' => $status?->label() ?? ShmCreditCheckStatus::Pending->label(),
            'status_badge' => $status?->badgeKey() ?? ShmCreditCheckStatus::Pending->badgeKey(),
            'outcome' => $outcome?->value,
            'outcome_label' => $outcome?->label(),
            'outcome_badge' => $outcome?->badgeKey(),
            'checker' => $row->checker_name,
            'started_at' => $row->credit_check_started_at,
            'completed_at' => $row->credit_check_completed_at,
            'updated_at' => $row->credit_check_updated_at,
            'action' => $action,
        ];
    }

    /**
     * Pencarian nomor pengajuan / identitas nasabah.
     *
     * Memakai `like` agar konsisten dengan scope pencarian master data lain dan
     * tetap berjalan pada driver SQLite yang dipakai test suite.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    private function applySearch(Builder $query, string $search): void
    {
        $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';

        $query->where(function (Builder $inner) use ($term): void {
            $inner->where('shm_applications.application_number', 'like', $term)
                ->orWhere('shm_customers.name', 'like', $term)
                ->orWhere('shm_customers.customer_number', 'like', $term)
                ->orWhere('shm_customers.nik', 'like', $term);
        });
    }

    /**
     * Daftar petugas yang pernah menangani credit checking dalam cakupan akses
     * pengguna, untuk pilihan filter.
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function checkerOptions(Request $request): array
    {
        $query = ShmCreditCheck::query()->whereNotNull('filled_by');

        if (! $this->canViewAllShm()) {
            $query->forMarketing($request->user()->id);
        }

        $ids = $query->distinct()->pluck('filled_by');

        return User::query()
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(static fn (User $user): array => [
                'value' => (string) $user->id,
                'label' => $user->name,
            ])
            ->all();
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function statusOptions(): array
    {
        return [
            ['value' => 'all', 'label' => 'Semua Status'],
            ['value' => 'not_started', 'label' => 'Belum Dimulai'],
            ['value' => ShmCreditCheckStatus::InProgress->value, 'label' => ShmCreditCheckStatus::InProgress->label()],
            ['value' => ShmCreditCheckStatus::Completed->value, 'label' => ShmCreditCheckStatus::Completed->label()],
        ];
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function outcomeOptions(): array
    {
        return [
            ['value' => 'all', 'label' => 'Semua Hasil'],
            ...$this->outcomeChoices(),
        ];
    }

    /**
     * Pilihan outcome tanpa sentinel "all", untuk form input.
     *
     * @return array<int, array{value: string, label: string, description: string}>
     */
    private function outcomeChoices(): array
    {
        return array_map(static fn (ShmCreditCheckOutcome $outcome): array => [
            'value' => $outcome->value,
            'label' => $outcome->label(),
            'description' => $outcome->description(),
        ], ShmCreditCheckOutcome::cases());
    }
}
