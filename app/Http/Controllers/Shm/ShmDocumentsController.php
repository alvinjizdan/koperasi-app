<?php

namespace App\Http\Controllers\Shm;

use App\Enums\ShmApplicationStatus;
use App\Http\Controllers\Concerns\BuildsAuditHistory;
use App\Http\Controllers\Concerns\InteractsWithSorting;
use App\Http\Controllers\Controller;
use App\Http\Requests\Shm\UpdateShmDocumentRequest;
use App\Models\ShmApplication;
use App\Models\ShmCustomer;
use App\Models\ShmDocument;
use App\Services\ShmWorkflowService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Kelengkapan Dokumen (Admin monitoring) — Ticket 50.
 *
 * Modul ini hanya memantau/mencatat checklist dokumen yang sudah tersedia di
 * `shm_documents`. Tidak ada daftar dokumen legal yang dikarang, tidak ada
 * upload berkas, dan tidak ada otomatisasi approval/pencairan.
 *
 * Cakupan data mengikuti pola yang sama dengan modul Memorandum: admin/superadmin
 * dengan `shm.view_all` melihat seluruh pengajuan, Marketing tanpa itu hanya
 * melihat pengajuannya sendiri. Batas ini ditegakkan di query, bukan hanya di UI.
 *
 * Seluruh tulisan dokumen tetap melalui `ShmWorkflowService::recordDocument` agar
 * audit, actor, dan checked_by/checked_at berasal dari satu sumber (event
 * `shm.document.updated`). Controller tidak menulis audit sendiri.
 */
class ShmDocumentsController extends Controller
{
    use BuildsAuditHistory;
    use InteractsWithSorting;

    public function __construct(
        private readonly ShmWorkflowService $workflow,
    ) {}

    /**
     * Daftar item dokumen dengan pencarian, filter status/document, sorting,
     * dan pagination.
     */
    public function index(Request $request): Response
    {
        $this->authorizeAction('shm.document.update');

        $filters = [
            'search' => $request->input('search'),
            'document_status' => $request->input('document_status'),
            'application_status' => $request->input('application_status'),
            'shm_customer_id' => $request->input('shm_customer_id'),
        ];

        // Base query join ke pengajuannya supaya bisa difilter/dicari lewat
        // konteks aplikasi/nasabah.
        $query = ShmDocument::query()
            ->select('shm_documents.*')
            ->join('shm_applications', 'shm_applications.id', '=', 'shm_documents.shm_application_id')
            ->whereHas('application', fn (Builder $q) => $this->scopeApplication($q))
            ->with([
                'application:id,application_number,shm_customer_id,status,created_at,marketing_id',
                'application.customer:id,customer_number,name',
                'application.properties',
                'checker:id,name',
            ])
            ->when($filters['document_status'], fn (Builder $q, string $status) => $q->where('shm_documents.status', $status))
            ->when($filters['application_status'], fn (Builder $q, string $status) => $q->where('shm_applications.status', $status))
            ->when($filters['shm_customer_id'], fn (Builder $q, string $id) => $q->where('shm_applications.shm_customer_id', $id))
            ->when($filters['search'], function (Builder $q, string $search): void {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';

                $q->where(function (Builder $inner) use ($term): void {
                    $inner->where('shm_documents.document_name', 'like', $term)
                        ->orWhere('shm_applications.application_number', 'like', $term)
                        ->orWhereIn(
                            'shm_applications.shm_customer_id',
                            ShmCustomer::query()
                                ->select('id')
                                ->where(function (Builder $customer) use ($term): void {
                                    $customer->where('name', 'like', $term)
                                        ->orWhere('customer_number', 'like', $term);
                                }),
                        );
                });
            });

        $sortStatus = $this->applySorting($query, $request, [
            'document_name' => 'shm_documents.document_name',
            'status' => 'shm_documents.status',
            'application_number' => 'shm_applications.application_number',
            'customer_name' => 'shm_customers.name',
            'updated_at' => 'shm_documents.updated_at',
            'checked_at' => 'shm_documents.checked_at',
        ], [['shm_documents.updated_at', 'desc']]);

        $documents = $query->paginate($this->resolvePerPage($request))
            ->withQueryString()
            ->through(fn (ShmDocument $document): array => $this->summaryRow($document));

        return Inertia::render('Shm/Documents/Index', [
            'documents' => $documents,
            'sort' => $sortStatus,
            'filters' => $filters,
            'document_statuses' => collect(ShmDocument::statuses())
                ->mapWithKeys(fn (string $status): array => [$status => ucfirst($status)])
                ->all(),
            'application_statuses' => $this->applicationStatusOptions(),
            'customers' => $this->selectableCustomers(),
            'can_update' => Gate::allows('shm.document.update'),
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
        ]);
    }

