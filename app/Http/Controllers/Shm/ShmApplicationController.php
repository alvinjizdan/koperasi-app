<?php

namespace App\Http\Controllers\Shm;

use App\Enums\ShmApplicationStatus;
use App\Http\Controllers\Concerns\AuthorizesShmOwnership;
use App\Http\Controllers\Concerns\BuildsAuditHistory;
use App\Http\Controllers\Concerns\InteractsWithSorting;
use App\Http\Controllers\Controller;
use App\Http\Requests\Shm\DecideShmApprovalRequest;
use App\Http\Requests\Shm\RecordMemorandumRequest;
use App\Http\Requests\Shm\RecordShmDocumentRequest;
use App\Http\Requests\Shm\SaveShmPropertyRequest;
use App\Http\Requests\Shm\StoreShmApplicationRequest;
use App\Http\Requests\Shm\TransitionShmApplicationRequest;
use App\Http\Requests\Shm\UpdateShmApplicationRequest;
use App\Models\AuditLog;
use App\Models\MarketingActivity;
use App\Models\ShmApplication;
use App\Models\ShmCustomer;
use App\Services\ShmApplicationService;
use App\Services\ShmWorkflowService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Halaman pengajuan SHM/SHGB.
 *
 * Controller ini TIDAK mengubah status secara langsung. Semua perpindahan
 * tahap harus lewat ShmWorkflowService supaya transisi ilegal tidak dapat terjadi
 * dan setiap transisi hanya menulis satu baris audit.
 *
 * Pembatasan data "milik sendiri" ditegakkan di level query, bukan hanya
 * dengan menyembunyikan tombol di UI.
 */
class ShmApplicationController extends Controller
{
    use AuthorizesShmOwnership;
    use BuildsAuditHistory;
    use InteractsWithSorting;

    public function __construct(
        private readonly ShmApplicationService $applications,
        private readonly ShmWorkflowService $workflow,
    ) {}

