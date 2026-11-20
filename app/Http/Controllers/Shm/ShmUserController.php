<?php

namespace App\Http\Controllers\Shm;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Shm\ShmAuthorizationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller pengelolaan akses Pengguna ke domain SHM (Ticket 15I).
 *
 * Mengatur penetapan & pencabutan role SHM (entity = makro).
 * TIDAK mengizinkan penonaktifan akun global (users.is_active = false)
 * maupun password reset global; operasi tersebut tetap milik Superadmin.
 */
class ShmUserController extends Controller
{
    public function __construct(
        private readonly ShmAuthorizationService $authService
    ) {}

    public function index(Request $request): Response
    {
        $search = $request->query('search');

        $users = User::query()
            ->when($search, function ($q, $search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->with(['roles' => fn ($q) => $q->where('entity', ShmAuthorizationService::ENTITY_MAKRO)])
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Shm/Settings/Users/Index', [
            'users' => $users->through(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'is_active' => $u->is_active,
                'shm_roles' => $u->roles->map(fn (Role $r) => [
                    'id' => $r->id,
                    'name' => $r->name,
                    'display_name' => $r->display_name,
                    'is_active' => $r->is_active,
                ])->all(),
            ]),
            'filters' => [
                'search' => $search,
            ],
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
        ]);
    }

    public function show(User $user): Response
    {
        $user->load([
            'roles' => fn ($q) => $q->where('entity', ShmAuthorizationService::ENTITY_MAKRO),
        ]);

        $assignableRoles = $this->authService->getAssignableShmRoles()->get(['id', 'name', 'display_name', 'description']);

        return Inertia::render('Shm/Settings/Users/Show', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_active' => $user->is_active,
                'shm_roles' => $user->roles->map(fn (Role $r) => [
                    'id' => $r->id,
                    'name' => $r->name,
                    'display_name' => $r->display_name,
                    'description' => $r->description,
                ])->all(),
            ],
            'assignableRoles' => $assignableRoles,
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
        ]);
    }

    /**
     * Memperbarui penugasan role SHM pada user.
     */
    public function updateRoles(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'roles' => ['nullable', 'array'],
            'roles.*' => ['integer', 'exists:roles,id'],
        ]);

        $newShmRoleIds = $validated['roles'] ?? [];

        $this->authService->syncUserShmRoles($request->user(), $user, $newShmRoleIds);

        AuditLogger::log(
            'shm.user.roles_updated',
            'User',
            $user->id,
            $user->name,
            'Role SHM pengguna '.$user->name.' diperbarui.',
            ['shm_roles' => $newShmRoleIds]
        );

        return redirect()
            ->route('shm.settings.users.index')
            ->with('success', "Hak akses SHM pengguna '{$user->name}' berhasil diperbarui.");
    }
}
