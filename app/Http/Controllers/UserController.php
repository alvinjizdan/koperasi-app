<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\InteractsWithSorting;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    use InteractsWithSorting;

    public function index(Request $request): Response
    {
        $search = trim((string) $request->input('search', ''));
        $role = $request->input('role', 'all');
        $status = $request->input('status', 'all');

        $usersQuery = User::query()
            ->with('roles:id,name,display_name')
            ->withCount('roles')
            ->when($search !== '', function ($q) use ($search) {
                $term = '%'.mb_strtolower($search).'%';

                $q->where(function ($inner) use ($term) {
                    $inner->whereRaw('LOWER(name) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(email) LIKE ?', [$term]);
                });
            })
            ->when($role !== 'all', fn ($q) => $q->whereHas('roles', fn ($r) => $r->where('roles.name', $role)))
            ->when($status !== 'all', fn ($q) => $q->where('is_active', $status === 'active'));

        $sortStatus = $this->applySorting($usersQuery, $request, [
            'name' => 'name',
            'email' => 'email',
            'roles_count' => 'roles_count',
            'is_active' => 'is_active',
            'last_login_at' => 'last_login_at',
            'created_at' => 'created_at',
        ], [['created_at', 'desc']]);

        $users = $usersQuery->paginate($this->resolvePerPage($request))
            ->withQueryString()
            ->through(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_active' => $user->is_active,
                'last_login_at' => $user->last_login_at?->format('Y-m-d H:i:s'),
                'created_at' => $user->created_at?->format('Y-m-d H:i:s'),
                'roles' => $user->roles->map(fn (Role $role) => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'display_name' => $role->display_name,
                ])->all(),
            ]);

        return Inertia::render('Pengaturan/Users/Index', [
            'users' => $users,
            'sort' => $sortStatus,
            'roles' => Role::query()->orderBy('name')->get(['id', 'name', 'display_name']),
            'filters' => [
                'search' => $search,
                'role' => $role,
                'status' => $status,
            ],
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Pengaturan/Users/Create', [
            'roles' => Role::query()->orderBy('name')->get(['id', 'name', 'display_name', 'description']),
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $user = DB::transaction(function () use ($validated) {
            $user = User::query()->create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                // Password di-hash melalui cast 'hashed' bawaan Laravel.
                'password' => $validated['password'],
                'is_active' => $validated['is_active'] ?? true,
            ]);

            $user->roles()->sync($validated['roles'] ?? []);

            return $user;
        });

        AuditLogger::log(
            'user.created',
            'User',
            $user->id,
            $user->email,
            'Akun pengguna '.$user->name.' dibuat dengan '.count($validated['roles'] ?? []).' role.',
            ['roles' => $validated['roles'] ?? [], 'is_active' => $user->is_active ? 'aktif' : 'nonaktif']
        );

        return redirect()
            ->route('pengaturan.users.show', $user->id)
            ->with('success', 'Akun pengguna '.$user->name.' berhasil dibuat.');
    }

    public function show(User $user): Response
    {
        return Inertia::render('Pengaturan/Users/Show', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_active' => $user->is_active,
                'last_login_at' => $user->last_login_at?->format('Y-m-d H:i:s'),
                'created_at' => $user->created_at?->format('Y-m-d H:i:s'),
                'email_verified' => $user->email_verified_at !== null,
                'roles' => $user->roles->map(fn (Role $role) => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'display_name' => $role->display_name,
                    'description' => $role->description,
                ])->all(),
                'permissions' => $user->effectivePermissions()
                    ->map(fn ($p) => ['name' => $p->name, 'display_name' => $p->display_name, 'module' => $p->module])
                    ->groupBy('module'),
                'is_superadmin' => $user->isSuperadmin(),
                'is_last_active_superadmin' => $user->isLastActiveSuperadmin(),
            ],
            'allRoles' => Role::query()->orderBy('name')->get(['id', 'name', 'display_name', 'description']),
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
        ]);
    }

    public function edit(User $user): Response
    {
        return Inertia::render('Pengaturan/Users/Edit', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $validated = $request->validated();

        // Nama dan email adalah identitas akun; perubahan keduanya diaudit.
        // Password tidak diubah melalui form ini (lihat reset terpisah).
        $user->update($validated);

        AuditLogger::logChanges($user, 'user.updated', ['name', 'email'], 'Data akun pengguna diperbarui.');

        return redirect()
            ->route('pengaturan.users.show', $user->id)
            ->with('success', 'Data pengguna '.$user->name.' berhasil diperbarui.');
    }

    /**
     * Aktifkan / nonaktifkan akun pengguna.
     */
    public function toggleActive(Request $request, User $user): RedirectResponse
    {
        $actor = $request->user();

        if ($user->id === $actor->id) {
            return redirect()
                ->back()
                ->with('error', 'Anda tidak dapat menonaktifkan akun Anda sendiri.');
        }

        $newStatus = ! $user->is_active;

        // Jaga agar selalu tersedia minimal satu Superadmin aktif.
        if ($user->is_active && $user->isLastActiveSuperadmin()) {
            return redirect()
                ->back()
                ->with('error', 'Akun ini adalah satu-satunya Superadmin aktif; tidak dapat dinonaktifkan.');
        }

        $user->update(['is_active' => $newStatus]);

        AuditLogger::log(
            $newStatus ? 'user.activated' : 'user.deactivated',
            'User',
            $user->id,
            $user->email,
            'Akun '.$user->name.($newStatus ? ' diaktifkan' : ' dinonaktifkan').'.',
            ['is_active' => ['old' => $newStatus ? 'nonaktif' : 'aktif', 'new' => $newStatus ? 'aktif' : 'nonaktif']]
        );

        return redirect()
            ->back()
            ->with('success', 'Akun '.$user->name.($newStatus ? ' berhasil diaktifkan.' : ' berhasil dinonaktifkan.'));
    }

    /**
     * Reset password oleh administrator: password baru dibangkitkan server,
     * tidak pernah ditampilkan kembali dan tidak dicatat pada audit.
     */
    public function resetPassword(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            // Administrator menetapkan password sementara; wajib diganti pengguna.
            'password' => ['required', 'string', Password::defaults(), 'confirmed'],
        ]);

        $user->update(['password' => $validated['password']]);

        AuditLogger::log(
            'user.password_reset',
            'User',
            $user->id,
            $user->email,
            'Password akun '.$user->name.' direset oleh administrator.',
            null
        );

        return redirect()
            ->back()
            ->with('success', 'Password akun '.$user->name.' berhasil direset.');
    }

    /**
     * Ubah role pengguna. Pengguna tidak dapat mengubah role dirinya sendiri
     * untuk mencegah eskalasi privilege.
     */
    public function updateRoles(Request $request, User $user): RedirectResponse
    {
        $actor = $request->user();

        if ($user->id === $actor->id) {
            return redirect()
                ->back()
                ->with('error', 'Anda tidak dapat mengubah role akun Anda sendiri.');
        }

        $validated = $request->validate([
            'roles' => ['nullable', 'array'],
            'roles.*' => ['integer', 'exists:roles,id'],
        ]);

        $oldRoleIds = $user->roles()->pluck('roles.id')->all();
        $newRoleIds = array_values(array_unique(array_map('intval', $validated['roles'] ?? [])));

        // Akun yang sedang menjadi Superadmin aktif terakhir tidak boleh
        // kehilangan role Superadmin.
        if ($user->is_active && $user->isSuperadmin() && ! in_array($this->superadminRoleId(), $newRoleIds, true)
            && $user->isLastActiveSuperadmin()) {
            return redirect()
                ->back()
                ->with('error', 'Akun ini adalah satu-satunya Superadmin aktif; role Superadmin tidak dapat dilepas.');
        }

        DB::transaction(function () use ($user, $newRoleIds) {
            $user->roles()->sync($newRoleIds);
        });

        $oldRoles = Role::query()->whereIn('id', $oldRoleIds)->pluck('display_name', 'id');
        $newRoles = Role::query()->whereIn('id', $newRoleIds)->pluck('display_name', 'id');

        AuditLogger::log(
            'user.roles_changed',
            'User',
            $user->id,
            $user->email,
            'Role akun '.$user->name.' diubah.',
            [
                'roles' => [
                    'old' => $oldRoles->values()->all(),
                    'new' => $newRoles->values()->all(),
                ],
            ]
        );

        return redirect()
            ->back()
            ->with('success', 'Role pengguna '.$user->name.' berhasil diperbarui.');
    }

    private function superadminRoleId(): ?int
    {
        return Role::query()->where('name', Role::SYSTEM_SUPERADMIN)->value('id');
    }
}
