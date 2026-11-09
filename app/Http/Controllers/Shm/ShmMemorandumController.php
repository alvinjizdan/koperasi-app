<?php

namespace App\Http\Controllers\Shm;

use App\Enums\ShmApplicationStatus;
use App\Http\Controllers\Concerns\AuthorizesShmOwnership;
use App\Http\Controllers\Concerns\BuildsAuditHistory;
use App\Http\Controllers\Concerns\InteractsWithSorting;
use App\Http\Controllers\Controller;
use App\Http\Requests\Shm\ActionShmMemorandumRequest;
use App\Http\Requests\Shm\RejectShmMemorandumRequest;
use App\Http\Requests\Shm\StoreShmBuybackMemorandumRequest;
use App\Http\Requests\Shm\StoreShmShgbMemorandumRequest;
use App\Http\Requests\Shm\UpdateShmBuybackMemorandumRequest;
use App\Http\Requests\Shm\UpdateShmMemorandumRequest;
use App\Http\Requests\Shm\UpdateShmShgbMemorandumRequest;
use App\Http\Requests\Shm\UploadShmBuybackDocumentRequest;
use App\Models\ShmApplication;
use App\Models\ShmBuybackDocument;
use App\Models\ShmCreditCheck;
use App\Models\ShmCustomer;
use App\Models\ShmMemorandum;
use App\Models\ShmMemorandumApprovalHistory;
use App\Services\Shm\ShmMemorandumApprovalService;
use App\Services\ShmWorkflowService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

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
        private readonly ShmMemorandumApprovalService $approvalService,
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
     * Halaman khusus Submenu Memorandum SHM/SHGB.
     */
    public function shmShgbIndex(Request $request): Response
    {
        $this->authorizeAction('shm.memorandum.update');

        $filters = [
            'search' => $request->input('search'),
            'status' => $request->input('status'),
        ];

        $query = ShmMemorandum::query()
            ->where('type', ShmMemorandum::TYPE_SHM_SHGB)
            ->whereHas('application', fn (Builder $q) => $this->scopeApplication($q))
            ->with([
                'application:id,application_number,shm_customer_id,marketing_id,status',
                'application.customer:id,customer_number,name',
                'creator:id,name',
            ])
            ->when($filters['status'], fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($filters['search'], function (Builder $q, string $search): void {
                $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%';
                $q->where(function (Builder $inner) use ($term): void {
                    $inner->where('notes', 'like', $term)
                        ->orWhereHas('application', function (Builder $app) use ($term): void {
                            $app->where('application_number', 'like', $term)
                                ->orWhereHas('customer', function (Builder $customer) use ($term): void {
                                    $customer->where('name', 'like', $term)
                                        ->orWhere('customer_number', 'like', $term);
                                });
                        });
                });
            });

        $sortStatus = $this->applySorting($query, $request, [
            'status' => 'status',
            'created_at' => 'created_at',
            'updated_at' => 'updated_at',
        ], [['created_at', 'desc']]);

        $items = $query->paginate($this->resolvePerPage($request))
            ->withQueryString()
            ->through(fn (ShmMemorandum $m): array => [
                'id' => $m->id,
                'application_number' => $m->application?->application_number ?? '-',
                'customer_name' => $m->application?->customer?->name ?? '-',
                'customer_number' => $m->application?->customer?->customer_number ?? '-',
                'status' => $m->status,
                'status_label' => $m->statusLabel(),
                'creator_name' => $m->creator?->name,
                'created_at' => $m->created_at,
                'updated_at' => $m->updated_at,
            ]);

        return Inertia::render('Shm/Memorandum/ShmShgbIndex', [
            'items' => $items,
            'sort' => $sortStatus,
            'filters' => $filters,
            'statuses' => ShmMemorandum::statusLabels(),
            'can_create' => Gate::allows('shm.memorandum.update'),
            'can_edit' => Gate::allows('shm.memorandum.update'),
            'can_delete' => Gate::allows('shm.memorandum.update'),
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
        ]);
    }

    /**
     * Form pembuatan memorandum khusus SHM/SHGB (Ticket 2).
     *
     * Hanya menampilkan pengajuan yang berstatus Completed dan belum memiliki record SHM/SHGB.
     */
    public function shmShgbCreate(Request $request): Response
    {
        $this->authorizeAction('shm.memorandum.update');

        $selectedApplication = null;

        if ($applicationId = $request->input('application_id')) {
            $application = $this->scopedApplicationQuery()
                ->where('type', ShmApplication::TYPE_SHGB)
                ->where('status', ShmApplicationStatus::Completed)
                ->whereDoesntHave('memorandums', fn (Builder $q) => $q->where('type', ShmMemorandum::TYPE_SHM_SHGB))
                ->with(['properties', 'customer', 'latestCreditCheck.checker:id,name'])
                ->find((int) $applicationId);

            if ($application) {
                $selectedApplication = $this->applicationContext($application);
                $selectedApplication['properties'] = $application->properties;
                $selectedApplication['shgb_start_date'] = $application->shgb_start_date?->toDateString();
                $selectedApplication['shgb_end_date'] = $application->shgb_end_date?->toDateString();
                if ($application->latestCreditCheck !== null) {
                    $selectedApplication['credit_check'] = $this->creditCheckContext($application->latestCreditCheck);
                }
            }
        }

        // Hanya pengajuan SHGB Completed yang belum pernah dikonversi ke SHM/SHGB
        $eligibleApplications = $this->scopedApplicationQuery()
            ->where('type', ShmApplication::TYPE_SHGB)
            ->where('status', ShmApplicationStatus::Completed)
            ->whereDoesntHave('memorandums', fn (Builder $q) => $q->where('type', ShmMemorandum::TYPE_SHM_SHGB))
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get(['id', 'application_number', 'shm_customer_id', 'status', 'created_at', 'completed_at'])
            ->map(fn (ShmApplication $app): array => [
                'id' => $app->id,
                'application_number' => $app->application_number,
                'status' => $app->status->value,
                'status_label' => $app->status->label(),
                'customer_label' => $app->customerLabel(),
            ])->all();

        return Inertia::render('Shm/Memorandum/ShmShgbCreate', [
            'applications' => $eligibleApplications,
            'selected_application' => $selectedApplication,
            'statuses' => [
                ['value' => ShmMemorandum::STATUS_DRAFT, 'label' => ShmMemorandum::statusLabels()[ShmMemorandum::STATUS_DRAFT]],
                ['value' => ShmMemorandum::STATUS_SUBMITTED, 'label' => ShmMemorandum::statusLabels()[ShmMemorandum::STATUS_SUBMITTED]],
            ],
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
        ]);
    }

    /**
     * Simpan record Memorandum SHM/SHGB baru (Ticket 2).
     */
    public function shmShgbStore(StoreShmShgbMemorandumRequest $request): RedirectResponse
    {
        $application = $this->scopedApplicationQuery()->findOrFail($request->applicationIdValue());

        // Pastikan kembali eligibility: hanya Completed
        abort_unless(
            $application->status === ShmApplicationStatus::Completed,
            422,
            'Hanya pengajuan dengan status Selesai yang dapat dikonversi ke SHM/SHGB.',
        );

        // Buat record ShmMemorandum bertipe shm_shgb
        // Sumber kebenaran tanggal masa berlaku SHGB berasal dari ShmApplication (dengan fallback input request jika ada)
        $startDate = $application->shgb_start_date ?? $request->shgbStartDateValue();
        $endDate = $application->shgb_end_date ?? $request->shgbEndDateValue();

        $memorandum = new ShmMemorandum([
            'type' => ShmMemorandum::TYPE_SHM_SHGB,
            'shm_application_id' => $application->id,
            'created_by' => $request->user()->id,
            'status' => $request->statusValue(),
            'notes' => $request->notesValue(),
            'shgb_start_date' => $startDate,
            'shgb_end_date' => $endDate,
            'submitted_at' => $request->statusValue() === ShmMemorandum::STATUS_SUBMITTED ? now() : null,
        ]);
        $memorandum->save();

        return to_route('shm.memorandums.shm-shgb.index')
            ->with('success', 'Data SHM/SHGB berhasil dibuat.');
    }

    /**
     * Detail Memorandum SHM/SHGB (Ticket 2).
     */
    public function shmShgbShow(Request $request, ShmMemorandum $memorandum): Response
    {
        $this->authorizeAction('shm.memorandum.update');
        abort_unless($memorandum->type === ShmMemorandum::TYPE_SHM_SHGB, 404);

        $application = $memorandum->application;
        abort_unless($application !== null, 404, 'Pengajuan asal tidak ditemukan.');
        $this->authorizeShmOwnership($request, $application);

        $application->load([
            'customer:id,customer_number,name,nik,phone,email,address',
            'marketing:id,name',
            'properties',
            'latestCreditCheck.checker:id,name',
        ]);

        $creditCheck = $application->latestCreditCheck;

        // Muat data aktor dan riwayat persetujuan (Ticket 8)
        $memorandum->load([
            'submitter:id,name',
            'reviewer:id,name',
            'approver:id,name',
            'approvalHistories.performer:id,name',
        ]);

        $approvalHistories = $memorandum->approvalHistories->map(fn ($h) => [
            'id' => $h->id,
            'action' => $h->action,
            'action_label' => $h->actionLabel(),
            'from_status' => $h->from_status,
            'to_status' => $h->to_status,
            'performed_by_name' => $h->performer?->name ?? 'Sistem',
            'notes' => $h->notes,
            'created_at' => $h->created_at,
        ])->all();

        $hasBeenRejected = $memorandum->approvalHistories->contains('action', ShmMemorandumApprovalHistory::ACTION_REJECTED);

        return Inertia::render('Shm/Memorandum/ShmShgbShow', [
            'memorandum' => [
                'id' => $memorandum->id,
                'type' => $memorandum->type,
                'type_label' => $memorandum->typeLabel(),
                'status' => $memorandum->status,
                'status_label' => $memorandum->statusLabel(),
                'notes' => $memorandum->notes,
                'shgb_start_date' => $memorandum->shgb_start_date?->toDateString(),
                'shgb_end_date' => $memorandum->shgb_end_date?->toDateString(),
                'submitted_at' => $memorandum->submitted_at,
                'submitted_by_name' => $memorandum->submitter?->name,
                'reviewed_at' => $memorandum->reviewed_at,
                'reviewed_by_name' => $memorandum->reviewer?->name,
                'approved_at' => $memorandum->approved_at,
                'approved_by_name' => $memorandum->approver?->name,
                'rejected_at' => $memorandum->rejected_at,
                'rejection_notes' => $memorandum->rejection_notes,
                'has_been_rejected' => $hasBeenRejected,
                'created_at' => $memorandum->created_at,
                'updated_at' => $memorandum->updated_at,
                'creator' => $memorandum->creator?->name,
            ],
            'application' => $this->applicationContext($application),
            'properties' => $application->properties,
            'credit_check' => $creditCheck === null ? null : $this->creditCheckContext($creditCheck),
            'history' => $this->auditHistory(ShmMemorandum::class, $memorandum->id),
            'approval_histories' => $approvalHistories,
            'can_edit' => Gate::allows('shm.memorandum.update') && $memorandum->isDraft(),
            'can_delete' => Gate::allows('shm.memorandum.update') && $memorandum->isDraft(),
            'can_submit' => Gate::allows('shm.memorandum.submit') && $memorandum->isDraft(),
            'can_start_review' => Gate::allows('shm.memorandum.review') && $memorandum->isSubmitted(),
            'can_approve' => Gate::allows('shm.memorandum.approve') && $memorandum->isUnderReview(),
            'can_reject' => Gate::allows('shm.memorandum.reject') && $memorandum->isUnderReview(),
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
        ]);
    }

    /**
     * Form edit Memorandum SHM/SHGB (Ticket 2).
     */
    public function shmShgbEdit(Request $request, ShmMemorandum $memorandum): Response|RedirectResponse
    {
        $this->authorizeAction('shm.memorandum.update');
        abort_unless($memorandum->type === ShmMemorandum::TYPE_SHM_SHGB, 404);

        $application = $memorandum->application;
        abort_unless($application !== null, 404, 'Pengajuan asal tidak ditemukan.');
        $this->authorizeShmOwnership($request, $application);

        // Kunci pengeditan memorandum jika sudah masuk approval (submitted, under_review, approved)
        if (in_array($memorandum->status, [ShmMemorandum::STATUS_SUBMITTED, ShmMemorandum::STATUS_UNDER_REVIEW, ShmMemorandum::STATUS_APPROVED], true)) {
            return to_route('shm.memorandums.shm-shgb.show', $memorandum->id)
                ->with('error', 'Memorandum sedang dalam proses review atau telah disetujui sehingga tidak dapat diedit.');
        }

        $application->load([
            'customer:id,customer_number,name,nik,phone',
            'properties',
        ]);

        return Inertia::render('Shm/Memorandum/ShmShgbEdit', [
            'memorandum' => [
                'id' => $memorandum->id,
                'type' => $memorandum->type,
                'type_label' => $memorandum->typeLabel(),
                'status' => $memorandum->status,
                'notes' => $memorandum->notes,
                'shgb_start_date' => $memorandum->shgb_start_date?->toDateString(),
                'shgb_end_date' => $memorandum->shgb_end_date?->toDateString(),
                'shm_application_id' => $memorandum->shm_application_id,
                'application_number' => $application->application_number,
                'application_status_label' => $application->status->label(),
                'customer_name' => $application->customer?->name,
                'customer_number' => $application->customer?->customer_number,
                'properties_count' => $application->properties->count(),
            ],
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
     * Update Memorandum SHM/SHGB (Ticket 2).
     */
    public function shmShgbUpdate(UpdateShmShgbMemorandumRequest $request, ShmMemorandum $memorandum): RedirectResponse
    {
        abort_unless($memorandum->type === ShmMemorandum::TYPE_SHM_SHGB, 404);

        $application = $memorandum->application;
        abort_unless($application !== null, 404, 'Pengajuan asal tidak ditemukan.');
        $this->authorizeShmOwnership($request, $application);

        // Immutability Guard: Hanya draft yang dapat diperbarui melalui form edit normal
        abort_if(
            in_array($memorandum->status, [ShmMemorandum::STATUS_SUBMITTED, ShmMemorandum::STATUS_UNDER_REVIEW, ShmMemorandum::STATUS_APPROVED], true),
            422,
            'Memorandum yang sedang dalam peninjauan atau telah disetujui tidak dapat diubah.'
        );

        $memorandum->status = $request->statusValue();
        $memorandum->notes = $request->notesValue();
        $memorandum->shgb_start_date = $request->shgbStartDateValue();
        $memorandum->shgb_end_date = $request->shgbEndDateValue();
        if ($memorandum->status === ShmMemorandum::STATUS_SUBMITTED && ! $memorandum->submitted_at) {
            $memorandum->submitted_at = now();
        }
        $memorandum->save();

        return to_route('shm.memorandums.shm-shgb.show', $memorandum->id)
            ->with('success', 'Data SHM/SHGB berhasil diperbarui.');
    }

    /**
     * Hapus record Memorandum SHM/SHGB (Ticket 2).
     *
     * PERINGATAN INTEGRITAS: Hanya menghapus record memorandum.
     * Record ShmApplication, ShmCustomer, dan ShmProperty tetap utuh.
     */
    public function shmShgbDestroy(Request $request, ShmMemorandum $memorandum): RedirectResponse
    {
        $this->authorizeAction('shm.memorandum.update');
        abort_unless($memorandum->type === ShmMemorandum::TYPE_SHM_SHGB, 404);

        $application = $memorandum->application;
        if ($application) {
            $this->authorizeShmOwnership($request, $application);
        }

        // Immutability Guard: Hanya memorandum berstatus Draft yang dapat dihapus
        abort_if(
            in_array($memorandum->status, [ShmMemorandum::STATUS_SUBMITTED, ShmMemorandum::STATUS_UNDER_REVIEW, ShmMemorandum::STATUS_APPROVED], true),
            422,
            'Memorandum yang sedang dalam peninjauan atau telah disetujui tidak dapat dihapus.'
        );

        // Hapus HANYA record ShmMemorandum
        $memorandum->delete();

        return to_route('shm.memorandums.shm-shgb.index')
            ->with('success', 'Data SHM/SHGB berhasil dihapus.');
    }

    /**
     * Halaman khusus Submenu Memorandum Buyback.
     */
    public function buybackIndex(Request $request): Response
    {
        $this->authorizeAction('shm.memorandum.update');

        $filters = [
            'search' => $request->input('search'),
            'status' => $request->input('status'),
        ];

        $query = ShmMemorandum::query()
            ->where('type', ShmMemorandum::TYPE_BUYBACK)
            ->whereHas('application', fn (Builder $q) => $this->scopeApplication($q))
            ->with([
                'application:id,application_number,shm_customer_id,marketing_id,status',
                'application.customer:id,customer_number,name',
                'creator:id,name',
            ])
            ->when($filters['status'], fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($filters['search'], function (Builder $q, string $search): void {
                $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%';
                $q->where(function (Builder $inner) use ($term): void {
                    $inner->where('notes', 'like', $term)
                        ->orWhereHas('application', function (Builder $app) use ($term): void {
                            $app->where('application_number', 'like', $term)
                                ->orWhereHas('customer', function (Builder $customer) use ($term): void {
                                    $customer->where('name', 'like', $term)
                                        ->orWhere('customer_number', 'like', $term);
                                });
                        });
                });
            });

        $sortStatus = $this->applySorting($query, $request, [
            'status' => 'status',
            'created_at' => 'created_at',
            'updated_at' => 'updated_at',
        ], [['created_at', 'desc']]);

        $items = $query->paginate($this->resolvePerPage($request))
            ->withQueryString()
            ->through(fn (ShmMemorandum $m): array => [
                'id' => $m->id,
                'application_number' => $m->application?->application_number ?? '-',
                'customer_name' => $m->application?->customer?->name ?? '-',
                'customer_number' => $m->application?->customer?->customer_number ?? '-',
                'status' => $m->status,
                'status_label' => $m->statusLabel(),
                'creator_name' => $m->creator?->name,
                'created_at' => $m->created_at,
                'updated_at' => $m->updated_at,
            ]);

        return Inertia::render('Shm/Memorandum/BuybackIndex', [
            'items' => $items,
            'sort' => $sortStatus,
            'filters' => $filters,
            'statuses' => ShmMemorandum::statusLabels(),
            'can_create' => Gate::allows('shm.memorandum.update'),
            'can_edit' => Gate::allows('shm.memorandum.update'),
            'can_delete' => Gate::allows('shm.memorandum.update'),
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
        ]);
    }

    /**
     * Form pembuatan memorandum khusus Buyback (Ticket 4).
     *
     * Hanya menampilkan pengajuan bertipe 'buyback' yang berstatus Completed
     * dan belum memiliki record Memorandum Buyback.
     */
    public function buybackCreate(Request $request): Response
    {
        $this->authorizeAction('shm.memorandum.update');

        $selectedApplication = null;

        if ($applicationId = $request->input('application_id')) {
            $application = $this->scopedApplicationQuery()
                ->where('type', ShmApplication::TYPE_BUYBACK)
                ->where('status', ShmApplicationStatus::Completed)
                ->whereDoesntHave('memorandums', fn (Builder $q) => $q->where('type', ShmMemorandum::TYPE_BUYBACK))
                ->with(['properties', 'customer', 'latestCreditCheck.checker:id,name', 'buybackDocuments.uploader:id,name'])
                ->find((int) $applicationId);

            if ($application) {
                $selectedApplication = $this->applicationContext($application);
                $selectedApplication['properties'] = $application->properties;
                $existingBuybackDocs = $application->buybackDocuments->keyBy('document_type');
                $selectedApplication['buyback_documents'] = collect(ShmBuybackDocument::REQUIRED_TYPES)->map(function (string $type) use ($existingBuybackDocs): array {
                    $doc = $existingBuybackDocs->get($type);

                    return [
                        'type' => $type,
                        'label' => ShmBuybackDocument::TYPE_LABELS[$type] ?? $type,
                        'is_uploaded' => $doc !== null,
                        'document' => $doc === null ? null : [
                            'id' => $doc->id,
                            'file_name' => $doc->file_name,
                            'file_size' => $doc->file_size,
                            'mime_type' => $doc->mime_type,
                            'uploaded_at' => $doc->created_at,
                            'uploader_name' => $doc->uploader?->name,
                        ],
                    ];
                })->values()->all();
                $uploadedBuybackCount = $existingBuybackDocs->whereIn('document_type', ShmBuybackDocument::REQUIRED_TYPES)->count();
                $selectedApplication['is_buyback_documents_complete'] = $uploadedBuybackCount === count(ShmBuybackDocument::REQUIRED_TYPES);
                $selectedApplication['buyback_documents_ratio'] = "{$uploadedBuybackCount}/".count(ShmBuybackDocument::REQUIRED_TYPES);

                if ($application->latestCreditCheck !== null) {
                    $selectedApplication['credit_check'] = $this->creditCheckContext($application->latestCreditCheck);
                }
            }
        }

        // Hanya pengajuan Buyback Completed yang belum pernah dikonversi ke Buyback
        $eligibleApplications = $this->scopedApplicationQuery()
            ->where('type', ShmApplication::TYPE_BUYBACK)
            ->where('status', ShmApplicationStatus::Completed)
            ->whereDoesntHave('memorandums', fn (Builder $q) => $q->where('type', ShmMemorandum::TYPE_BUYBACK))
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get(['id', 'application_number', 'shm_customer_id', 'status', 'created_at', 'completed_at'])
            ->map(fn (ShmApplication $app): array => [
                'id' => $app->id,
                'application_number' => $app->application_number,
                'status' => $app->status->value,
                'status_label' => $app->status->label(),
                'customer_label' => $app->customerLabel(),
            ])->all();

        return Inertia::render('Shm/Memorandum/BuybackCreate', [
            'applications' => $eligibleApplications,
            'selected_application' => $selectedApplication,
            'statuses' => [
                ['value' => ShmMemorandum::STATUS_DRAFT, 'label' => ShmMemorandum::statusLabels()[ShmMemorandum::STATUS_DRAFT]],
                ['value' => ShmMemorandum::STATUS_SUBMITTED, 'label' => ShmMemorandum::statusLabels()[ShmMemorandum::STATUS_SUBMITTED]],
            ],
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
        ]);
    }

    /**
     * Simpan record Memorandum Buyback baru (Ticket 4).
     */
    public function buybackStore(StoreShmBuybackMemorandumRequest $request): RedirectResponse
    {
        $application = $this->scopedApplicationQuery()->findOrFail($request->applicationIdValue());

        // Pastikan kembali eligibility: hanya Completed & type = buyback
        abort_unless(
            $application->status === ShmApplicationStatus::Completed && ($application->type ?? ShmApplication::TYPE_SHGB) === ShmApplication::TYPE_BUYBACK,
            422,
            'Hanya pengajuan Buyback dengan status Selesai yang dapat dikonversi ke Buyback.',
        );

        // Buat record ShmMemorandum bertipe buyback
        $memorandum = new ShmMemorandum([
            'type' => ShmMemorandum::TYPE_BUYBACK,
            'shm_application_id' => $application->id,
            'created_by' => $request->user()->id,
            'status' => $request->statusValue(),
            'notes' => $request->notesValue(),
            'submitted_at' => $request->statusValue() === ShmMemorandum::STATUS_SUBMITTED ? now() : null,
        ]);
        $memorandum->save();

        return to_route('shm.memorandums.buyback.index')
            ->with('success', 'Data Memorandum Buyback berhasil dibuat.');
    }

    /**
     * Detail Memorandum Buyback (Ticket 4).
     */
    public function buybackShow(Request $request, ShmMemorandum $memorandum): Response
    {
        $this->authorizeAction('shm.memorandum.update');
        abort_unless($memorandum->type === ShmMemorandum::TYPE_BUYBACK, 404);

        $application = $memorandum->application;
        abort_unless($application !== null, 404, 'Pengajuan asal tidak ditemukan.');
        $this->authorizeShmOwnership($request, $application);

        $application->load([
            'customer:id,customer_number,name,nik,phone,email,address',
            'marketing:id,name',
            'properties',
            'latestCreditCheck.checker:id,name',
        ]);

        $creditCheck = $application->latestCreditCheck;

        // Muat 5 dokumen legalitas buyback (Ticket 7 & 14) dan aktor approval (Ticket 8)
        // Dokumen bersumber dari Application, dengan fallback ke Memorandum bila legacy
        $application->load(['buybackDocuments.uploader:id,name']);
        $memorandum->load([
            'buybackDocuments.uploader:id,name',
            'submitter:id,name',
            'reviewer:id,name',
            'approver:id,name',
            'approvalHistories.performer:id,name',
        ]);
        $existingDocuments = $application->buybackDocuments->isNotEmpty()
            ? $application->buybackDocuments->keyBy('document_type')
            : $memorandum->buybackDocuments->keyBy('document_type');

        $documentsList = collect(ShmBuybackDocument::REQUIRED_TYPES)->map(function (string $type) use ($existingDocuments): array {
            $doc = $existingDocuments->get($type);

            return [
                'type' => $type,
                'label' => ShmBuybackDocument::TYPE_LABELS[$type] ?? $type,
                'is_uploaded' => $doc !== null,
                'document' => $doc === null ? null : [
                    'id' => $doc->id,
                    'file_name' => $doc->file_name,
                    'file_size' => $doc->file_size,
                    'mime_type' => $doc->mime_type,
                    'uploaded_at' => $doc->created_at,
                    'uploader_name' => $doc->uploader?->name,
                ],
            ];
        })->values()->all();

        $uploadedCount = $existingDocuments->whereIn('document_type', ShmBuybackDocument::REQUIRED_TYPES)->count();
        $isComplete = $uploadedCount === count(ShmBuybackDocument::REQUIRED_TYPES);

        $approvalHistories = $memorandum->approvalHistories->map(fn ($h) => [
            'id' => $h->id,
            'action' => $h->action,
            'action_label' => $h->actionLabel(),
            'from_status' => $h->from_status,
            'to_status' => $h->to_status,
            'performed_by_name' => $h->performer?->name ?? 'Sistem',
            'notes' => $h->notes,
            'created_at' => $h->created_at,
        ])->all();

        $hasBeenRejected = $memorandum->approvalHistories->contains('action', ShmMemorandumApprovalHistory::ACTION_REJECTED);

        return Inertia::render('Shm/Memorandum/BuybackShow', [
            'memorandum' => [
                'id' => $memorandum->id,
                'type' => $memorandum->type,
                'type_label' => $memorandum->typeLabel(),
                'status' => $memorandum->status,
                'status_label' => $memorandum->statusLabel(),
                'notes' => $memorandum->notes,
                'submitted_at' => $memorandum->submitted_at,
                'submitted_by_name' => $memorandum->submitter?->name,
                'reviewed_at' => $memorandum->reviewed_at,
                'reviewed_by_name' => $memorandum->reviewer?->name,
                'approved_at' => $memorandum->approved_at,
                'approved_by_name' => $memorandum->approver?->name,
                'rejected_at' => $memorandum->rejected_at,
                'rejection_notes' => $memorandum->rejection_notes,
                'has_been_rejected' => $hasBeenRejected,
                'created_at' => $memorandum->created_at,
                'updated_at' => $memorandum->updated_at,
                'creator' => $memorandum->creator?->name,
                'documents_count' => $uploadedCount,
                'documents_ratio' => "{$uploadedCount}/".count(ShmBuybackDocument::REQUIRED_TYPES),
                'is_documents_complete' => $isComplete,
            ],
            'documents' => $documentsList,
            'application' => $this->applicationContext($application),
            'properties' => $application->properties,
            'credit_check' => $creditCheck === null ? null : $this->creditCheckContext($creditCheck),
            'history' => $this->auditHistory(ShmMemorandum::class, $memorandum->id),
            'approval_histories' => $approvalHistories,
            'can_edit' => Gate::allows('shm.memorandum.update') && $memorandum->isDraft(),
            'can_delete' => Gate::allows('shm.memorandum.update') && $memorandum->isDraft(),
            'can_submit' => Gate::allows('shm.memorandum.submit') && $memorandum->isDraft(),
            'can_start_review' => Gate::allows('shm.memorandum.review') && $memorandum->isSubmitted(),
            'can_approve' => Gate::allows('shm.memorandum.approve') && $memorandum->isUnderReview(),
            'can_reject' => Gate::allows('shm.memorandum.reject') && $memorandum->isUnderReview(),
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
        ]);
    }

    /**
     * Form edit Memorandum Buyback (Ticket 4).
     */
    public function buybackEdit(Request $request, ShmMemorandum $memorandum): Response|RedirectResponse
    {
        $this->authorizeAction('shm.memorandum.update');
        abort_unless($memorandum->type === ShmMemorandum::TYPE_BUYBACK, 404);

        $application = $memorandum->application;
        abort_unless($application !== null, 404, 'Pengajuan asal tidak ditemukan.');
        $this->authorizeShmOwnership($request, $application);

        // Kunci pengeditan memorandum jika sudah masuk approval (submitted, under_review, approved)
        if (in_array($memorandum->status, [ShmMemorandum::STATUS_SUBMITTED, ShmMemorandum::STATUS_UNDER_REVIEW, ShmMemorandum::STATUS_APPROVED], true)) {
            return to_route('shm.memorandums.buyback.show', $memorandum->id)
                ->with('error', 'Memorandum sedang dalam proses review atau telah disetujui sehingga tidak dapat diedit.');
        }

        $application->load([
            'customer:id,customer_number,name,nik,phone',
            'properties',
        ]);

        // Muat 5 dokumen legalitas buyback (Ticket 7 & 14)
        $application->load(['buybackDocuments.uploader:id,name']);
        $memorandum->load(['buybackDocuments.uploader:id,name']);
        $existingDocuments = $application->buybackDocuments->isNotEmpty()
            ? $application->buybackDocuments->keyBy('document_type')
            : $memorandum->buybackDocuments->keyBy('document_type');

        $documentsList = collect(ShmBuybackDocument::REQUIRED_TYPES)->map(function (string $type) use ($existingDocuments): array {
            $doc = $existingDocuments->get($type);

            return [
                'type' => $type,
                'label' => ShmBuybackDocument::TYPE_LABELS[$type] ?? $type,
                'is_uploaded' => $doc !== null,
                'document' => $doc === null ? null : [
                    'id' => $doc->id,
                    'file_name' => $doc->file_name,
                    'file_size' => $doc->file_size,
                    'mime_type' => $doc->mime_type,
                    'uploaded_at' => $doc->created_at,
                    'uploader_name' => $doc->uploader?->name,
                ],
            ];
        })->values()->all();

        $uploadedCount = $existingDocuments->whereIn('document_type', ShmBuybackDocument::REQUIRED_TYPES)->count();
        $isComplete = $uploadedCount === count(ShmBuybackDocument::REQUIRED_TYPES);

        return Inertia::render('Shm/Memorandum/BuybackEdit', [
            'memorandum' => [
                'id' => $memorandum->id,
                'type' => $memorandum->type,
                'type_label' => $memorandum->typeLabel(),
                'status' => $memorandum->status,
                'notes' => $memorandum->notes,
                'shm_application_id' => $memorandum->shm_application_id,
                'application_number' => $application->application_number,
                'application_status_label' => $application->status->label(),
                'customer_name' => $application->customer?->name,
                'customer_number' => $application->customer?->customer_number,
                'properties_count' => $application->properties->count(),
                'documents_count' => $uploadedCount,
                'documents_ratio' => "{$uploadedCount}/".count(ShmBuybackDocument::REQUIRED_TYPES),
                'is_documents_complete' => $isComplete,
            ],
            'documents' => $documentsList,
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
     * Update Memorandum Buyback (Ticket 4 & 7).
     */
    public function buybackUpdate(UpdateShmBuybackMemorandumRequest $request, ShmMemorandum $memorandum): RedirectResponse
    {
        abort_unless($memorandum->type === ShmMemorandum::TYPE_BUYBACK, 404);

        $application = $memorandum->application;
        abort_unless($application !== null, 404, 'Pengajuan asal tidak ditemukan.');
        $this->authorizeShmOwnership($request, $application);

        // Immutability Guard: Hanya draft yang dapat diperbarui melalui form edit normal
        abort_if(
            in_array($memorandum->status, [ShmMemorandum::STATUS_SUBMITTED, ShmMemorandum::STATUS_UNDER_REVIEW, ShmMemorandum::STATUS_APPROVED], true),
            422,
            'Memorandum Buyback yang sedang dalam peninjauan atau telah disetujui tidak dapat diubah.'
        );

        $targetStatus = $request->statusValue();

        // VALIDASI KELENGKAPAN 5 DOKUMEN SEBELUM STATUS SUBMITTED (Ticket 7)
        if ($targetStatus === ShmMemorandum::STATUS_SUBMITTED) {
            if (! $memorandum->isBuybackDocumentsComplete()) {
                return back()->with('error', 'Status tidak dapat diubah menjadi Diajukan (Submitted) karena kelima dokumen legalitas Buyback belum lengkap.');
            }
        }

        $memorandum->status = $targetStatus;
        $memorandum->notes = $request->notesValue();
        if ($memorandum->status === ShmMemorandum::STATUS_SUBMITTED && ! $memorandum->submitted_at) {
            $memorandum->submitted_at = now();
        }
        $memorandum->save();

        return to_route('shm.memorandums.buyback.show', $memorandum->id)
            ->with('success', 'Data Memorandum Buyback berhasil diperbarui.');
    }

    /**
     * Upload Dokumen Legalitas Buyback (Ticket 7).
     *
     * Mekanisme penggantian aman: File lama tidak dihapus sebelum file baru
     * berhasil disimpan ke disk privat dan database diperbarui dalam DB transaction.
     */
    public function buybackUploadDocument(
        UploadShmBuybackDocumentRequest $request,
        ShmMemorandum $memorandum,
    ): RedirectResponse {
        $this->authorizeAction('shm.memorandum.update');
        abort_unless($memorandum->type === ShmMemorandum::TYPE_BUYBACK, 404);

        $application = $memorandum->application;
        abort_unless($application !== null, 404, 'Pengajuan asal tidak ditemukan.');
        $this->authorizeShmOwnership($request, $application);

        $docType = $request->documentTypeValue();
        $file = $request->file('file');

        // Immutability Guard: Dokumen tidak dapat diunggah / diganti jika memorandum telah disetujui
        abort_if(
            $memorandum->status === ShmMemorandum::STATUS_APPROVED,
            422,
            'Dokumen tidak dapat diunggah atau diganti pada memorandum yang telah disetujui.'
        );

        // Simpan file baru ke disk 'local' di direktori privat
        $storedPath = $file->store("shm/memorandums/{$memorandum->id}/buyback-documents", 'local');
        if (! $storedPath) {
            return back()->with('error', 'Gagal menyimpan berkas dokumen legalitas.');
        }

        $oldFilePathToDelete = null;
        $isExisting = false;

        try {
            DB::transaction(function () use ($memorandum, $docType, $file, $storedPath, $request, &$oldFilePathToDelete, &$isExisting): void {
                $existingDoc = $memorandum->buybackDocuments()->where('document_type', $docType)->lockForUpdate()->first();

                if ($existingDoc) {
                    $isExisting = true;
                    $oldFilePathToDelete = $existingDoc->file_path;
                    $existingDoc->update([
                        'file_name' => $file->getClientOriginalName(),
                        'file_path' => $storedPath,
                        'mime_type' => 'application/pdf',
                        'file_size' => $file->getSize(),
                        'uploaded_by' => $request->user()->id,
                    ]);
                } else {
                    $memorandum->buybackDocuments()->create([
                        'document_type' => $docType,
                        'file_name' => $file->getClientOriginalName(),
                        'file_path' => $storedPath,
                        'mime_type' => 'application/pdf',
                        'file_size' => $file->getSize(),
                        'uploaded_by' => $request->user()->id,
                    ]);
                }
            });

            // Hapus berkas lama hanya setelah transaksi database berhasil
            if ($oldFilePathToDelete && Storage::disk('local')->exists($oldFilePathToDelete)) {
                Storage::disk('local')->delete($oldFilePathToDelete);
            }
        } catch (\Throwable $e) {
            // Kompensasi jika database gagal: bersihkan file baru yang sudah terlanjur tersimpan di disk
            if (Storage::disk('local')->exists($storedPath)) {
                Storage::disk('local')->delete($storedPath);
            }

            report($e);

            return back()->with('error', 'Terjadi kesalahan sistem saat memperbarui dokumen legalitas.');
        }

        return back()->with('success', 'Dokumen legalitas '.($isExisting ? 'berhasil diperbarui' : 'berhasil diunggah').'.');
    }

    /**
     * Download/Preview Dokumen Legalitas Buyback (Ticket 7).
     */
    public function buybackDownloadDocument(
        Request $request,
        int $memorandumId,
        int $documentId,
    ): SymfonyResponse {
        $this->authorizeAction('shm.memorandum.update');
        $memorandum = ShmMemorandum::find($memorandumId);
        abort_unless($memorandum !== null, 404, 'Memorandum not found: '.$memorandumId);
        abort_unless($memorandum->type === ShmMemorandum::TYPE_BUYBACK, 404, 'Type not buyback');

        $document = ShmBuybackDocument::where(function (Builder $q) use ($memorandum): void {
            $q->where('shm_memorandum_id', $memorandum->id)
                ->orWhere('shm_application_id', $memorandum->shm_application_id);
        })
            ->where('id', $documentId)
            ->first();
        abort_unless($document !== null, 404, 'Document not found: '.$documentId);

        $application = $memorandum->application;
        abort_unless($application !== null, 404, 'Pengajuan asal tidak ditemukan.');
        $this->authorizeShmOwnership($request, $application);

        $disk = Storage::disk('local');
        abort_unless($disk->exists($document->file_path), 404, 'File path missing: '.$document->file_path);

        $contents = $disk->get($document->file_path);
        abort_unless(is_string($contents) && $contents !== '', 404, 'File contents empty');

        $filename = $document->file_name ?: ('dokumen-'.$document->document_type.'.pdf');

        return response($contents, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    /**
     * Hapus Dokumen Legalitas Buyback (Ticket 7).
     *
     * Jika memorandum berstatus Submitted, penghapusan dokumen yang menyebabkan
     * dokumen menjadi tidak lengkap dilarang.
     */
    public function buybackDestroyDocument(
        Request $request,
        ShmMemorandum $memorandum,
        ShmBuybackDocument $document,
    ): RedirectResponse {
        $this->authorizeAction('shm.memorandum.update');
        abort_unless($memorandum->type === ShmMemorandum::TYPE_BUYBACK, 404);
        abort_unless($document->shm_memorandum_id === $memorandum->id, 404);

        $application = $memorandum->application;
        abort_unless($application !== null, 404, 'Pengajuan asal tidak ditemukan.');
        $this->authorizeShmOwnership($request, $application);

        // Jika sudah Submitted, Under Review, atau Approved, dilarang menghapus dokumen karena dokumen wajib lengkap / terkunci
        if (in_array($memorandum->status, [ShmMemorandum::STATUS_SUBMITTED, ShmMemorandum::STATUS_UNDER_REVIEW, ShmMemorandum::STATUS_APPROVED], true)) {
            return back()->with('error', 'Dokumen tidak dapat dihapus karena Memorandum Buyback telah diajukan atau disetujui. Gunakan fitur ganti dokumen jika masih dalam perbaikan.');
        }

        $filePath = $document->file_path;
        $document->delete();

        if ($filePath && Storage::disk('local')->exists($filePath)) {
            Storage::disk('local')->delete($filePath);
        }

        return back()->with('success', 'Dokumen legalitas berhasil dihapus.');
    }

    /**
     * Hapus record Memorandum Buyback (Ticket 4 & 7).
     *
     * PERINGATAN INTEGRITAS: Hanya menghapus record memorandum dan berkas dokumen Buyback.
     * Record ShmApplication, ShmCustomer, dan ShmProperty tetap utuh.
     */
    public function buybackDestroy(Request $request, ShmMemorandum $memorandum): RedirectResponse
    {
        $this->authorizeAction('shm.memorandum.update');
        abort_unless($memorandum->type === ShmMemorandum::TYPE_BUYBACK, 404);

        $application = $memorandum->application;
        if ($application) {
            $this->authorizeShmOwnership($request, $application);
        }

        // Immutability Guard: Hanya memorandum berstatus Draft yang dapat dihapus
        abort_if(
            in_array($memorandum->status, [ShmMemorandum::STATUS_SUBMITTED, ShmMemorandum::STATUS_UNDER_REVIEW, ShmMemorandum::STATUS_APPROVED], true),
            422,
            'Memorandum Buyback yang sedang dalam peninjauan atau telah disetujui tidak dapat dihapus.'
        );

        // Bersihkan berkas-berkas fisik dokumen buyback dari storage
        $memorandum->load('buybackDocuments');
        foreach ($memorandum->buybackDocuments as $doc) {
            if ($doc->file_path && Storage::disk('local')->exists($doc->file_path)) {
                Storage::disk('local')->delete($doc->file_path);
            }
        }

        // Hapus HANYA record ShmMemorandum (cascadeOnDelete akan menghapus record di shm_buyback_documents)
        $memorandum->delete();

        return to_route('shm.memorandums.buyback.index')
            ->with('success', 'Data Memorandum Buyback berhasil dihapus.');
    }

    /**
     * Halaman khusus Submenu Summary Memorandum (Ticket 5).
     *
     * Menampilkan integrasi data seluruh Pengajuan (SHGB & Buyback) beserta
     * status memorandum terkait, metrik statistik, filter, pencarian, dan aksi.
     */
    public function summaryIndex(Request $request): Response
    {
        $this->authorizeAction('shm.memorandum.update');

        $filters = [
            'search' => $request->input('search'),
            'type' => $request->input('type'),
            'status' => $request->input('status'),
            'memorandum_status' => $request->input('memorandum_status'),
        ];

        // 1. Base Query untuk seluruh pengajuan yang boleh diakses pengguna
        $baseAppQuery = ShmApplication::query();
        if (! $this->canViewAllShm()) {
            $baseAppQuery->where('marketing_id', auth()->id());
        }

        // 2. Perhitungan Statistik (Metric Cards)
        $totalApplications = (clone $baseAppQuery)->count();
        $totalShgb = (clone $baseAppQuery)->where('type', ShmApplication::TYPE_SHGB)->count();
        $totalBuyback = (clone $baseAppQuery)->where('type', ShmApplication::TYPE_BUYBACK)->count();

        // Pengajuan yang sudah memiliki memorandum
        $withMemorandumCount = (clone $baseAppQuery)->whereHas('memorandums')->count();
        $withoutMemorandumCount = (clone $baseAppQuery)->whereDoesntHave('memorandums')->count();

        $stats = [
            'total_applications' => $totalApplications,
            'total_shgb' => $totalShgb,
            'total_buyback' => $totalBuyback,
            'with_memorandum' => $withMemorandumCount,
            'without_memorandum' => $withoutMemorandumCount,
            // Legacy aliases for backward compatibility with initial Ticket 1 submenu assertions
            'total_shm_shgb' => $totalShgb,
            'submitted_shm_shgb' => (clone $baseAppQuery)->whereHas('memorandums', fn ($q) => $q->where('type', ShmMemorandum::TYPE_SHM_SHGB)->where('status', ShmMemorandum::STATUS_SUBMITTED))->count(),
            'draft_shm_shgb' => (clone $baseAppQuery)->whereHas('memorandums', fn ($q) => $q->where('type', ShmMemorandum::TYPE_SHM_SHGB)->where('status', ShmMemorandum::STATUS_DRAFT))->count(),
            'submitted_buyback' => (clone $baseAppQuery)->whereHas('memorandums', fn ($q) => $q->where('type', ShmMemorandum::TYPE_BUYBACK)->where('status', ShmMemorandum::STATUS_SUBMITTED))->count(),
            'draft_buyback' => (clone $baseAppQuery)->whereHas('memorandums', fn ($q) => $q->where('type', ShmMemorandum::TYPE_BUYBACK)->where('status', ShmMemorandum::STATUS_DRAFT))->count(),
            'grand_total' => $totalApplications,
        ];

        // 3. Query Data Tabel Terintegrasi
        $query = (clone $baseAppQuery)->with([
            'customer:id,customer_number,name,nik',
            'memorandums' => fn ($q) => $q->select('id', 'shm_application_id', 'type', 'status', 'created_at', 'updated_at', 'submitted_at', 'rejection_notes', 'rejected_at')
                ->with([
                    'buybackDocuments:id,shm_memorandum_id,document_type',
                    'approvalHistories:id,shm_memorandum_id,action',
                ]),
        ]);

        // Filter Tipe Pengajuan: shgb / buyback
        $query->when($filters['type'], fn (Builder $q, string $type) => $q->where('type', $type));

        // Filter Status Pengajuan
        $query->when($filters['status'], fn (Builder $q, string $status) => $q->where('status', $status));

        // Filter Status Memorandum: none (Belum Dibuat), draft, submitted
        $query->when($filters['memorandum_status'], function (Builder $q, string $memoStatus): void {
            if ($memoStatus === 'none') {
                $q->whereDoesntHave('memorandums');
            } else {
                $q->whereHas('memorandums', fn (Builder $mq) => $mq->where('status', $memoStatus));
            }
        });

        // Filter Pencarian: application_number, customer name, customer NIK
        $query->when($filters['search'], function (Builder $q, string $search): void {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%';
            $q->where(function (Builder $inner) use ($term): void {
                $inner->where('application_number', 'like', $term)
                    ->orWhereHas('customer', function (Builder $cq) use ($term): void {
                        $cq->where('name', 'like', $term)
                            ->orWhere('nik', 'like', $term)
                            ->orWhere('customer_number', 'like', $term);
                    });
            });
        });

        // Sorting
        $sortStatus = $this->applySorting($query, $request, [
            'application_number' => 'application_number',
            'type' => 'type',
            'status' => 'status',
            'created_at' => 'created_at',
        ], [['created_at', 'desc']]);

        // Pagination & Transformasi Data Baris
        $items = $query->paginate($this->resolvePerPage($request))
            ->withQueryString()
            ->through(function (ShmApplication $app): array {
                $memo = $app->memorandums->first(); // 1:1 relation model
                $hasBeenRejected = $memo?->approvalHistories?->contains('action', ShmMemorandumApprovalHistory::ACTION_REJECTED) ?? false;

                return [
                    'id' => $app->id,
                    'application_number' => $app->application_number,
                    'created_at' => $app->created_at,
                    'customer_name' => $app->customer?->name ?? '-',
                    'customer_nik' => $app->customer?->nik ?? '-',
                    'customer_number' => $app->customer?->customer_number ?? '-',
                    'type' => $app->type ?? ShmApplication::TYPE_SHGB,
                    'type_label' => $app->type === ShmApplication::TYPE_BUYBACK ? 'Buyback' : 'SHM/SHGB',
                    'status' => $app->status->value,
                    'status_label' => $app->status->label(),
                    'is_editable' => $app->isEditable(),
                    'has_memorandum' => $memo !== null,
                    'memorandum' => $memo === null ? null : [
                        'id' => $memo->id,
                        'type' => $memo->type,
                        'type_label' => $memo->typeLabel(),
                        'status' => $memo->status,
                        'status_label' => $memo->statusLabel(),
                        'has_been_rejected' => $hasBeenRejected,
                        'rejection_notes' => $memo->rejection_notes,
                        'created_at' => $memo->created_at,
                        'submitted_at' => $memo->submitted_at,
                        'buyback_documents_ratio' => $memo->type === ShmMemorandum::TYPE_BUYBACK
                            ? ($memo->buybackDocuments->whereIn('document_type', ShmBuybackDocument::REQUIRED_TYPES)->count().'/'.count(ShmBuybackDocument::REQUIRED_TYPES))
                            : null,
                    ],
                ];
            });

        return Inertia::render('Shm/Memorandum/SummaryIndex', [
            'stats' => $stats,
            'items' => $items,
            'sort' => $sortStatus,
            'filters' => $filters,
            'types' => [
                ['value' => 'shgb', 'label' => 'SHM/SHGB'],
                ['value' => 'buyback', 'label' => 'Buyback'],
            ],
            'application_statuses' => collect(ShmApplicationStatus::cases())
                ->map(fn (ShmApplicationStatus $st): array => [
                    'value' => $st->value,
                    'label' => $st->label(),
                ])->values()->all(),
            'memorandum_statuses' => [
                ['value' => 'none', 'label' => 'Belum Dibuat'],
                ['value' => ShmMemorandum::STATUS_DRAFT, 'label' => 'Draft'],
                ['value' => ShmMemorandum::STATUS_SUBMITTED, 'label' => 'Diajukan'],
                ['value' => ShmMemorandum::STATUS_UNDER_REVIEW, 'label' => 'Sedang Direview'],
                ['value' => ShmMemorandum::STATUS_APPROVED, 'label' => 'Disetujui'],
            ],
            'can_update' => Gate::allows('shm.memorandum.update'),
            'can_update_application' => Gate::allows('shm.update'),
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
        ]);
    }

    /**
     * Ajukan memorandum untuk persetujuan (Ticket 8).
     */
    public function submit(ActionShmMemorandumRequest $request, ShmMemorandum $memorandum): RedirectResponse
    {
        $this->authorizeAction('shm.memorandum.submit');
        $application = $memorandum->application;
        abort_unless($application !== null, 404, 'Pengajuan asal tidak ditemukan.');
        $this->authorizeShmOwnership($request, $application);

        try {
            $this->approvalService->submit($memorandum, $request->user(), $request->notesValue());
        } catch (ValidationException $e) {
            $firstError = collect($e->errors())->flatten()->first() ?? 'Gagal mengajukan memorandum.';

            return back()->with('error', $firstError);
        }

        return back()->with('success', 'Memorandum berhasil diajukan untuk proses approval.');
    }

    /**
     * Mulai review/pemeriksaan memorandum (Ticket 8).
     */
    public function startReview(ActionShmMemorandumRequest $request, ShmMemorandum $memorandum): RedirectResponse
    {
        $this->authorizeAction('shm.memorandum.review');
        $application = $memorandum->application;
        abort_unless($application !== null, 404, 'Pengajuan asal tidak ditemukan.');
        $this->authorizeShmOwnership($request, $application);

        try {
            $this->approvalService->startReview($memorandum, $request->user(), $request->notesValue());
        } catch (ValidationException $e) {
            $firstError = collect($e->errors())->flatten()->first() ?? 'Gagal memulai review memorandum.';

            return back()->with('error', $firstError);
        }

        return back()->with('success', 'Pemeriksaan (review) memorandum telah dimulai.');
    }

    /**
     * Setujui memorandum (Ticket 8).
     */
    public function approve(ActionShmMemorandumRequest $request, ShmMemorandum $memorandum): RedirectResponse
    {
        $this->authorizeAction('shm.memorandum.approve');
        $application = $memorandum->application;
        abort_unless($application !== null, 404, 'Pengajuan asal tidak ditemukan.');
        $this->authorizeShmOwnership($request, $application);

        try {
            $this->approvalService->approve($memorandum, $request->user(), $request->notesValue());
        } catch (ValidationException $e) {
            $firstError = collect($e->errors())->flatten()->first() ?? 'Gagal menyetujui memorandum.';

            return back()->with('error', $firstError);
        }

        return back()->with('success', 'Memorandum berhasil disetujui (Approved).');
    }

    /**
     * Tolak memorandum (Ticket 8).
     */
    public function reject(RejectShmMemorandumRequest $request, ShmMemorandum $memorandum): RedirectResponse
    {
        $this->authorizeAction('shm.memorandum.reject');
        $application = $memorandum->application;
        abort_unless($application !== null, 404, 'Pengajuan asal tidak ditemukan.');
        $this->authorizeShmOwnership($request, $application);

        try {
            $this->approvalService->reject($memorandum, $request->user(), $request->reasonValue());
        } catch (ValidationException $e) {
            $firstError = collect($e->errors())->flatten()->first() ?? 'Gagal menolak memorandum.';

            return back()->with('error', $firstError);
        }

        return back()->with('success', 'Memorandum telah ditolak dan dikembalikan ke status Draft untuk diperbaiki.');
    }

    /**
     * Form pembuatan memorandum berdasarkan pengajuan SHM (Legacy Ticket 49).
     *
     * Jika application_id diberikan dan tipe pengajuan diketahui valid, arahkan ke route operasional spesifik.
     */
    public function create(Request $request): Response|RedirectResponse
    {
        $this->authorizeAction('shm.memorandum.update');

        // Forwarding aman: jika application_id diberikan, cek tipenya dan redirect ke form spesifik yang benar
        if ($applicationId = $request->input('application_id')) {
            $application = $this->scopedApplicationQuery()->find((int) $applicationId);
            if ($application) {
                if (($application->type ?? ShmApplication::TYPE_SHGB) === ShmApplication::TYPE_BUYBACK) {
                    return to_route('shm.memorandums.buyback.create', ['application_id' => $application->id]);
                }

                return to_route('shm.memorandums.shm-shgb.create', ['application_id' => $application->id]);
            }
        }

        $selectedApplication = null;

        return Inertia::render('Shm/Memorandum/Create', [
            // Pilihan pengajuan dibatasi hanya status Completed dan yang belum memiliki memorandum
            'applications' => $this->scopedApplicationQuery()
                ->where('status', ShmApplicationStatus::Completed)
                ->orderByDesc('completed_at')
                ->orderByDesc('created_at')
                ->limit(50)
                ->get(['id', 'application_number', 'shm_customer_id', 'status', 'type', 'created_at'])
                ->map(fn (ShmApplication $application): array => [
                    'id' => $application->id,
                    'application_number' => $application->application_number,
                    'status' => $application->status->value,
                    'status_label' => $application->status->label(),
                    'customer_label' => $application->customerLabel(),
                    'type' => $application->type ?? ShmApplication::TYPE_SHGB,
                ])->all(),
            'selected_application' => $selectedApplication,
            'types' => collect(ShmMemorandum::typeLabels())
                ->map(fn (string $label, string $value): array => [
                    'value' => $value,
                    'label' => $label,
                ])->values()->all(),
            'statuses' => [
                ['value' => ShmMemorandum::STATUS_DRAFT, 'label' => ShmMemorandum::statusLabels()[ShmMemorandum::STATUS_DRAFT]],
                ['value' => ShmMemorandum::STATUS_SUBMITTED, 'label' => ShmMemorandum::statusLabels()[ShmMemorandum::STATUS_SUBMITTED]],
            ],
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

        // Gate tahap workflow: memorandum dicatat saat pengajuan berada di tahap Memorandum atau Approval
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
                'shgb_start_date' => $memorandum->shgb_start_date?->toDateString(),
                'shgb_end_date' => $memorandum->shgb_end_date?->toDateString(),
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
