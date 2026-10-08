<?php

namespace App\Http\Controllers\Shm;

use App\Enums\ShmApplicationStatus;
use App\Http\Controllers\Concerns\AuthorizesShmOwnership;
use App\Http\Controllers\Concerns\BuildsAuditHistory;
use App\Http\Controllers\Concerns\InteractsWithSorting;
use App\Http\Controllers\Controller;
use App\Models\ShmApplication;
use App\Models\ShmApproval;
use App\Models\ShmCustomer;
use App\Models\ShmDocument;
use App\Models\ShmMemorandum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Modul Approval operasional (Ticket 54).
 *
 * Controller ini HANYA MEMBACA: daftar dan detail pengajuan yang relevan untuk
 * keputusan approval. Tidak ada endpoint keputusan kedua — approve/reject tetap
 * memakai endpoint pengajuan existing (shm.applications.approve / reject) yang
 * semuanya lewat ShmWorkflowService. Controller tidak pernah menulis kolom
 * status maupun baris approval secara langsung.
 *
 * Struktur keputusan yang ditampilkan adalah struktur existing: tabel
 * shm_approvals (status, decided_by, decided_at, notes) milik Ticket 44.
 * Tidak ada approval hierarchy, pagu nominal, scoring, atau status baru.
 *
 * Modul dilindungi permission `shm.approval.decide` yang sudah ada. Pembatasan
 * data "milik sendiri" tetap ditegakkan di level query seperti modul SHM lain,
 * sebagai lapisan kedua di balik middleware.
 */
class ShmApprovalController extends Controller
{
    use AuthorizesShmOwnership;
    use BuildsAuditHistory;
    use InteractsWithSorting;

    /**
     * Status pengajuan yang relevan untuk modul approval.
     *
     * Daftar ini BUKAN workflow baru: ketiganya adalah status existing dari
     * ShmApplicationStatus. Modul ini hanya menampilkan pengajuan yang sedang
     * atau sudah melewati tahap keputusan; tahap lain tetap milik modulnya.
     *
     * @var array<int, ShmApplicationStatus>
     */
    private const DECISION_STATUSES = [
        ShmApplicationStatus::Approval,
        ShmApplicationStatus::Approved,
        ShmApplicationStatus::Rejected,
    ];