    /**
     * Daftar pengajuan.
     */
    public function index(Request $request): Response
    {
        $this->authorizeAction('shm.view');

        $filters = [
            'status' => $request->input('status'),
            'shm_customer_id' => $request->input('shm_customer_id'),
            'marketing_id' => $request->input('marketing_id'),
            'search' => $request->input('search'),
        ];

        $query = $this->scopedQuery($request)
            ->with(['customer:id,customer_number,name', 'marketing:id,name', 'properties'])
            ->when($filters['status'], fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($filters['shm_customer_id'], fn (Builder $q, string $id) => $q->where('shm_customer_id', $id))
            ->when(
                $filters['marketing_id'],
                fn (Builder $q, string $id) => $q->where('marketing_id', $id)
            )
            // Pencarian server-side. Tidak pernah mengambil seluruh dataset lalu
            // difilter di browser. Nasabah dicari dari master nasabah SHM, bukan
            // dari tabel anggota koperasi.
            ->when($filters['search'], function (Builder $q, string $search): void {
                $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%';

                $q->where(function (Builder $inner) use ($term): void {
                    // `like` dipakai agar konsisten dengan scope pencarian master
                    // data lain dan tetap berjalan pada driver SQLite test suite.
                    $inner->where('application_number', 'like', $term)
                        ->orWhereIn(
                            'shm_customer_id',
                            ShmCustomer::query()
                                ->select('id')
                                ->where(function (Builder $customer) use ($term): void {
                                    $customer->where('name', 'like', $term)
                                        ->orWhere('customer_number', 'like', $term);
                                })
                        );
                });
            });

        $sortStatus = $this->applySorting($query, $request, [
            'application_number' => 'application_number',
            'status' => 'status',
            'created_at' => 'created_at',
            'submitted_at' => 'submitted_at',
        ], [['created_at', 'desc']]);

        $applications = $query->paginate($this->resolvePerPage($request))
            ->withQueryString()
            ->through(fn (ShmApplication $application): array => [
                'id' => $application->id,
                'application_number' => $application->application_number,
                'status' => $application->status->value,
                'status_label' => $application->status->label(),
                'customer_label' => $application->customerLabel(),
                'shm_customer_id' => $application->shm_customer_id,
                'marketing' => $application->marketing?->name,
                // Jenis sertifikat diambil dari objek pertama bila sudah ada,
                // tanpa memuat seluruh objek.
                'certificate_type' => $application->properties->first()?->certificate_type,
                'submitted_at' => $application->submitted_at,
                'created_at' => $application->created_at,
            ]);

        return Inertia::render('Shm/Applications/Index', [
            'applications' => $applications,
            'sort' => $sortStatus,
            'filters' => $filters,
            'statuses' => $this->statusOptions(),
            // Master nasabah SHM, bukan daftar anggota koperasi.
            'customers' => $this->applications->selectableCustomers(),
            'can_view_all' => $this->canViewAllShm(),
            'can_create' => Gate::allows('shm.create'),
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
        ]);
    }

    /**
     * Form pembuatan pengajuan.
     */
    public function create(Request $request): Response
    {
        $this->authorizeAction('shm.create');

        return Inertia::render('Shm/Applications/Create', [
            'customers' => $this->applications->selectableCustomers(),
            'can_create_customer' => Gate::allows('shm.customer.create'),
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
        ]);
    }

    /**
     * Simpan pengajuan baru.
     */
    public function store(StoreShmApplicationRequest $request): RedirectResponse
    {
        $application = $this->applications->create($request->payload());

        return to_route('shm.applications.show', $application->id)
            ->with('success', 'Pengajuan SHM berhasil dibuat.');
    }

    /**
     * Detail pengajuan beserta seluruh tahap dan timeline.
     */
    public function show(Request $request, ShmApplication $application): Response
    {
        $this->authorizeAction('shm.view');
        $this->authorizeShmOwnership($request, $application);

        $application->load([
            'customer:id,customer_number,name,nik,phone,email,address,is_active',
            'marketing:id,name',
            'marketingActivity:id,member_id,checked_in_at,latitude,longitude,result',
            'properties',
            'latestCreditCheck.checker:id,name',
            'memorandums.creator:id,name',
            'approvals.decider:id,name',
            'documents.checker:id,name',
        ]);

        $creditCheck = $application->latestCreditCheck;

        return Inertia::render('Shm/Applications/Show', [
            'application' => [
                'id' => $application->id,
                'application_number' => $application->application_number,
                'customer' => $application->customer,
                'shm_customer_id' => $application->shm_customer_id,
                'customer_label' => $application->customerLabel(),
                'marketing' => $application->marketing,
                'status' => $application->status->value,
                'status_label' => $application->status->label(),
                'submitted_at' => $application->submitted_at,
                'approved_at' => $application->approved_at,
                'rejected_at' => $application->rejected_at,
                'disbursed_at' => $application->disbursed_at,
                'completed_at' => $application->completed_at,
                'notes' => $application->notes,
                'is_editable' => $application->isEditable(),
                'marketing_activity_id' => $application->marketing_activity_id,
            ],
            'properties' => $application->properties,
            // Ringkasan credit checking untuk panel di halaman pengajuan.
            // Sumber kebenaran hasil tetap halaman Credit Checking.
            'credit_check' => $creditCheck === null ? null : [
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
            'documents' => $application->documents,
            'documents_complete' => $application->documentsComplete(),
            'documents_summary' => $application->documentsSummary(),
            'marketing_activity' => $application->marketingActivity,
            // Hanya aktivitas milik pengguna ini yang boleh ditampilkan di form.
            'available_activities' => $this->availableActivities($request),
            'timeline' => $this->buildTimeline($application),
            'history' => $this->buildHistory($application),
            'transitions' => $this->availableTransitions($request, $application),
            'permissions' => [
                'can_update' => $application->isEditable() && Gate::allows('shm.update'),
                'can_submit' => $application->isEditable() && Gate::allows('shm.submit'),
                'can_manage_property' => Gate::allows('shm.property.manage'),
                'can_manage_credit_check' => Gate::allows('shm.credit_checking.update'),
                'can_view_credit_check' => Gate::allows('shm.credit_checking.view'),
                'can_update_memorandum' => Gate::allows('shm.memorandum.update'),
                'can_decide_approval' => Gate::allows('shm.approval.decide'),
                'can_update_document' => Gate::allows('shm.document.update'),
                'can_disburse' => Gate::allows('shm.disburse'),
                'can_complete' => Gate::allows('shm.complete'),
            ],
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
        ]);
    }

    /**
     * Form edit pengajuan yang masih draft.
     */
    public function edit(Request $request, ShmApplication $application): Response
    {
        $this->authorizeAction('shm.update');
        $this->authorizeShmOwnership($request, $application);

        abort_unless($application->isEditable(), 422, 'Pengajuan hanya dapat diubah selama masih berstatus draft.');

        return Inertia::render('Shm/Applications/Edit', [
            'application' => [
                'id' => $application->id,
                'application_number' => $application->application_number,
                'shm_customer_id' => $application->shm_customer_id,
                'notes' => $application->notes,
                'status' => $application->status->value,
            ],
            'customers' => $this->applications->selectableCustomers(),
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
        ]);
    }

    /**
     * Simpan perubahan data pengajuan.
     */
    public function update(UpdateShmApplicationRequest $request, ShmApplication $application): RedirectResponse
    {
        $this->authorizeShmOwnership($request, $application);

        abort_unless($application->isEditable(), 422, 'Pengajuan hanya dapat diubah selama masih berstatus draft.');

        $this->applications->update($application, $request->payload());

        return to_route('shm.applications.show', $application->id)
            ->with('success', 'Data pengajuan berhasil diperbarui.');
    }

    /**
     * Marketing mengirim pengajuan dari draft ke tahap submitted.
     */
    public function submit(TransitionShmApplicationRequest $request, ShmApplication $application): RedirectResponse
    {
        Gate::authorize('shm.submit');
        $this->authorizeShmOwnership($request, $application);

        $this->workflow->submit($application, $request->user()->id);

        return back()->with('success', 'Pengajuan berhasil dikirim.');
    }

    // -----------------------------------------------------------------
    // Transisi antar tahap kerja (Ticket 45).
    //
    // Ticket 44 mendefinisikan rantai status tetapi tidak menyediakan jalur
    // melewati credit_checking, memorandum, approval, dan document_check.
    // Akibatnya pengajuan tidak pernah bisa mencapai status approved, karena
    // approve() hanya sah dari status approval.
    //
    // Setiap tahap punya endpoint sendiri. Arah transisi ditentukan server dan
    // tidak dapat dipalsukan lewat payload.
    // -----------------------------------------------------------------

    /**
     * Submitted ke credit checking.
     */
    public function startCreditChecking(
        TransitionShmApplicationRequest $request,
        ShmApplication $application,
    ): RedirectResponse {
        Gate::authorize('shm.submit');
        $this->authorizeShmOwnership($request, $application);

        $this->workflow->startCreditChecking($application, $request->user()->id);

        return back()->with('success', 'Pengajuan masuk ke tahap Credit Checking.');
    }

    /**
     * Credit checking ke memorandum.
     */
    public function startMemorandum(
        TransitionShmApplicationRequest $request,
        ShmApplication $application,
    ): RedirectResponse {
        Gate::authorize('shm.memorandum.update');
        $this->authorizeShmOwnership($request, $application);

        $this->workflow->startMemorandum($application, $request->user()->id);

        return back()->with('success', 'Pengajuan masuk ke tahap Memorandum.');
    }

    /**
     * Memorandum ke tahap approval.
     */
    public function startApproval(
        TransitionShmApplicationRequest $request,
        ShmApplication $application,
    ): RedirectResponse {
        Gate::authorize('shm.memorandum.update');
        $this->authorizeShmOwnership($request, $application);

        $this->workflow->startApproval($application, $request->user()->id);

        return back()->with('success', 'Pengajuan masuk ke tahap Approval.');
    }

    /**
     * Approved ke kelengkapan dokumen.
     */
    public function startDocumentCheck(
        TransitionShmApplicationRequest $request,
        ShmApplication $application,
    ): RedirectResponse {
        Gate::authorize('shm.document.update');
        $this->authorizeShmOwnership($request, $application);

        $this->workflow->startDocumentCheck($application, $request->user()->id);

        return back()->with('success', 'Pengajuan masuk ke tahap Kelengkapan Dokumen.');
    }

    /**
     * Kelengkapan dokumen ke siap pencairan.
     *
     * Kelengkapan dokumen TIDAK otomatis menyetujui pengajuan maupun memicu
     * pencairan. Ini hanya transisi state administratif.
     */
    public function markReadyForDisbursement(
        TransitionShmApplicationRequest $request,
        ShmApplication $application,
    ): RedirectResponse {
        Gate::authorize('shm.document.update');
        $this->authorizeShmOwnership($request, $application);

        $this->workflow->markReadyForDisbursement($application, $request->user()->id);

        return back()->with('success', 'Pengajuan ditandai siap pencairan.');
    }

    /**
     * Pengajuan yang ditolak dikembalikan ke draft.
     */
    public function returnToDraft(
        TransitionShmApplicationRequest $request,
        ShmApplication $application,
    ): RedirectResponse {
        Gate::authorize('shm.approval.decide');
        $this->authorizeShmOwnership($request, $application);

        $this->workflow->returnToDraft($application, $request->user()->id);

        return back()->with('success', 'Pengajuan dikembalikan ke draft.');
    }

    /**
     * Admin atau pemangku wewenang mengambil keputusan approval.
     */
    public function approve(DecideShmApprovalRequest $request, ShmApplication $application): RedirectResponse
    {
        $this->workflow->approve($application, $request->user()->id, $request->notesValue());

        return back()->with('success', 'Pengajuan berhasil disetujui.');
    }

    /**
     * Penolakan pada tahap approval.
     */
    public function reject(DecideShmApprovalRequest $request, ShmApplication $application): RedirectResponse
    {
        $this->workflow->reject($application, $request->user()->id, $request->notesValue());

        return back()->with('success', 'Pengajuan berhasil ditolak.');
    }

    /**
     * Tandai pengajuan sudah dicairkan.
     *
     * Tidak ada posting jurnal otomatis dan tidak ada perhitungan keuangan apa
     * pun. Yang dicatat hanya fakta pencairan.
     */
    public function disburse(TransitionShmApplicationRequest $request, ShmApplication $application): RedirectResponse
    {
        Gate::authorize('shm.disburse');

        $this->workflow->markDisbursed($application, $request->user()->id, $request->notesValue());

        return back()->with('success', 'Pengajuan ditandai sudah dicairkan.');
    }

    /**
     * Tandai pengajuan selesai.
     *
     * Tidak ada engine pembayaran atau collection pada ticket ini.
     */
    public function complete(TransitionShmApplicationRequest $request, ShmApplication $application): RedirectResponse
    {
        Gate::authorize('shm.complete');

        $this->workflow->markCompleted($application, $request->user()->id);

        return back()->with('success', 'Pengajuan ditandai selesai.');
    }

    /**
     * Batalkan pengajuan. Hanya diizinkan sebelum approval.
     */
    public function cancel(TransitionShmApplicationRequest $request, ShmApplication $application): RedirectResponse
    {
        $this->authorizeShmOwnership($request, $application);

        $this->workflow->cancel($application, $request->user()->id, $request->notesValue());

        return back()->with('success', 'Pengajuan berhasil dibatalkan.');
    }

    /**
     * Reopen ke tahap Pemeriksaan Dokumen karena kelengkapan dokumen tidak
     * terpenuhi (Ticket 52).
     *
     * Reopen HANYA dari `ready_for_disbursement` dan HANYA jika dokumen tidak
     * lengkap. Tidak ada automatic reopen/rollback: perubahan dokumen saja tidak
     * memicu perubahan status aplikasi.
     */
    public function reopenDocumentCheck(
        TransitionShmApplicationRequest $request,
        ShmApplication $application,
    ): RedirectResponse {
        Gate::authorize('shm.document.update');
        $this->authorizeShmOwnership($request, $application);

        $this->workflow->reopenDocumentCheck($application, $request->user()->id);

        return back()->with('success', 'Pengajuan dikembalikan ke tahap Pemeriksaan Dokumen.');
    }

    /**
     * Simpan objek SHM/SHGB milik pengajuan.
     */
    public function saveProperty(SaveShmPropertyRequest $request, ShmApplication $application): RedirectResponse
    {
        $this->authorizeShmOwnership($request, $application);

        // Objek SHM/SHGB boleh diisi selama pengajuan belum ditolak atau
        // dibatalkan. Setelah itu datanya dibekukan.
        $this->workflow->guardStage(
            $application,
            [
                ShmApplicationStatus::Draft,
                ShmApplicationStatus::Submitted,
                ShmApplicationStatus::CreditChecking,
                ShmApplicationStatus::Memorandum,
                ShmApplicationStatus::Approval,
                ShmApplicationStatus::Approved,
                ShmApplicationStatus::DocumentCheck,
                ShmApplicationStatus::ReadyForDisbursement,
            ],
            'Pengelolaan objek SHM/SHGB',
        );

        $this->applications->saveProperty(
            $application,
            $request->payload(),
            $request->integer('property_id') ?: null
        );

        return back()->with('success', 'Objek SHM/SHGB berhasil disimpan.');
    }

    /**
     * Catat memorandum pengajuan.
     */
    public function recordMemorandum(
        RecordMemorandumRequest $request,
        ShmApplication $application,
    ): RedirectResponse {
        $this->authorizeShmOwnership($request, $application);

        $this->workflow->guardStage(
            $application,
            [
                ShmApplicationStatus::Memorandum,
                ShmApplicationStatus::Approval,
            ],
            'Pencatatan memorandum',
        );

        $this->workflow->recordMemorandum(
            $application,
            $request->statusValue(),
            $request->notesValue(),
            $request->user()->id,
            // Ticket 49: kategori ikut disimpan bila dikirim (nullable agar
            // klien lama yang tidak mengirim type tetap bekerja seperti T44).
            $request->typeValue(),
        );

        return back()->with('success', 'Memorandum berhasil dicatat.');
    }

    /**
     * Catat kelengkapan dokumen.
     */
    public function recordDocument(
        RecordShmDocumentRequest $request,
        ShmApplication $application,
    ): RedirectResponse {
        $this->authorizeShmOwnership($request, $application);

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

        return back()->with('success', 'Kelengkapan dokumen berhasil dicatat.');
    }

    /**
     * Batasi query sesuai cakupan akses pengguna.
     *
     * Tanpa shm.view_all, pengguna hanya melihat pengajuannya sendiri.
     */
    private function scopedQuery(Request $request): Builder
    {
        $query = ShmApplication::query();

        if (! $this->canViewAllShm()) {
            $query->where('marketing_id', $request->user()->id);
        }

        return $query;
    }

    /**
     * Pemeriksaan permission sederhana yang konsisten dengan pola middleware.
     */
    private function authorizeAction(string $permission): void
    {
        abort_unless(Gate::allows($permission), 403, 'Anda tidak memiliki hak akses untuk modul SHM.');
    }

    /**
     * Timeline dibangun dari event yang BENAR-BENAR terjadi.
     *
     * Ticket 44 mengirim seluruh daftar tahap ke frontend lalu menandai
     * tahap yang belum terjadi dengan done=false. Ticket 45 §10 melarang
     * itu: tahap yang belum terjadi tidak boleh muncul sebagai event yang
     * sudah terjadi.
     *
     * Karena itu timeline sekarang hanya berisi event yang ada datanya,
     * diurutkan berdasarkan waktu server.
     *
     * @return array<int, array{key: string, label: string, at: string|null, actor: string|null, current: bool}>
     */
    private function buildTimeline(ShmApplication $application): array
    {
        $events = [];

        $add = function (string $key, string $label, $at, ?string $actor = null) use (&$events): void {
            if ($at === null) {
                return;
            }

            $events[] = [
                'key' => $key,
                'label' => $label,
                'at' => $at instanceof \DateTimeInterface ? Carbon::instance($at)->toIso8601String() : (string) $at,
                'actor' => $actor,
                'current' => false,
            ];
        };

        $add('created', 'Pengajuan dibuat', $application->created_at, $application->marketing?->name);
        $add('submitted', 'Disubmit Marketing', $application->submitted_at, $application->marketing?->name);
        $add('approved', 'Disetujui', $application->approved_at);
        $add('rejected', 'Ditolak', $application->rejected_at);
        $add('disbursed', 'Dicairkan', $application->disbursed_at);
        $add('completed', 'Selesai', $application->completed_at);

        if ($application->status === ShmApplicationStatus::Cancelled) {
            $add('cancelled', 'Dibatalkan', $application->updated_at, $application->marketing?->name);
        }

        // Tahap yang tidak punya kolom timestamp sendiri memakai waktu event audit
        // yang benar-benar tercatat. Setiap event dipetakan ke audit action-nya
        // sendiri supaya tidak lagi ambigu seperti di Ticket 44.
        $auditMap = [
            'credit_checking' => ['shm.credit_check.started', 'Credit Checking dimulai'],
            'credit_check_completed' => ['shm.credit_check.completed', 'Credit Checking selesai'],
            'memorandum' => ['shm.memorandum.updated', 'Memorandum dicatat'],
            'document_check' => ['shm.document.updated', 'Kelengkapan dokumen dicatat'],
        ];

        foreach ($auditMap as $key => [$action, $label]) {
            $log = $this->firstAuditFor($application, $action);

            if ($log !== null) {
                $add($key, $label, $log->created_at, $log->actor?->name);
            }
        }

        // Perpindahan tahap yang memakai event status_changed umum.
        foreach ($this->statusChangeEvents($application) as [$key, $label]) {
            $log = $this->lastAuditFor($application, 'shm.application.status_changed', $key);

            if ($log !== null) {
                $add($key, $label, $log->created_at, $log->actor?->name);
            }
        }

        usort($events, static fn (array $a, array $b): int => strcmp($a['at'], $b['at']));

        $lastKey = $events === [] ? null : $events[count($events) - 1]['key'];

        return array_map(static function (array $event) use ($lastKey): array {
            $event['current'] = $event['key'] === $lastKey;

            return $event;
        }, $events);
    }

    /**
     * Nilai status tujuan untuk setiap event status_changed, dipetakan ke
     * label tahap yang dimunculkan pada timeline.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private function statusChangeEvents(ShmApplication $application): array
    {
        return [
            ['approval', 'Diajukan untuk approval'],
            ['document_check', 'Pemeriksaan dokumen'],
            ['ready_for_disbursement', 'Siap pencairan'],
        ];
    }

    /**
     * Audit pertama dengan action tertentu untuk pengajuan ini.
     */
    private function firstAuditFor(ShmApplication $application, string $action): ?AuditLog
    {
        return AuditLog::query()
            ->where('object_type', ShmApplication::class)
            ->where('object_id', $application->id)
            ->where('action', $action)
            ->with('actor:id,name')
            ->orderBy('created_at')
            ->first();
    }

    /**
     * Audit terakhir dengan action tertentu, dibatasi pada nilai status tujuan
     * yang tercatat di payload changes.
     */
    private function lastAuditFor(ShmApplication $application, string $action, string $toStatus): ?AuditLog
    {
        return AuditLog::query()
            ->where('object_type', ShmApplication::class)
            ->where('object_id', $application->id)
            ->where('action', $action)
            ->with('actor:id,name')
            ->orderByDesc('created_at')
            ->get()
            ->first(fn (AuditLog $log): bool => ($log->changes['to'] ?? null) === $toStatus);
    }

    /**
     * Aktivitas Marketing yang boleh dipilih pengguna pada halaman detail.
     *
     * Marketing hanya melihat aktivitasnya sendiri. Holder shm.view_all
     * melihat seluruh aktivitas karena mereka sudah berwenang melihat
     * seluruh pengajuan.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function availableActivities(Request $request): array
    {
        $ownerId = $this->canViewAllShm() ? null : $request->user()->id;

        $activities = MarketingActivity::query()
            ->when($ownerId !== null, static fn ($query) => $query->where('marketing_id', $ownerId))
            ->orderByDesc('checked_in_at')
            ->limit(50)
            ->get(['id', 'member_id', 'checked_in_at', 'result']);

        return $activities->map(static fn (MarketingActivity $activity): array => [
            'id' => $activity->id,
            'checked_in_at' => $activity->checked_in_at,
            'result' => $activity->result,
            'label' => sprintf(
                'Kunjungan %s',
                $activity->checked_in_at?->format('d M Y H:i') ?? '-',
            ),
        ])->all();
    }

    /**
     * Riwayat audit pengajuan yang boleh dilihat pengguna.
     *
     * @return Collection<int, AuditLog>
     */
    private function buildHistory(ShmApplication $application)
    {
        return $this->auditHistory(ShmApplication::class, $application->id);
    }

    /**
     * Transisi yang boleh dijalankan pengguna saat ini.
     *
     * @return array<int, string>
     */
    private function availableTransitions(Request $request, ShmApplication $application): array
    {
        $status = $application->status;
        $user = $request->user();
        $isOwner = $application->marketing_id === $user->id;
        $allowed = [];

        foreach ($status->allowedTransitions() as $target) {
            $permitted = match ($target) {
                ShmApplicationStatus::Submitted => Gate::allows('shm.submit') && $isOwner,
                ShmApplicationStatus::CreditChecking => Gate::allows('shm.submit') && $isOwner,
                ShmApplicationStatus::Memorandum => Gate::allows('shm.memorandum.update') && $isOwner,
                ShmApplicationStatus::Approval => Gate::allows('shm.memorandum.update') && $isOwner,
                ShmApplicationStatus::DocumentCheck => Gate::allows('shm.document.update') && $isOwner,
                ShmApplicationStatus::ReadyForDisbursement => Gate::allows('shm.document.update') && $isOwner,
                ShmApplicationStatus::Approved, ShmApplicationStatus::Rejected => Gate::allows('shm.approval.decide'),
                ShmApplicationStatus::Disbursed => Gate::allows('shm.disburse'),
                ShmApplicationStatus::Completed => Gate::allows('shm.complete'),
                ShmApplicationStatus::Cancelled => Gate::allows('shm.submit') && $isOwner,
                ShmApplicationStatus::Draft => Gate::allows('shm.submit') && $isOwner,
            };

            if ($permitted) {
                $allowed[] = $target->value;
            }
        }

        return $allowed;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function statusOptions(): array
    {
        return collect(ShmApplicationStatus::cases())
            ->map(fn (ShmApplicationStatus $status): array => [
                'value' => $status->value,
                'label' => $status->label(),
            ])
            ->all();
    }
}
