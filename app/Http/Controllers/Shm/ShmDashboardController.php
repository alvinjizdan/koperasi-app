<?php

namespace App\Http\Controllers\Shm;

use App\Enums\ShmApplicationStatus;
use App\Enums\ShmCreditCheckStatus;
use App\Http\Controllers\Controller;
use App\Models\ShmApplication;
use App\Models\ShmApproval;
use App\Models\ShmCreditCheck;
use App\Models\ShmCustomer;
use App\Models\ShmMemorandum;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dashboard aplikasi SHM (Ticket 48).
 *
 * Shell dashboard yang JUJUR: seluruh angka dihitung dari tabel SHM yang sudah
 * ada (shm_customers, shm_applications, shm_credit_checks). Tidak ada KPI
 * rekaan seperti pendapatan, tingkat approval, atau nilai pencairan, karena
 * data maupun aturan bisnisnya belum ada.
 *
 * Cakupan data mengikuti aturan kepemilikan yang sudah berlaku di modul SHM:
 * pemegang `shm.view_all` (admin/superadmin) melihat seluruh pengajuan,
 * Marketing hanya melihat pengajuan miliknya sendiri. Master nasabah bersifat
 * bersama dan dilindungi permission `shm.customer.view` pada halamannya.
 */
class ShmDashboardController extends Controller
{
    /**
     * Tahap pengajuan saat credit checking relevan dikerjakan.
     *
     * Dipakai untuk menghitung pengajuan yang SUDAH masuk tahap proses tetapi
     * BELUM memiliki baris credit checking — itulah arti "belum dimulai" yang
     * sebenarnya. Tidak ada baris credit checking dummy yang dibuat untuk
     * menghitungnya (lihat Ticket 47).
     *
     * @var array<int, ShmApplicationStatus>
     */
    private const PROCESS_STAGES = [
        ShmApplicationStatus::Submitted,
        ShmApplicationStatus::CreditChecking,
        ShmApplicationStatus::Memorandum,
        ShmApplicationStatus::Approval,
    ];