    /**
     * Daftar pengajuan untuk keputusan approval.
     *
     * Bawaan menampilkan pengajuan yang sedang berada pada tahap approval.
     * Filter status memungkinkan melihat hasil keputusan (approved/rejected)
     * atau ketiganya sekaligus.
     */
    public function index(Request $request): Response
    {
        $this->authorizeAction();

        $filters = [
            'search' => $request->input('search'),
            'status' => $request->input('status', ShmApplicationStatus::Approval->value),
            'type' => $request->input('type'),
        ];

        $query = $this->scopedQuery()
            // latestOfMany dimuat tanpa daftar kolom: membatasi kolom pada
            // relasi latestOfMany membuat alias join ambigu di SQLite
            // (pola yang sama dengan ShmDocumentsController). Aktor keputusan
            // dimuat sebagai relasi nested dengan kolom terbatas.
            ->with([
                'customer:id,customer_number,name',
                'marketing:id,name',
                'latestMemorandum.creator:id,name',
                'latestApproval.decider:id,name',
            ])
            ->whereIn('status', $this->decisionStatusValues())
            // CATATAN: kondisi when TIDAK boleh dijadikan nilai where. Kondisi
            // gabungan bernilai boolean dan akan terikat sebagai "1" bila
            // closure-nya menerima parameter kedua, sehingga filter menjadi
            // kosong. Nilai filter diambil dari closure tanpa parameter kedua.
            ->when(
                filled($filters['status']) && $filters['status'] !== 'all',
                fn (Builder $q) => $q->where('status', $filters['status'])
            )
            ->when(
                $filters['type'],
                fn (Builder $q) => $q->whereHas(
                    'memorandums',
                    fn (Builder $memo) => $memo->where('type', $filters['type'])
                )
            )
            ->when($filters['search'], function (Builder $q, string $search): void {
                $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%';

                $q->where(function (Builder $inner) use ($term): void {
                    // Pencarian server-side mengikuti pola modul SHM lain:
                    // nomor pengajuan dan master nasabah SHM, bukan tabel anggota.
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
            'submitted_at' => 'submitted_at',
            'updated_at' => 'updated_at',
        ], [['updated_at', 'desc']]);

        $approvals = $query->paginate($this->resolvePerPage($request))
            ->withQueryString()
            ->through(fn (ShmApplication $application): array => $this->summaryRow($application));

        return Inertia::render('Shm/Approvals/Index', [
            'approvals' => $approvals,
            'sort' => $sortStatus,
            'filters' => $filters,
            'statuses' => $this->filterStatusOptions(),
            'types' => ShmMemorandum::typeLabels(),
            // Ticket 57: jumlah approval pending (ShmApproval.status = pending)
            // dalam cakupan kepemilikan yang sama dengan daftar ini. Satu query
            // COUNT, tidak memuat barisnya; hanya dikirim ke pengguna yang sudah
            // lolos middleware `shm.approval.decide`.
            'pending_count' => ShmApproval::pendingCountForUser(
                $this->canViewAllShm(),
                (int) $request->user()->id
            ),
            // Ticket 58: ringkasan antrean approval PER MARKETING.
            // Satu grouped aggregate (marketing_id + COUNT(*)) dari
            // ShmApproval.status = pending yang bergabung dengan
            // shm_applications untuk mendapatkan marketing_id masing-masing
            // pengajuan. Tanpa shm.view_all, hasil hanya berisi
            // marketing_id milik pengguna — sehingga tidak ada yang lain.
            // Marketing dengan 0 pending tidak dimuat. Diurutkan
            // pending_count DESC, nama ASC.
            'marketing_queue' => ShmApproval::pendingCountByMarketing(
                $this->canViewAllShm(),
                (int) $request->user()->id
            ),
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
        ]);
    }

    /**
     * Detail approval satu pengajuan: konteks pengajuan, nasabah, objek,
     * memorandum, referensi credit checking, checklist dokumen read-only,
     * keputusan yang sudah ada, dan riwayat audit.
     *
     * Aksi keputusan tidak ada di sini: form pada halaman ini mengirim ke
     * endpoint pengajuan existing sehingga state machine dan audit tetap
     * milik ShmWorkflowService.
     */
    public function show(Request $request, ShmApplication $application): Response
    {
        $this->authorizeAction();
        $this->authorizeShmOwnership($request, $application);

        $application->load([
            'customer:id,customer_number,name,nik,phone,email,address,is_active',
            'marketing:id,name',
            'properties',
            'latestCreditCheck.checker:id,name',
            'latestMemorandum.creator:id,name',
            'latestApproval.decider:id,name',
            'documents.checker:id,name',
        ]);

        // Materialkan relasi HasOne sekali (pola ShmMemorandumController):
        // latestOfMany tidak di-cache sehingga pemanggilan berulang dapat
        // menghasilkan baris berbeda.
        $creditCheck = $application->latestCreditCheck;
        $memorandum = $application->latestMemorandum;
        $approval = $application->latestApproval;

        return Inertia::render('Shm/Approvals/Show', [
            'application' => [
                'id' => $application->id,
                'application_number' => $application->application_number,
                'status' => $application->status->value,
                'status_label' => $application->status->label(),
                'customer' => $application->customer,
                'customer_label' => $application->customerLabel(),
                'marketing' => $application->marketing?->name,
                'notes' => $application->notes,
                'created_at' => $application->created_at,
                'submitted_at' => $application->submitted_at,
                'approved_at' => $application->approved_at,
                'rejected_at' => $application->rejected_at,
                'updated_at' => $application->updated_at,
            ],
            'properties' => $application->properties,
            'credit_check' => $creditCheck === null ? null : [
                'id' => $creditCheck->id,
                'status_label' => $creditCheck->statusLabel(),
                'status_badge' => $creditCheck->status->badgeKey(),
                'outcome' => $creditCheck->outcome?->value,
                'outcome_label' => $creditCheck->outcome?->label(),
                'outcome_badge' => $creditCheck->outcome?->badgeKey(),
                'checker' => $creditCheck->checker?->name,
                'completed_at' => $creditCheck->checked_at,
                'result' => $creditCheck->result,
            ],
            'memorandum' => $memorandum === null ? null : [
                'id' => $memorandum->id,
                'type' => $memorandum->type,
                'type_label' => $memorandum->typeLabel(),
                'status' => $memorandum->status,
                'status_label' => $memorandum->statusLabel(),
                'notes' => $memorandum->notes,
                'submitted_at' => $memorandum->submitted_at,
                'creator' => $memorandum->creator?->name,
            ],
            'approval' => $approval === null ? null : [
                'id' => $approval->id,
                'status' => $approval->status,
                'decider' => $approval->decider?->name,
                'decided_at' => $approval->decided_at,
                'notes' => $approval->notes,
            ],
            'documents' => $application->documents,
            'documents_summary' => $application->documentsSummary(),
            // Ticket 53: konteks requirement dokumen dipakai apa adanya.
            // Bukan daftar dokumen wajib dan tidak menambah aturan baru.
            'document_requirement_context' => ShmDocument::requirementContextFor($application),
            // Keputusan hanya dapat diambil pada tahap approval oleh pemegang
            // permission existing; UI lain hanya membaca.
            'can_decide' => $application->status === ShmApplicationStatus::Approval
                && Gate::allows('shm.approval.decide'),
            'history' => $this->auditHistory(ShmApplication::class, $application->id),
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
        ]);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * Baris ringkas untuk tabel daftar.
     *
     * Tidak ada kolom nominal: tabel pengajuan SHM memang tidak memiliki
     * kolom finansial, dan modul ini tidak mengarang datanya.
     *
     * @return array<string, mixed>
     */
    private function summaryRow(ShmApplication $application): array
    {
        $memorandum = $application->latestMemorandum;
        $approval = $application->latestApproval;

        return [
            'id' => $application->id,
            'application_number' => $application->application_number,
            'status' => $application->status->value,
            'status_label' => $application->status->label(),
            'customer_label' => $application->customerLabel(),
            'customer_number' => $application->customer?->customer_number,
            'marketing' => $application->marketing?->name,
            'memorandum_type' => $memorandum?->type,
            'memorandum_type_label' => $memorandum?->typeLabel(),
            'submitted_at' => $application->submitted_at,
            'updated_at' => $application->updated_at,
            'decision' => $approval === null ? null : [
                'status' => $approval->status,
                'decided_at' => $approval->decided_at,
                'decider' => $approval->decider?->name,
            ],
        ];
    }

    /**
     * Nilai status yang relevan untuk modul approval.
     *
     * @return array<int, string>
     */
    private function decisionStatusValues(): array
    {
        return array_map(
            static fn (ShmApplicationStatus $status): string => $status->value,
            self::DECISION_STATUSES,
        );
    }

    /**
     * Opsi filter status untuk halaman daftar.
     *
     * Hanya memuat tahap keputusan; tahap workflow lain (draft, credit
     * checking, dan seterusnya) bukan domain modul ini.
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function filterStatusOptions(): array
    {
        return collect(self::DECISION_STATUSES)
            ->map(fn (ShmApplicationStatus $status): array => [
                'value' => $status->value,
                'label' => $status->label(),
            ])
            ->values()
            ->all();
    }

    /**
     * Query pengajuan yang boleh dilihat pengguna.
     *
     * Tanpa `shm.view_all`, pengguna hanya melihat pengajuannya sendiri.
     * Lapisan ini berjalan di balik middleware permission yang sudah ada.
     */
    private function scopedQuery(): Builder
    {
        $query = ShmApplication::query();

        if (! $this->canViewAllShm()) {
            $query->where('marketing_id', auth()->id());
        }

        return $query;
    }

    /**
     * Pemeriksaan permission sederhana yang konsisten dengan pola middleware.
     */
    private function authorizeAction(): void
    {
        abort_unless(
            Gate::allows('shm.approval.decide'),
            403,
            'Anda tidak memiliki hak akses untuk modul approval.'
        );
    }
}
