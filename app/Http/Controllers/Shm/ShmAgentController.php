<?php

namespace App\Http\Controllers\Shm;

use App\Http\Controllers\Concerns\InteractsWithSorting;
use App\Http\Controllers\Controller;
use App\Http\Requests\Shm\StoreShmAgentRequest;
use App\Http\Requests\Shm\UpdateShmAgentRequest;
use App\Models\ShmAgent;
use App\Services\Shm\ShmAgentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ShmAgentController extends Controller
{
    use InteractsWithSorting;

    public function __construct(
        private readonly ShmAgentService $agentService,
    ) {}

    /**
     * Tampilkan daftar Agen SHM dengan pencarian, filter status, dan pagination.
     */
    public function index(Request $request): Response
    {
        $filters = [
            'search' => $request->input('search'),
            'status' => $request->input('status', 'all'),
        ];

        $query = ShmAgent::query()
            ->search($filters['search'])
            ->status($filters['status'])
            ->withCount('loanApplications');

        $sortStatus = $this->applySorting($query, $request, [
            'code' => 'code',
            'name' => 'name',
            'nik' => 'nik',
            'is_active' => 'is_active',
            'created_at' => 'created_at',
        ], [['created_at', 'desc']]);

        $agents = $query->paginate($this->resolvePerPage($request))
            ->withQueryString()
            ->through(fn (ShmAgent $agent): array => [
                'id' => $agent->id,
                'code' => $agent->code,
                'name' => $agent->name,
                'nik' => $agent->nik,
                'is_active' => $agent->is_active,
                'applications_count' => $agent->loan_applications_count ?? 0,
                'created_at' => $agent->created_at?->toIso8601String(),
            ]);

        $user = $request->user();

        return Inertia::render('Shm/Agents/Index', [
            'agents' => $agents,
            'filters' => $filters,
            'sort' => $sortStatus,
            'permissions' => [
                'can_create' => $user?->hasPermission('shm.agent.create') ?? false,
                'can_update' => $user?->hasPermission('shm.agent.update') ?? false,
                'can_delete' => $user?->hasPermission('shm.agent.delete') ?? false,
            ],
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
        ]);
    }

    /**
     * Form tambah agen baru.
     */
    public function create(): Response
    {
        return Inertia::render('Shm/Agents/Create');
    }

    /**
     * Simpan agen baru.
     */
    public function store(StoreShmAgentRequest $request): RedirectResponse
    {
        $agent = $this->agentService->create(
            $request->validated(),
            $request->user()?->id,
        );

        return redirect()
            ->route('shm.agents.index')
            ->with('success', sprintf('Agen %s (%s) berhasil ditambahkan.', $agent->name, $agent->code));
    }

    /**
     * Form edit agen.
     */
    public function edit(ShmAgent $agent): Response
    {
        return Inertia::render('Shm/Agents/Edit', [
            'agent' => [
                'id' => $agent->id,
                'code' => $agent->code,
                'name' => $agent->name,
                'nik' => $agent->nik,
                'is_active' => $agent->is_active,
            ],
        ]);
    }

    /**
     * Update data agen (kode tidak berubah).
     */
    public function update(UpdateShmAgentRequest $request, ShmAgent $agent): RedirectResponse
    {
        $this->agentService->update(
            $agent,
            $request->validated(),
            $request->user()?->id,
        );

        return redirect()
            ->route('shm.agents.index')
            ->with('success', sprintf('Data agen %s berhasil diperbarui.', $agent->code));
    }

    /**
     * Aktifkan atau nonaktifkan agen.
     */
    public function toggleActive(Request $request, ShmAgent $agent): RedirectResponse
    {
        $isActive = $request->boolean('is_active');

        $this->agentService->setActive($agent, $isActive, $request->user()?->id);

        return back()->with(
            'success',
            $isActive
                ? sprintf('Agen %s berhasil diaktifkan.', $agent->code)
                : sprintf('Agen %s berhasil dinonaktifkan.', $agent->code),
        );
    }

    /**
     * Hapus agen (hanya jika belum memiliki relasi pengajuan pinjaman).
     */
    public function destroy(Request $request, ShmAgent $agent): RedirectResponse
    {
        try {
            $this->agentService->delete($agent, $request->user()?->id);

            return redirect()
                ->route('shm.agents.index')
                ->with('success', sprintf('Agen %s berhasil dihapus.', $agent->code));
        } catch (ValidationException $exception) {
            return back()->with('error', $exception->getMessage());
        }
    }
}
