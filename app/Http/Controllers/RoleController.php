<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRoleRequest;
use App\Http\Requests\UpdateRoleRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Services\AuditLogger;
use App\Services\PermissionRegistry;
use App\Support\PerPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class RoleController extends Controller
{
    public function index(): Response
    {
        $roles = Role::query()
            ->withCount(['users', 'permissions'])
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'display_name' => $role->display_name,
                'description' => $role->description,
                'is_system' => $role->is_system,
                'users_count' => $role->users_count,
                'permissions_count' => $role->permissions_count,
                'is_superadmin' => $role->isSuperadmin(),
            ]);

        return Inertia::render('Pengaturan/Roles/Index', [
            'roles' => $roles,
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Pengaturan/Roles/Create', [
            'modules' => $this->moduleList(),
        ]);
    }

    public function store(StoreRoleRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $role = DB::transaction(function () use ($validated) {
            $role = Role::query()->create([
                'name' => $validated['name'],
                'display_name' => $validated['display_name'],
                'description' => $validated['description'] ?? null,
                'is_system' => false,
            ]);

            $role->permissions()->sync($this->filterValidPermissions($validated['permissions'] ?? []));

            return $role;
        });

        AuditLogger::log(
            'role.created',
            'Role',
            $role->id,
            $role->name,
            'Role '.$role->display_name.' dibuat.',
            ['permissions' => $validated['permissions'] ?? []]
        );

        return redirect()
            ->route('pengaturan.roles.index')
            ->with('success', 'Role '.$role->display_name.' berhasil dibuat.');
    }

    public function edit(Role $role): Response
    {
        return Inertia::render('Pengaturan/Roles/Edit', [
            'role' => [
                'id' => $role->id,
                'name' => $role->name,
                'display_name' => $role->display_name,
                'description' => $role->description,
                'is_system' => $role->is_system,
                'is_superadmin' => $role->isSuperadmin(),
                'permissions' => $role->permissions()->pluck('permissions.name')->all(),
                'users_count' => $role->users()->count(),
            ],
            'modules' => $this->moduleList(),
        ]);
    }

    public function update(UpdateRoleRequest $request, Role $role): RedirectResponse
    {
        $validated = $request->validated();

        $oldPermissions = $role->permissions()->pluck('permissions.name')->all();

        DB::transaction(function () use ($role, $validated) {
            $role->update([
                'display_name' => $validated['display_name'],
                'description' => $validated['description'] ?? $role->description,
            ]);

            if (array_key_exists('permissions', $validated) && ! $role->isSuperadmin()) {
                $role->permissions()->sync($this->filterValidPermissions($validated['permissions'] ?? []));
            }
        });

        $newPermissions = $role->permissions()->pluck('permissions.name')->all();

        AuditLogger::log(
            'role.updated',
            'Role',
            $role->id,
            $role->name,
            'Role '.$role->display_name.' diperbarui.',
            [
                'display_name' => ['old' => $role->getOriginal('display_name'), 'new' => $role->display_name],
                'permissions' => [
                    'old' => $oldPermissions,
                    'new' => $newPermissions,
                ],
            ]
        );

        return redirect()
            ->route('pengaturan.roles.index')
            ->with('success', 'Role '.$role->display_name.' berhasil diperbarui.');
    }

    /**
     * Hapus role. Role sistem, role Superadmin, dan role yang masih dipakai
     * pengguna tidak boleh dihapus.
     */
    public function destroy(Request $request, Role $role): RedirectResponse
    {
        if ($role->is_system || $role->isSuperadmin()) {
            return redirect()
                ->back()
                ->with('error', 'Role sistem tidak dapat dihapus.');
        }

        if ($role->users()->exists()) {
            return redirect()
                ->back()
                ->with('error', 'Role '.$role->display_name.' masih digunakan oleh '.$role->users()->count().' pengguna dan tidak dapat dihapus.');
        }

        $label = $role->display_name;

        DB::transaction(function () use ($role) {
            $role->permissions()->detach();
            $role->delete();
        });

        AuditLogger::log(
            'role.deleted',
            'Role',
            $role->id,
            $role->name,
            'Role '.$label.' dihapus.'
        );

        return redirect()
            ->route('pengaturan.roles.index')
            ->with('success', 'Role '.$label.' berhasil dihapus.');
    }

    /**
     * Halaman peninjauan permission per modul (matriks role x permission).
     */
    public function permissions(): Response
    {
        $roles = Role::query()->with('permissions:id,name')->orderBy('name')->get();
        $registry = app(PermissionRegistry::class);

        return Inertia::render('Pengaturan/Roles/Permissions', [
            'modules' => $this->moduleList(),
            'roles' => $roles->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'display_name' => $role->display_name,
                'is_superadmin' => $role->isSuperadmin(),
                'permissions' => $role->permissions->pluck('name')->all(),
            ]),
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
        ]);
    }

    /**
     * Daftar pengguna per role.
     */
    public function users(Role $role): Response
    {
        $users = $role->users()
            ->orderBy('name')
            ->paginate(PerPage::resolve(request()->input(PerPage::PARAM)))
            ->through(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_active' => $user->is_active,
                'last_login_at' => $user->last_login_at?->format('Y-m-d H:i:s'),
            ]);

        return Inertia::render('Pengaturan/Roles/Users', [
            'role' => [
                'id' => $role->id,
                'name' => $role->name,
                'display_name' => $role->display_name,
                'description' => $role->description,
            ],
            'users' => $users,
        ]);
    }

    /**
     * Registry permission dalam struktur siap-render per modul.
     *
     * @return array<int, mixed>
     */
    private function moduleList(): array
    {
        $modules = [];

        foreach (app(PermissionRegistry::class)->modules() as $module => $definition) {
            $actions = [];

            foreach ($definition['actions'] ?? [] as $action => $description) {
                $actions[] = [
                    'name' => $module.'.'.$action,
                    'action' => $action,
                    'description' => $description,
                ];
            }

            $modules[] = [
                'module' => $module,
                'label' => $definition['label'] ?? $module,
                'actions' => $actions,
            ];
        }

        return $modules;
    }

    /**
     * @param  array<int, string>  $permissions
     * @return array<int, int>
     */
    private function filterValidPermissions(array $permissions): array
    {
        $registry = app(PermissionRegistry::class);

        return collect($permissions)
            ->filter(fn ($name) => $registry->isValid((string) $name))
            ->map(fn ($name) => (int) Permission::query()->where('name', $name)->value('id'))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