    /**
     * Detail dokumen suatu pengajuan.
     */
    public function show(Request $request, ShmApplication $application): Response
    {
        $this->authorizeAction('shm.document.update');

        // Terapkan cakupan yang sama dengan modul Memorandum: Marketing tanpa
        // shm.view_all hanya boleh membuka pengajuan miliknya sendiri.
        if (! $this->canViewAllShm()) {
            abort_if($application->marketing_id !== auth()->id(), 403, 'Pengajuan ini bukan milik Anda.');
        }

        $application->load([
            'customer:id,customer_number,name,nik,phone,email,address,is_active',
            'marketing:id,name',
            'properties',
            'documents.checker:id,name',
            'latestCreditCheck.checker:id,name',
            'latestMemorandum',
            'latestApproval',
        ]);

        // Materialkan relasi HasOne sekali supaya tidak muncul baris berbeda
        // dari pemanggilan berulang (pola yang sama dengan
        // ShmMemorandumController.show).
        $creditCheck = $application->latestCreditCheck;

        return Inertia::render('Shm/Documents/Show', [
            'application' => [
                'id' => $application->id,
                'application_number' => $application->application_number,
                'status' => $application->status->value,
                'status_label' => $application->status->label(),
                'customer' => $application->customer,
                'shm_customer_id' => $application->shm_customer_id,
                'customer_label' => $application->customerLabel(),
                'marketing' => $application->marketing,
                'created_at' => $application->created_at,
                'submitted_at' => $application->submitted_at,
                'approved_at' => $application->approved_at,
                'disbursed_at' => $application->disbursed_at,
                'completed_at' => $application->completed_at,
                'notes' => $application->notes,
            ],
            'properties' => $application->properties,
            'documents' => $application->documents,
            'documents_summary' => $application->documentsSummary(),
            'documents_complete' => $application->documentsComplete(),
            'credit_check' => $creditCheck === null ? null : [
                'id' => $creditCheck->id,
                'status' => $creditCheck->status->value,
                'status_label' => $creditCheck->statusLabel(),
                'checker' => $creditCheck->checker?->name,
                'started_at' => $creditCheck->started_at,
                'completed_at' => $creditCheck->checked_at,
                'result' => $creditCheck->result,
                'outcome' => $creditCheck->outcome?->value,
                'outcome_label' => $creditCheck->outcome?->label(),
            ],
            'memorandum' => $application->latestMemorandum === null ? null : [
                'id' => $application->latestMemorandum->id,
                'type' => $application->latestMemorandum->type,
                'type_label' => $application->latestMemorandum->typeLabel(),
                'status' => $application->latestMemorandum->status,
                'status_label' => $application->latestMemorandum->statusLabel(),
                'notes' => $application->latestMemorandum->notes,
                'submitted_at' => $application->latestMemorandum->submitted_at,
            ],
            'approval' => $application->latestApproval,
            'can_update' => Gate::allows('shm.document.update'),
            'history' => $this->auditHistory(ShmDocument::class, $application->id)->take(50),
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
        ]);
    }