    public function __invoke(Request $request): Response
    {
        $seesEverything = Gate::allows('shm.view_all');
        $userId = $request->user()->id;

        // Builder dasar; setiap pemakaian memakai clone agar klausa tidak saling
        // menempel (groupBy/selectRaw mengubah builder aslinya).
        $applications = ShmApplication::query()
            ->when(! $seesEverything, fn (Builder $query): Builder => $query->where('marketing_id', $userId));

        $creditChecks = ShmCreditCheck::query()
            ->when(! $seesEverything, fn (Builder $query): Builder => $query->forMarketing($userId));

        $applicationCounts = (clone $applications)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $creditCheckCounts = (clone $creditChecks)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $processStageValues = array_map(
            static fn (ShmApplicationStatus $status): string => $status->value,
            self::PROCESS_STAGES,
        );

        $notStarted = (clone $applications)
            ->whereIn('status', $processStageValues)
            ->whereDoesntHave('creditChecks')
            ->count();

        // -----------------------------------------------------------------
        // Ticket 56 — pemantauan pipeline operasional. Semua angka berikut
        // tetap dibaca dari kolom/status existing; semantics "Masuk" mengikuti
        // Ticket 55 (submitted_at terisi) dan tidak diubah.
        // -----------------------------------------------------------------

        // Masuk: satu aggregate, bukan query per status.
        $masuk = (clone $applications)->whereNotNull('submitted_at')->count();

        // Distribusi memorandum berdasarkan memorandum TERAKHIR tiap
        // pengajuan (semantik yang sama dengan kolom Memorandum pada modul
        // export Ticket 55). Pengajuan tanpa memorandum TIDAK dipaksa masuk
        // kategori mana pun. Subquery MAX(id) per pengajuan menjaga ini
        // sebagai satu grouped query tanpa memuat barisnya.
        $latestMemorandumIds = ShmMemorandum::query()
            ->selectRaw('MAX(id)')
            ->when(! $seesEverything, fn (Builder $query): Builder => $query->whereIn(
                'shm_application_id',
                (clone $applications)->select('id')
            ))
            ->groupBy('shm_application_id');

        $memorandumDistribution = ShmMemorandum::query()
            ->whereIn('id', $latestMemorandumIds)
            ->selectRaw('type, count(*) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        $memorandumTypes = collect(ShmMemorandum::types())
            ->map(fn (string $type): array => [
                'type' => $type,
                'label' => ShmMemorandum::typeLabels()[$type] ?? $type,
                'total' => (int) ($memorandumDistribution[$type] ?? 0),
            ])
            ->values()
            ->all();

        // Pengajuan terbaru yang visible, terbatas 5 baris dengan eager
        // loading minimal (pola summaryRow modul export).
        $recent = (clone $applications)
            ->with([
                'customer:id,customer_number,name',
                'latestMemorandum',
            ])
            ->latest('updated_at')
            ->limit(5)
            ->get()
            ->map(fn (ShmApplication $application): array => [
                'id' => $application->id,
                'application_number' => $application->application_number,
                'customer_label' => $application->customerLabel(),
                'customer_number' => $application->customer?->customer_number,
                'memorandum_type' => $application->latestMemorandum?->type,
                'memorandum_type_label' => $application->latestMemorandum?->typeLabel(),
                'status' => $application->status->value,
                'status_label' => $application->status->label(),
                'updated_at' => $application->updated_at,
            ])
            ->all();

        // Ticket 59 - Marketing Operational Summary
        $marketingSummary = $this->buildMarketingSummary($seesEverything, $userId);

        return Inertia::render('Shm/Dashboard', [
            'scope' => $seesEverything ? 'all' : 'own',
            'metrics' => [
                'customers' => [
                    'total' => ShmCustomer::query()->count(),
                    'active' => ShmCustomer::query()->where('is_active', true)->count(),
                ],
                'applications' => [
                    'total' => (clone $applications)->count(),
                    // Semantics "Masuk" Ticket 55: submitted_at terisi.
                    'masuk' => $masuk,
                    'by_status' => array_map(
                        static fn (ShmApplicationStatus $status): array => [
                            'status' => $status->value,
                            'label' => $status->label(),
                            'total' => (int) ($applicationCounts[$status->value] ?? 0),
                        ],
                        ShmApplicationStatus::cases(),
                    ),
                ],
                'credit_checking' => [
                    'not_started' => $notStarted,
                    'in_progress' => (int) ($creditCheckCounts[ShmCreditCheckStatus::InProgress->value] ?? 0),
                    'completed' => (int) ($creditCheckCounts[ShmCreditCheckStatus::Completed->value] ?? 0),
                ],
                // Ticket 56: distribusi memorandum terakhir per pengajuan.
                // Ticket 57: approval yang menunggu keputusan menurut
                // ShmApproval.status = pending (bukan status pengajuan),
                // scope-safe dan read-only. Hanya dikirim ke pengguna yang
                // memang dapat mengakses antrean approval (permission
                // existing); pengguna lain menerima null sehingga kartu
                // tidak tampil dan angka tidak bocor.
                'pending_approval' => Gate::allows('shm.approval.decide')
                    ? ShmApproval::pendingCountForUser($seesEverything, $userId)
                    : null,
                'memorandum_distribution' => $memorandumTypes,
                // Ticket 56: pengajuan terbaru yang visible (maks 5).
                'recent_applications' => $recent,
                'marketing_summary' => $marketingSummary,
            ],
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
        ]);
    }

    private function buildMarketingSummary(bool $seesEverything, int $userId): array
    {
        // Base applications with ownership
        $appsBase = ShmApplication::query()
            ->select('id', 'marketing_id', 'status', 'submitted_at')
            ->when(! $seesEverything, fn (Builder $q) => $q->where('marketing_id', $userId));

        // If no apps visible, still might need to handle? But "Marketing tanpa application tidak perlu ditampilkan" -
        // but pending approval might be tied to applications. Let us get marketing IDs that have applications or have pending approvals.
        $marketingIdsFromApps = (clone $appsBase)->distinct()->pluck('marketing_id')->all();

        // Also get marketing IDs that have pending approvals (respecting ownership)
        $pendingApprovalMarketingIds = ShmApproval::query()
            ->where('shm_approvals.status', ShmApproval::STATUS_PENDING)
            ->join('shm_applications', 'shm_approvals.shm_application_id', '=', 'shm_applications.id')
            ->when(! $seesEverything, fn (Builder $q) => $q->where('shm_applications.marketing_id', $userId))
            ->distinct()
            ->pluck('shm_applications.marketing_id')
            ->all();

        $allMarketingIds = array_unique(array_merge($marketingIdsFromApps, $pendingApprovalMarketingIds));

        if (empty($allMarketingIds)) {
            return [];
        }

        // Application aggregates per marketing
        $appAgg = ShmApplication::query()
            ->when(! $seesEverything, fn (Builder $q) => $q->where('marketing_id', $userId))
            ->selectRaw('marketing_id')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN submitted_at IS NOT NULL THEN 1 ELSE 0 END) as masuk')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as credit_checking', [ShmApplicationStatus::CreditChecking->value])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as memorandum', [ShmApplicationStatus::Memorandum->value])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as approval', [ShmApplicationStatus::Approval->value])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as approved', [ShmApplicationStatus::Approved->value])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as rejected', [ShmApplicationStatus::Rejected->value])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as document_check', [ShmApplicationStatus::DocumentCheck->value])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as ready_for_disbursement', [ShmApplicationStatus::ReadyForDisbursement->value])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as disbursed', [ShmApplicationStatus::Disbursed->value])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as completed', [ShmApplicationStatus::Completed->value])
            ->groupBy('marketing_id')
            ->get()
            ->keyBy('marketing_id');

        // Pending approval aggregates per marketing
        $pendingAgg = ShmApproval::query()
            ->selectRaw('shm_applications.marketing_id, COUNT(*) as pending_count')
            ->where('shm_approvals.status', ShmApproval::STATUS_PENDING)
            ->join('shm_applications', 'shm_approvals.shm_application_id', '=', 'shm_applications.id')
            ->when(! $seesEverything, fn (Builder $q) => $q->where('shm_applications.marketing_id', $userId))
            ->groupBy('shm_applications.marketing_id')
            ->get()
            ->keyBy('marketing_id');

        // Marketing names
        $names = User::query()
            ->whereIn('id', $allMarketingIds)
            ->pluck('name', 'id');

        // Merge
        $result = collect($allMarketingIds)
            ->map(function (int $marketingId) use ($appAgg, $pendingAgg, $names) {
                $appRow = $appAgg->get($marketingId);
                $pendingRow = $pendingAgg->get($marketingId);

                return [
                    'marketing_id' => $marketingId,
                    'marketing_name' => $names[$marketingId] ?? '-',
                    'total' => (int) ($appRow?->total ?? 0),
                    'masuk' => (int) ($appRow?->masuk ?? 0),
                    'credit_checking' => (int) ($appRow?->credit_checking ?? 0),
                    'memorandum' => (int) ($appRow?->memorandum ?? 0),
                    'approval' => (int) ($appRow?->approval ?? 0),
                    'approved' => (int) ($appRow?->approved ?? 0),
                    'rejected' => (int) ($appRow?->rejected ?? 0),
                    'document_check' => (int) ($appRow?->document_check ?? 0),
                    'ready_for_disbursement' => (int) ($appRow?->ready_for_disbursement ?? 0),
                    'disbursed' => (int) ($appRow?->disbursed ?? 0),
                    'completed' => (int) ($appRow?->completed ?? 0),
                    'pending_approval' => (int) ($pendingRow?->pending_count ?? 0),
                ];
            })
            ->filter(fn ($item) => $item['total'] > 0 || $item['pending_approval'] > 0) // only show if has data
            ->sort(function ($a, $b) {
                if ($a['total'] !== $b['total']) {
                    return $b['total'] <=> $a['total']; // desc
                }

                return Str::lower($a['marketing_name']) <=> Str::lower($b['marketing_name']); // asc
            })
            ->values()
            ->all();

        return $result;
    }
}
