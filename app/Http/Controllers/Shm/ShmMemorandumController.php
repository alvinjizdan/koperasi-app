<?php

namespace App\Http\Controllers\Shm;

use App\Enums\ShmApplicationStatus;
use App\Http\Controllers\Concerns\AuthorizesShmOwnership;
use App\Http\Controllers\Concerns\BuildsAuditHistory;
use App\Http\Controllers\Concerns\InteractsWithSorting;
use App\Http\Controllers\Controller;
use App\Http\Requests\Shm\UpdateShmMemorandumRequest;
use App\Models\ShmApplication;
use App\Models\ShmCreditCheck;
use App\Models\ShmCustomer;
use App\Models\ShmMemorandum;
use App\Services\ShmWorkflowService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Modul Memorandum (Ticket 49): SHM/SHGB dan Buyback.
 *
 * Controller ini TIDAK menulis status/kategori memorandum secara langsung.
 * Pembuatan dan perubahan selalu lewat ShmWorkflowService::recordMemorandum
 * supaya transisi tahap, actor, timestamp, dan audit berasal dari satu sumber
 * yang sama seperti endpoint memorandum Ticket 44.
 *
 * Pembatasan data "milik sendiri" ditegakkan di level query, bukan hanya di UI:
 * Marketing tanpa shm.view_all hanya melihat memorandum dari pengajuannya
 * sendiri.
 */
class ShmMemorandumController extends Controller
{
    use AuthorizesShmOwnership;
    use BuildsAuditHistory;
    use InteractsWithSorting;

    public function __construct(
        private readonly ShmWorkflowService $workflow,
    ) {}