    /**
     * Perbarui item dokumen (tambah atau ubah status/checklist).
     *
     * Endpoint ini TIDAK membatasi owner pengajuan (Admin memantau seluruh
     * pengajuan), tetapi tetap membatasi tahap: dokumen hanya boleh dicatat
     * saat pengajuan sudah di Approval/DocumentCheck/ReadyForDisbursement — sama
     * seperti endpoint dokumen pada halaman pengajuan.
     */
    public function update(UpdateShmDocumentRequest $request, ShmApplication $application): RedirectResponse
    {
        $this->authorizeAction('shm.document.update');

        // Pastikan pengguna boleh melihat pengajuan ini (cakupan, bukan
        // kepemilikan ketat).
        if (! $this->canViewAllShm()) {
            abort_if($application->marketing_id !== auth()->id(), 403, 'Pengajuan ini bukan milik Anda.');
        }

        $this->workflow->guardStage(
            $application,
            [
                ShmApplicationStatus::Approved,
                ShmApplicationStatus::DocumentCheck,
                ShmApplicationStatus::ReadyForDisbursement,
            ],
            'Pencatatan kelengkapan dokumen',
        );

        $this->workflow->recordDocument(
            $application,
            $request->nameValue(),
            $request->statusValue(),
            $request->notesValue(),
            $request->user()->id,
            $request->documentIdValue(),
        );

        return back()->with('success', 'Kelengkapan dokumen berhasil diperbarui.');
    }

    // -----------------------------------------------------------------
    // helpers
    // -----------------------------------------------------------------

    /**
     * Baris ringkas untuk tabel daftar.
     *
     * @return array<string, mixed>
     */
    private function summaryRow(ShmDocument $document): array
    {
        $application = $document->application;

        return [
            'id' => $document->id,
            'document_name' => $document->document_name,
            'status' => $document->status,
            'status_label' => $document->isComplete() ? 'Tercatat' : 'Pending',
            'application_number' => $application?->application_number,
            'application_id' => $application?->id,
            'customer_name' => $application?->customer?->name,
            'customer_number' => $application?->customer?->customer_number,
            'application_status_label' => $application?->status?->label(),
            'property_summary' => $this->propertySummary($application?->properties),
            'checker' => $document->checker?->name,
            'checked_at' => $document->checked_at,
            'updated_at' => $document->updated_at,
        ];
    }

    /**
     * Ringkasan singkat objek SHM/SHGB.
     */
    private function propertySummary($properties): ?array
    {
        $first = $properties?->firstWhere('certificate_type');

        if ($first === null) {
            return null;
        }

        return [
            'certificate_type' => $first->certificate_type,
            'certificate_number' => $first->certificate_number,
            'address' => $first->address,
        ];
    }

    /**
     * Opsi status aplikasi untuk filter.
     *
     * @return array<int, array{value: string, label: string}>
     */
    /**
     * Opsi status aplikasi untuk filter.
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function applicationStatusOptions(): array
    {
        return collect(ShmApplicationStatus::cases())
            ->map(fn (ShmApplicationStatus $status): array => [
                'value' => $status->value,
                'label' => $status->label(),
            ])
            ->values()
            ->all();
    }

    /**
     * Master nasabah SHM untuk filter (berbeda dari anggota koperasi).
     *
     * @return array<int, array{id: int, name: string, customer_number: string}>
     */
    private function selectableCustomers(): array
    {
        return ShmCustomer::query()
            ->active()
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'customer_number', 'name'])
            ->map(fn (ShmCustomer $customer): array => [
                'id' => $customer->id,
                'name' => $customer->name,
                'customer_number' => $customer->customer_number,
            ])->all();
    }

    /**
     * Pemeriksaan permission sederhana yang konsisten dengan pola middleware.
     */
    private function authorizeAction(string $permission): void
    {
        abort_unless(Gate::allows($permission), 403, 'Anda tidak memiliki hak akses untuk modul ini.');
    }

    /**
     * Cakupan pengajuan yang boleh dilihat pengguna.
     *
     * Tanpa `shm.view_all`, pengguna hanya melihat pengajuannya sendiri.
     */
    private function canViewAllShm(): bool
    {
        return Gate::allows('shm.view_all');
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
}
