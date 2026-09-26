<?php

namespace App\Http\Controllers;

use App\Http\Requests\RunReconciliationRequest;
use App\Http\Requests\UpdateFindingStatusRequest;
use App\Models\ReconciliationFinding;
use App\Models\ReconciliationRun;
use App\Services\ReconciliationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReconciliationController extends Controller
{
    public function __construct(private ReconciliationService $reconciliationService) {}

    /**
     * Dashboard rekonsiliasi: ringkasan proses terakhir dan temuan terbuka.
     */
    public function index(): Response
    {
        $openFindings = ReconciliationFinding::query()
            ->whereIn('status', [ReconciliationFinding::STATUS_OPEN, ReconciliationFinding::STATUS_UNDER_REVIEW])
            ->selectRaw('module, COUNT(*) AS total')
            ->groupBy('module')
            ->pluck('total', 'module');

        $resolvedFindings = ReconciliationFinding::query()
            ->whereIn('status', [ReconciliationFinding::STATUS_RESOLVED, ReconciliationFinding::STATUS_ACCEPTED_WITH_REASON])
            ->selectRaw('module, COUNT(*) AS total')
            ->groupBy('module')
            ->pluck('total', 'module');

        $byType = ReconciliationFinding::query()
            ->whereIn('status', [ReconciliationFinding::STATUS_OPEN, ReconciliationFinding::STATUS_UNDER_REVIEW])
            ->selectRaw('finding_type, COUNT(*) AS total')
            ->groupBy('finding_type')
            ->pluck('total', 'finding_type');

        return Inertia::render('Rekonsiliasi/Index', [
            'lastRuns' => ReconciliationRun::query()
                ->with('runner:id,name')
                ->latest('id')
                ->limit(5)
                ->get()
                ->map(fn (ReconciliationRun $run) => $this->runToArray($run)),
            'openByModule' => $openFindings,
            'resolvedByModule' => $resolvedFindings,
            'openByType' => $byType,
            'can' => [
                'run' => request()->user()->can('rekonsiliasi.run'),
                'resolve' => request()->user()->can('rekonsiliasi.resolve'),
            ],
        ]);
    }

    /**
     * Form menjalankan rekonsiliasi + daftar proses (server-side pagination).
     */
    public function runs(Request $request): Response
    {
        $runs = ReconciliationRun::query()
            ->with('runner:id,name')
            ->when($request->input('status', 'all') !== 'all', fn ($q) => $q->where('status', $request->input('status')))
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (ReconciliationRun $run) => $this->runToArray($run));

        return Inertia::render('Rekonsiliasi/Runs', [
            'runs' => $runs,
            'filters' => ['status' => $request->input('status', 'all')],
            'can' => [
                'run' => $request->user()->can('rekonsiliasi.run'),
            ],
        ]);
    }

    /**
     * Jalankan proses rekonsiliasi (synchronous pada skala data saat ini).
     */
    public function store(RunReconciliationRequest $request): RedirectResponse
    {
        $run = $this->reconciliationService->run(
            $request->validated('period_start'),
            $request->validated('period_end'),
            $request->validated('modules'),
            $request->user()->id,
        );

        return redirect()
            ->route('rekonsiliasi.runs.show', $run->id)
            ->with('success', 'Rekonsiliasi '.$run->run_number.' selesai: '.$run->findings_count.' temuan.');
    }

    /**
     * Detail satu proses rekonsiliasi beserta temuan-temuannya.
     */
    public function show(ReconciliationRun $run): Response
    {
        $run->load('runner:id,name');

        $findings = $run->findings()
            ->when(request()->input('status', 'all') !== 'all', fn ($q) => $q->where('status', request()->input('status')))
            ->orderByRaw("CASE priority WHEN 'HIGH' THEN 0 WHEN 'MEDIUM' THEN 1 ELSE 2 END")
            ->orderBy('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (ReconciliationFinding $finding) => $this->findingToArray($finding));

        return Inertia::render('Rekonsiliasi/Runs/Show', [
            'run' => $this->runToArray($run, withSummary: true),
            'findings' => $findings,
            'filters' => ['status' => request()->input('status', 'all')],
            'can' => [
                'run' => request()->user()->can('rekonsiliasi.run'),
                'resolve' => request()->user()->can('rekonsiliasi.resolve'),
            ],
        ]);
    }

    /**
     * Daftar seluruh temuan lintas proses (dengan filter status, modul, jenis).
     */
    public function findings(Request $request): Response
    {
        $filters = [
            'status' => (string) $request->input('status', 'all'),
            'module' => (string) $request->input('module', 'all'),
            'finding_type' => (string) $request->input('finding_type', 'all'),
            'priority' => (string) $request->input('priority', 'all'),
        ];

        $findings = ReconciliationFinding::query()
            ->with(['run:id,run_number,status'])
            ->when($filters['status'] !== 'all', fn ($q) => $q->where('status', $filters['status']))
            ->when($filters['module'] !== 'all', fn ($q) => $q->where('module', $filters['module']))
            ->when($filters['finding_type'] !== 'all', fn ($q) => $q->where('finding_type', $filters['finding_type']))
            ->when($filters['priority'] !== 'all', fn ($q) => $q->where('priority', $filters['priority']))
            ->orderByRaw("CASE priority WHEN 'HIGH' THEN 0 WHEN 'MEDIUM' THEN 1 ELSE 2 END")
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (ReconciliationFinding $finding) => $this->findingToArray($finding));

        return Inertia::render('Rekonsiliasi/Findings', [
            'findings' => $findings,
            'filters' => $filters,
            'can' => [
                'resolve' => $request->user()->can('rekonsiliasi.resolve'),
            ],
        ]);
    }

    /**
     * Detail temuan beserta riwayat tindak lanjut.
     */
    public function showFinding(ReconciliationFinding $finding): Response
    {
        $finding->load([
            'run:id,run_number,status,period_start,period_end',
            'followUps.user:id,name',
        ]);

        return Inertia::render('Rekonsiliasi/Findings/Show', [
            'finding' => $this->findingToArray($finding),
            'followUps' => $finding->followUps->map(fn ($fu) => [
                'id' => $fu->id,
                'from_status' => $fu->from_status,
                'to_status' => $fu->to_status,
                'note' => $fu->note,
                'user' => $fu->user !== null ? ['id' => $fu->user->id, 'name' => $fu->user->name] : null,
                'created_at' => $fu->created_at?->format('Y-m-d H:i:s'),
            ]),
            'can' => [
                'resolve' => request()->user()->can('rekonsiliasi.resolve'),
            ],
        ]);
    }

    /**
     * Proses tindak lanjut temuan: ubah status dengan catatan wajib.
     */
    public function updateFinding(UpdateFindingStatusRequest $request, ReconciliationFinding $finding): RedirectResponse
    {
        $updated = $this->reconciliationService->followUp(
            $finding,
            $request->validated('to_status'),
            $request->validated('note'),
            $request->user()->id,
        );

        return redirect()
            ->route('rekonsiliasi.findings.show', $updated->id)
            ->with('success', 'Status temuan diperbarui menjadi '.$updated->status.'.');
    }

    /**
     * @return array<string, mixed>
     */
    private function runToArray(ReconciliationRun $run, bool $withSummary = false): array
    {
        return [
            'id' => $run->id,
            'run_number' => $run->run_number,
            'period_start' => $run->period_start?->format('Y-m-d'),
            'period_end' => $run->period_end?->format('Y-m-d'),
            'modules' => $run->modules,
            'status' => $run->status,
            'checked_rows' => $run->checked_rows,
            'findings_count' => $run->findings_count,
            'summary' => $withSummary ? ($run->summary !== null ? json_decode($run->summary, true) : null) : null,
            'error_message' => $run->error_message,
            'started_at' => $run->started_at?->format('Y-m-d H:i:s'),
            'finished_at' => $run->finished_at?->format('Y-m-d H:i:s'),
            'runner' => $run->runner !== null ? ['id' => $run->runner->id, 'name' => $run->runner->name] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function findingToArray(ReconciliationFinding $finding): array
    {
        return [
            'id' => $finding->id,
            'finding_type' => $finding->finding_type,
            'module' => $finding->module,
            'object_type' => $finding->object_type,
            'object_id' => $finding->object_id,
            'object_label' => $finding->object_label,
            'expected_value' => $finding->expected_value !== null ? round((float) $finding->expected_value, 2) : null,
            'actual_value' => $finding->actual_value !== null ? round((float) $finding->actual_value, 2) : null,
            'variance' => $finding->variance !== null ? round((float) $finding->variance, 2) : null,
            'priority' => $finding->priority,
            'status' => $finding->status,
            'found_at' => $finding->found_at?->format('Y-m-d H:i:s'),
            'run' => $finding->relationLoaded('run') && $finding->run !== null
                ? ['id' => $finding->run->id, 'run_number' => $finding->run->run_number]
                : null,
        ];
    }
}