    /**
     * Daftar memorandum dengan pencarian, filter kategori/status, sorting,
     * dan pagination.
     */
    public function index(Request $request): Response
    {
        $this->authorizeAction('shm.memorandum.update');

        $filters = [
            'search' => $request->input('search'),
            'type' => $request->input('type'),
            'status' => $request->input('status'),
        ];

        $query = ShmMemorandum::query()
            // Memorandum selalu dibaca lewat pengajuannya: batasan "milik
            // sendiri" mengikuti pemilik pengajuan, bukan kolom terpisah.
            ->whereHas('application', fn (Builder $q) => $this->scopeApplication($q))
            ->with([
                'application:id,application_number,shm_customer_id,marketing_id,status',
                'application.customer:id,customer_number,name',
                'creator:id,name',
            ])
            ->when($filters['type'], fn (Builder $q, string $type) => $q->where('type', $type))
            ->when($filters['status'], fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($filters['search'], function (Builder $q, string $search): void {
                $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%';

                $q->where(function (Builder $inner) use ($term): void {
                    $inner->where('notes', 'like', $term)
                        ->orWhereIn(
                            'shm_application_id',
                            ShmApplication::query()
                                ->select('id')
                                ->where(function (Builder $app) use ($term): void {
                                    $app->where('application_number', 'like', $term)
                                        ->orWhereIn(
                                            'shm_customer_id',
                                            ShmCustomer::query()
                                                ->select('id')
                                                ->where(function (Builder $customer) use ($term): void {
                                                    $customer->where('name', 'like', $term)
                                                        ->orWhere('customer_number', 'like', $term);
                                                }),
                                        );
                                }),
                        );
                });
            });

        $sortStatus = $this->applySorting($query, $request, [
            'type' => 'type',
            'status' => 'status',
            'created_at' => 'created_at',
            'updated_at' => 'updated_at',
        ], [['created_at', 'desc']]);

        $memorandums = $query->paginate($this->resolvePerPage($request))
            ->withQueryString()
            ->through(fn (ShmMemorandum $memorandum): array => $this->summaryRow($memorandum));

        return Inertia::render('Shm/Memorandum/Index', [
            'memorandums' => $memorandums,
            'sort' => $sortStatus,
            'filters' => $filters,
            'types' => ShmMemorandum::typeLabels(),
            'statuses' => collect(ShmMemorandum::statusLabels())
                ->map(fn (string $label, string $value): array => [
                    'value' => $value,
                    'label' => $label,
                ])->values()->all(),
            'can_create' => Gate::allows('shm.memorandum.update'),
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
        ]);
    }

    /**
     * Form pembuatan memorandum berdasarkan pengajuan SHM.
     */
    public function create(Request $request): Response
    {
        $this->authorizeAction('shm.memorandum.update');

        $selectedApplication = null;

        // Konteks pengajuan terpilih: agar Marketing memahami pengajuan, nasabah,
        // objek, dan hasil credit checking SEBELUM mencatat memorandum.
        if ($applicationId = $request->input('application_id')) {
            $application = $this->scopedApplicationQuery()
                ->with('latestCreditCheck.checker:id,name')
                ->findOrFail((int) $applicationId);

            $selectedApplication = $this->applicationContext($application);

            // Hasil credit checking hanya REFERENSI sebelum membuat memorandum;
            // tidak ada aturan otomatis (pass/fail tidak mengubah apa pun).
            if ($application->latestCreditCheck !== null) {
                $selectedApplication['credit_check'] = $this->creditCheckContext($application->latestCreditCheck);
            }
        }

        return Inertia::render('Shm/Memorandum/Create', [
            // Pilihan pengajuan dibatasi sesuai cakupan akses; hanya kolom yang
            // benar-benar dibutuhkan picker yang dikirim.
            'applications' => $this->scopedApplicationQuery()
                ->orderByDesc('created_at')
                ->limit(50)
                ->get(['id', 'application_number', 'shm_customer_id', 'status', 'created_at'])
                ->map(fn (ShmApplication $application): array => [
                    'id' => $application->id,
                    'application_number' => $application->application_number,
                    'status' => $application->status->value,
                    'status_label' => $application->status->label(),
                    'customer_label' => $application->customerLabel(),
                ])->all(),
            'selected_application' => $selectedApplication,
            'types' => collect(ShmMemorandum::typeLabels())
                ->map(fn (string $label, string $value): array => [
                    'value' => $value,
                    'label' => $label,
                ])->values()->all(),
            'statuses' => collect(ShmMemorandum::statusLabels())
                ->map(fn (string $label, string $value): array => [
                    'value' => $value,
                    'label' => $label,
                ])->values()->all(),
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
        ]);
    }

    /**
     * Simpan memorandum baru (atau perbarui memorandum yang sudah ada pada
     * pengajuan yang sama — relasi existing tetap satu baris per pengajuan).
     */
    public function store(UpdateShmMemorandumRequest $request): RedirectResponse
    {
        $application = $this->scopedApplicationQuery()->findOrFail($request->applicationIdValue());

        // Gate tahap yang sama dengan endpoint memorandum Ticket 44: memorandum
        // dicatat saat pengajuan berada di tahap Memorandum atau Approval.
        // Tidak ada gate baru dan tidak ada bypass state machine.
        $this->workflow->guardStage(
            $application,
            [ShmApplicationStatus::Memorandum, ShmApplicationStatus::Approval],
            'Pencatatan memorandum',
        );

        $this->workflow->recordMemorandum(
            $application,
            $request->statusValue(),
            $request->notesValue(),
            $request->user()->id,
            $request->typeValue(),
        );

        return to_route('shm.memorandums.index')
            ->with('success', 'Memorandum berhasil dicatat.');
    }

    /**
     * Detail memorandum: kategori, status, konteks pengajuan/nasabah/objek,
     * hasil credit checking, dan riwayat audit.
     */
    public function show(Request $request, ShmMemorandum $memorandum): Response
    {
        $this->authorizeAction('shm.memorandum.update');

        $application = $memorandum->application;
        $this->authorizeShmOwnership($request, $application);

        $application->load([
            'customer:id,customer_number,name,nik,phone,email,address',
            'marketing:id,name',
            'properties',
            'latestCreditCheck.checker:id,name',
        ]);

        // Credit checking terakhir dievaluasi SEKALI: relasi hasOne latestOfMany
        // tidak di-cache, jadi dua pemanggilan bisa menghasilkan baris berbeda
        // dan membuat pengecekan null lalu aksesnya tidak konsisten.
        $creditCheck = $application->latestCreditCheck;

        return Inertia::render('Shm/Memorandum/Show', [
            'memorandum' => [
                'id' => $memorandum->id,
                'type' => $memorandum->type,
                'type_label' => $memorandum->typeLabel(),
                'status' => $memorandum->status,
                'status_label' => $memorandum->statusLabel(),
                'notes' => $memorandum->notes,
                'submitted_at' => $memorandum->submitted_at,
                'created_at' => $memorandum->created_at,
                'updated_at' => $memorandum->updated_at,
                'creator' => $memorandum->creator?->name,
            ],
            'application' => $this->applicationContext($application),
            'properties' => $application->properties,
            'credit_check' => $creditCheck === null ? null : $this->creditCheckContext($creditCheck),
            'history' => $this->auditHistory(ShmMemorandum::class, $memorandum->id),
            'can_edit' => Gate::allows('shm.memorandum.update'),
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
        ]);
    }

    /**
     * Form perubahan memorandum.
     */
    public function edit(Request $request, ShmMemorandum $memorandum): Response
    {
        $this->authorizeAction('shm.memorandum.update');

        $application = $memorandum->application;
        $this->authorizeShmOwnership($request, $application);

        return Inertia::render('Shm/Memorandum/Edit', [
            'memorandum' => [
                'id' => $memorandum->id,
                'type' => $memorandum->type,
                'status' => $memorandum->status,
                'notes' => $memorandum->notes,
                'shm_application_id' => $memorandum->shm_application_id,
                'application_number' => $application->application_number,
                'application_status_label' => $application->status->label(),
            ],
            'types' => collect(ShmMemorandum::typeLabels())
                ->map(fn (string $label, string $value): array => [
                    'value' => $value,
                    'label' => $label,
                ])->values()->all(),
            'statuses' => collect(ShmMemorandum::statusLabels())
                ->map(fn (string $label, string $value): array => [
                    'value' => $value,
                    'label' => $label,
                ])->values()->all(),
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
        ]);
    }

    /**
     * Simpan perubahan memorandum. Seluruh tulisan lewat service sehingga
     * audit tetap SATU event per aksi (shm.memorandum.updated) — controller
     * tidak menulis audit kedua.
     */
    public function update(UpdateShmMemorandumRequest $request, ShmMemorandum $memorandum): RedirectResponse
    {
        $application = $this->scopedApplicationQuery()->findOrFail($memorandum->shm_application_id);

        // Perubahan memorandum hanya pada tahap Memorandum/Approval, sama
        // seperti pencatatan pertama.
        $this->workflow->guardStage(
            $application,
            [ShmApplicationStatus::Memorandum, ShmApplicationStatus::Approval],
            'Perubahan memorandum',
        );

        $this->workflow->recordMemorandum(
            $application,
            $request->statusValue(),
            $request->notesValue(),
            $request->user()->id,
            $request->typeValue(),
        );

        return to_route('shm.memorandums.show', $memorandum->id)
            ->with('success', 'Memorandum berhasil diperbarui.');
    }

    /**
     * Baris ringkas untuk tabel daftar.
     *
     * @return array<string, mixed>
     */
    private function summaryRow(ShmMemorandum $memorandum): array
    {
        $application = $memorandum->application;

        return [
            'id' => $memorandum->id,
            // Belum ada nomor dokumen resmi: referensi teknis adalah nomor
            // pengajuan, bukan nomor memorandum yang dikarang.
            'reference' => $application?->application_number,
            'application_id' => $application?->id,
            'customer_name' => $application?->customer?->name,
            'customer_number' => $application?->customer?->customer_number,
            'application_status_label' => $application?->status?->label(),
            'type' => $memorandum->type,
            'type_label' => $memorandum->typeLabel(),
            'status' => $memorandum->status,
            'status_label' => $memorandum->statusLabel(),
            'creator' => $memorandum->creator?->name,
            'created_at' => $memorandum->created_at,
            'updated_at' => $memorandum->updated_at,
        ];
    }

    /**
     * Konteks ringkas satu pengajuan untuk panel samping detail/create.
     *
     * @return array<string, mixed>
     */
    private function applicationContext(ShmApplication $application): array
    {
        return [
            'id' => $application->id,
            'application_number' => $application->application_number,
            'status' => $application->status->value,
            'status_label' => $application->status->label(),
            'customer' => $application->customer === null ? null : [
                'id' => $application->customer->id,
                'customer_number' => $application->customer->customer_number,
                'name' => $application->customer->name,
                'nik' => $application->customer->nik,
                'phone' => $application->customer->phone,
            ],
            'marketing' => $application->marketing?->name,
            'created_at' => $application->created_at,
        ];
    }

    /**
     * Konteks credit checking sebagai REFERENSI saja. Tidak ada aturan otomatis
     * (pass/fail tidak pernah mengubah memorandum).
     *
     * @param  ShmCreditCheck  $creditCheck
     * @return array<string, mixed>
     */
    private function creditCheckContext($creditCheck): array
    {
        return [
            'id' => $creditCheck->id,
            'status' => $creditCheck->status->value,
            'status_label' => $creditCheck->statusLabel(),
            'status_badge' => $creditCheck->status->badgeKey(),
            'outcome' => $creditCheck->outcome?->value,
            'outcome_label' => $creditCheck->outcome?->label(),
            'outcome_badge' => $creditCheck->outcome?->badgeKey(),
            'checker' => $creditCheck->checker?->name,
            'completed_at' => $creditCheck->checked_at,
            'result' => $creditCheck->result,
        ];
    }

    /**
     * Query pengajuan yang boleh dilihat pengguna ini.
     */
    private function scopedApplicationQuery(): Builder
    {
        $query = ShmApplication::query()->with(['customer:id,customer_number,name']);

        if (! $this->canViewAllShm()) {
            $query->where('marketing_id', auth()->id());
        }

        return $query;
    }

    /**
     * Terapkan batasan cakupan pada query relasi hasMany/whereHas.
     */
    private function scopeApplication(Builder $query): void
    {
        if (! $this->canViewAllShm()) {
            $query->where('marketing_id', auth()->id());
        }
    }

    /**
     * Pemeriksaan permission sederhana yang konsisten dengan pola middleware.
     */
    private function authorizeAction(string $permission): void
    {
        abort_unless(Gate::allows($permission), 403, 'Anda tidak memiliki hak akses untuk modul memorandum.');
    }
}
