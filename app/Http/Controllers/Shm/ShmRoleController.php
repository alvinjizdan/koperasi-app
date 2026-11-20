<?php

namespace App\Http\Controllers\Shm;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Services\AuditLogger;
use App\Services\Shm\ShmAuthorizationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller pengelolaan Role & Permission dalam domain SHM / BSM Makro (Ticket 15I).
 */
class ShmRoleController extends Controller
{
    public function __construct(
        private readonly ShmAuthorizationService $authService
    ) {}

    public function index(): Response
    {
        $roles = $this->authService->getShmRoles();

        return Inertia::render('Shm/Settings/Roles/Index', [
            'roles' => $roles->map(fn (Role $r) => [
                'id' => $r->id,
                'name' => $r->name,
                'display_name' => $r->display_name,
                'description' => $r->description,
                'entity' => $r->entity,
                'is_system' => $r->is_system,
                'is_active' => $r->is_active,
                'users_count' => $r->users_count,
            ])->all(),
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
        ]);
    }

    public function create(): Response
    {
        $permissions = $this->authService->getAvailableShmPermissions();

        return Inertia::render('Shm/Settings/Roles/Create', [
            'permissions' => $permissions->groupBy('module'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:100',
                'regex:/^[a-z0-9_-]+$/',
                'unique:roles,name,NULL,id,entity,makro',
            ],
            'display_name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:500'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['integer', 'exists:permissions,id'],
        ]);

        $role = $this->authService->createRole(
            $request->user(),
            $validated,
            $validated['permissions'] ?? []
        );

        AuditLogger::log(
            'shm.role.created',
            'Role',
            $role->id,
            $role->display_name,
            'Role SHM '.$role->display_name.' dibuat.',
            ['name' => $role->name, 'entity' => 'makro']
        );

        return redirect()
            ->route('shm.settings.roles.index')
            ->with('success', "Role SHM '{$role->display_name}' berhasil dibuat.");
    }

    public function edit(Role $role): Response
    {
        $this->authService->assertRoleIsShm($role);

        $role->load('permissions:id,name,module,action,display_name');
        $availablePermissions = $this->authService->getAvailableShmPermissions();

        return Inertia::render('Shm/Settings/Roles/Edit', [
            'role' => [
                'id' => $role->id,
                'name' => $role->name,
                'display_name' => $role->display_name,
                'description' => $role->description,
                'entity' => $role->entity,
                'is_system' => $role->is_system,
                'is_active' => $role->is_active,
                'permissions' => $role->permissions->pluck('id')->all(),
            ],
            'permissions' => $availablePermissions->groupBy('module'),
        ]);
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $this->authService->assertRoleIsShm($role);

        $validated = $request->validate([
            'display_name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['nullable', 'boolean'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['integer', 'exists:permissions,id'],
        ]);

        $updatedRole = $this->authService->updateRole($request->user(), $role, $validated);

        if (array_key_exists('permissions', $validated)) {
            $this->authService->syncRolePermissions(
                $request->user(),
                $updatedRole,
                $validated['permissions'] ?? []
            );
        }

        AuditLogger::log(
            'shm.role.updated',
            'Role',
            $role->id,
            $role->display_name,
            'Role SHM '.$role->display_name.' diperbarui.',
            ['entity' => 'makro']
        );

        return redirect()
            ->route('shm.settings.roles.index')
            ->with('success', "Role SHM '{$role->display_name}' berhasil diperbarui.");
    }

    public function destroy(Request $request, Role $role): RedirectResponse
    {
        $this->authService->assertRoleIsShm($role);

        $name = $role->display_name;
        $this->authService->deleteRole($request->user(), $role);

        AuditLogger::log(
            'shm.role.deleted',
            'Role',
            $role->id,
            $name,
            'Role SHM '.$name.' dihapus.'
        );

        return redirect()
            ->route('shm.settings.roles.index')
            ->with('success', "Role SHM '{$name}' berhasil dihapus.");
    }
}
