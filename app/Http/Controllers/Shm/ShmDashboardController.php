<?php

namespace App\Http\Controllers\Shm;

use App\Enums\ShmApplicationStatus;
use App\Enums\ShmCreditCheckStatus;
use App\Http\Controllers\Controller;
use App\Models\ShmApplication;
use App\Models\ShmCreditCheck;
use App\Models\ShmCustomer;
use App\Models\ShmMemorandum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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
                'memorandum_distribution' => $memorandumTypes,
                // Ticket 56: pengajuan terbaru yang visible (maks 5).
                'recent_applications' => $recent,
            ],
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
        ]);
    }
}
