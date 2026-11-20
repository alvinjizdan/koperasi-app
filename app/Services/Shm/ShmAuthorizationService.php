<?php

namespace App\Services\Shm;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Service otorisasi terisolasi untuk domain SHM / BSM Makro (Ticket 15I).
 *
 * Mengelola role, user role assignment, protected permission shm.role.update,
 * dan evaluasi Last Administrator dengan proteksi pg_advisory_xact_lock.
 */
class ShmAuthorizationService
{
    public const ADMIN_MUTATION_LOCK_KEY = 99887766;

    public const PROTECTED_PERMISSION = 'shm.role.update';

    public const ENTITY_MAKRO = 'makro';

    public const ENTITY_SHARED = 'shared';

    /**
     * Memperoleh daftar role yang menjadi kewenangan domain SHM (entity = makro).
     *
     * @return Collection<int, Role>
     */
    public function getShmRoles(): Collection
    {
        return Role::query()
            ->where('entity', self::ENTITY_MAKRO)
            ->withCount('users')
            ->orderBy('name')
            ->get();
    }

    /**
     * Role aktif entity makro yang dapat ditugaskan ke user dalam domain SHM.
     */
    public function getAssignableShmRoles(): Builder
    {
        return Role::query()
            ->where('entity', self::ENTITY_MAKRO)
            ->where('is_active', true)
            ->orderBy('display_name');
    }

    /**
     * Seluruh permission yang dapat ditugaskan ke role SHM (entity = makro).
     *
     * @return Collection<int, Permission>
     */
    public function getAvailableShmPermissions(): Collection
    {
        return Permission::query()
            ->where('entity', self::ENTITY_MAKRO)
            ->orderBy('module')
            ->orderBy('action')
            ->get();
    }

    /**
     * Membuat role SHM baru (harus entity = makro).
     *
     * @param  array{name: string, display_name: string, description?: string|null}  $data
     * @param  array<int, int>  $permissionIds
     */
    public function createRole(User $actor, array $data, array $permissionIds = []): Role
    {
        return DB::transaction(function () use ($actor, $data, $permissionIds): Role {
            $this->acquireAdvisoryLock();

            // Validasi nama & entity
            $role = Role::query()->create([
                'name' => $data['name'],
                'display_name' => $data['display_name'],
                'description' => $data['description'] ?? null,
                'entity' => self::ENTITY_MAKRO,
                'is_system' => false,
                'is_active' => true,
            ]);

            if ($permissionIds !== []) {
                $this->syncRolePermissions($actor, $role, $permissionIds);
            }

            return $role;
        });
    }

    /**
     * Memperbarui metadata role SHM.
     *
     * @param  array{display_name: string, description?: string|null, is_active?: bool}  $data
     */
    public function updateRole(User $actor, Role $role, array $data): Role
    {
        return DB::transaction(function () use ($role, $data): Role {
            $this->acquireAdvisoryLock();

            $this->assertRoleIsShm($role);

            // Jika role hendak dinonaktifkan, jalankan Last Admin check
            if (isset($data['is_active']) && ! (bool) $data['is_active'] && $role->is_active) {
                $this->assertWillRetainAdministratorAfterRoleDeactivation($role->id);
            }

            $updatePayload = [
                'display_name' => $data['display_name'],
                'description' => $data['description'] ?? null,
            ];

            if (isset($data['is_active']) && ! $role->is_system) {
                $updatePayload['is_active'] = (bool) $data['is_active'];
            }

            $role->update($updatePayload);

            return $role;
        });
    }

    /**
     * Menghapus role SHM (hanya role custom non-sistem yang belum memiliki user).
     */
    public function deleteRole(User $actor, Role $role): void
    {
        DB::transaction(function () use ($role): void {
            $this->acquireAdvisoryLock();

            $this->assertRoleIsShm($role);

            if ($role->is_system) {
                throw ValidationException::withMessages([
                    'role' => ['Role sistem tidak dapat dihapus.'],
                ]);
            }

            if ($role->users()->exists()) {
                throw ValidationException::withMessages([
                    'role' => ['Role yang masih memiliki pengguna terdaftar tidak dapat dihapus.'],
                ]);
            }

            $role->permissions()->detach();
            $role->delete();
        });
    }

    /**
     * Sinkronisasi permission pada role SHM.
     *
     * @param  array<int, int>  $permissionIds
     */
    public function syncRolePermissions(User $actor, Role $role, array $permissionIds): void
    {
        DB::transaction(function () use ($actor, $role, $permissionIds): void {
            $this->acquireAdvisoryLock();

            $this->assertRoleIsShm($role);

            // Validasi seluruh permission yang diberikan adalah entity 'makro'
            $permissions = Permission::query()->whereIn('id', $permissionIds)->get();

            if ($permissions->count() !== count($permissionIds)) {
                throw ValidationException::withMessages([
                    'permissions' => ['Satu atau lebih permission yang dipilih tidak valid.'],
                ]);
            }

            foreach ($permissions as $permission) {
                if ($permission->entity !== self::ENTITY_MAKRO) {
                    throw ValidationException::withMessages([
                        'permissions' => ["Permission '{$permission->name}' bukan milik domain SHM (makro)."],
                    ]);
                }
            }

            // Aturan Protected Permission: shm.role.update
            $hasProtected = $permissions->contains('name', self::PROTECTED_PERMISSION);
            $hadProtected = $role->permissions()->where('name', self::PROTECTED_PERMISSION)->exists();

            if (($hasProtected || $hadProtected) && ! $actor->isSuperadmin()) {
                // Hanya Superadmin yang boleh memberi atau mencabut shm.role.update
                if ($hasProtected !== $hadProtected) {
                    throw ValidationException::withMessages([
                        'permissions' => ['Hanya Superadmin yang berwenang menetapkan atau mencabut permission pengelola role SHM (shm.role.update).'],
                    ]);
                }
            }

            // Jika shm.role.update dicabut dari role ini, jalankan Last Admin check
            if ($hadProtected && ! $hasProtected) {
                $this->assertWillRetainAdministratorAfterPermissionRevocation($role->id);
            }

            $role->permissions()->sync($permissionIds);
        });
    }

    /**
     * Sinkronisasi penugasan role SHM pada user.
     *
     * @param  array<int, int>  $shmRoleIds
     */
    public function syncUserShmRoles(User $actor, User $target, array $shmRoleIds): void
    {
        DB::transaction(function () use ($actor, $target, $shmRoleIds): void {
            $this->acquireAdvisoryLock();

            // Lock baris target user di tabel users untuk sinkronisasi dengan mutasi finansial
            DB::table('users')->where('id', $target->id)->lockForUpdate()->first();

            // Validasi semua role target adalah entity 'makro'
            $newRoles = Role::query()->whereIn('id', $shmRoleIds)->get();

            if ($newRoles->count() !== count($shmRoleIds)) {
                throw ValidationException::withMessages([
                    'roles' => ['Satu atau lebih role SHM tidak ditemukan.'],
                ]);
            }

            foreach ($newRoles as $role) {
                if ($role->entity !== self::ENTITY_MAKRO) {
                    throw ValidationException::withMessages([
                        'roles' => ["Role '{$role->name}' bukan role domain SHM (makro)."],
                    ]);
                }
                if (! $role->is_active) {
                    throw ValidationException::withMessages([
                        'roles' => ["Role non-aktif '{$role->display_name}' tidak dapat ditugaskan."],
                    ]);
                }
            }

            // T105: Actor non-superadmin tidak boleh menugaskan role yang memiliki privilege di atas dirinya
            if (! $actor->isSuperadmin()) {
                $actorHeld = $actor->effectivePermissions()->pluck('name')->all();

                foreach ($newRoles as $r) {
                    foreach ($r->permissions as $p) {
                        if (! in_array($p->name, $actorHeld, true)) {
                            throw ValidationException::withMessages([
                                'roles' => ["Role '{$r->display_name}' memiliki permission ({$p->name}) di luar kewenangan Anda."],
                            ]);
                        }
                    }
                }
            }

            // Ambil role SHM yang saat ini dimiliki target
            $currentShmRoleIds = $target->roles()
                ->where('entity', self::ENTITY_MAKRO)
                ->pluck('roles.id')
                ->all();

            $revokedRoleIds = array_diff($currentShmRoleIds, $shmRoleIds);

            // Jika ada role SHM yang dicabut, evaluasi Last Admin
            foreach ($revokedRoleIds as $revokedId) {
                $this->assertWillRetainAdministratorAfterRoleDetachment($target->id, $revokedId);
            }

            // Lepaskan role SHM lama yang tidak ada di daftar baru
            if ($revokedRoleIds !== []) {
                $target->roles()->detach($revokedRoleIds);
            }

            // Tambahkan role SHM baru
            $addedRoleIds = array_diff($shmRoleIds, $currentShmRoleIds);
            if ($addedRoleIds !== []) {
                $target->roles()->attach($addedRoleIds);
            }
        });
    }

    /**
     * Memastikan role berada dalam entity 'makro'.
     */
    public function assertRoleIsShm(Role $role): void
    {
        if ($role->entity !== self::ENTITY_MAKRO) {
            throw ValidationException::withMessages([
                'role' => ["Role '{$role->name}' bukan milik domain SHM."],
            ]);
        }
    }

    /**
     * Mengakuisisi PostgreSQL advisory lock level transaksi jika driver pgsql.
     */
    public function acquireAdvisoryLock(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('SELECT pg_advisory_xact_lock(?)', [self::ADMIN_MUTATION_LOCK_KEY]);
        }
    }

    /**
     * Menghitung administrator aktif yang tersisa jika target user dikecualikan.
     */
    public function countEffectiveAdminsExcludingUser(int $excludedUserId): int
    {
        // 1. Superadmin users (active user, role name superadmin, is_system true, entity shared)
        $superadminQuery = DB::table('users as u')
            ->join('role_user as ru', 'ru.user_id', '=', 'u.id')
            ->join('roles as r', 'r.id', '=', 'ru.role_id')
            ->where('u.id', '<>', $excludedUserId)
            ->where('u.is_active', true)
            ->where('r.is_active', true)
            ->where('r.name', 'superadmin')
            ->where('r.is_system', true)
            ->where('r.entity', self::ENTITY_SHARED)
            ->select('u.id as admin_user_id');

        // 2. Delegated SHM administrators (active user, role makro, permission makro shm.role.update)
        $delegatedQuery = DB::table('users as u')
            ->join('role_user as ru', 'ru.user_id', '=', 'u.id')
            ->join('roles as r', 'r.id', '=', 'ru.role_id')
            ->join('permission_role as pr', 'pr.role_id', '=', 'r.id')
            ->join('permissions as p', 'p.id', '=', 'pr.permission_id')
            ->where('u.id', '<>', $excludedUserId)
            ->where('u.is_active', true)
            ->where('r.is_active', true)
            ->where('r.entity', self::ENTITY_MAKRO)
            ->where('p.entity', self::ENTITY_MAKRO)
            ->where('p.name', self::PROTECTED_PERMISSION)
            ->select('u.id as admin_user_id');

        $union = $superadminQuery->union($delegatedQuery);

        return (int) DB::query()->fromSub($union, 'active_admins')->count();
    }

    /**
     * Menghitung administrator aktif yang tersisa jika role tertentu dicabut dari user.
     */
    public function countEffectiveAdminsAfterRoleDetachment(int $targetUserId, int $revokedRoleId): int
    {
        $superadminQuery = DB::table('users as u')
            ->join('role_user as ru', 'ru.user_id', '=', 'u.id')
            ->join('roles as r', 'r.id', '=', 'ru.role_id')
            ->where('u.is_active', true)
            ->where('r.is_active', true)
            ->whereNot(function ($query) use ($targetUserId, $revokedRoleId) {
                $query->where('u.id', $targetUserId)
                    ->where('ru.role_id', $revokedRoleId);
            })
            ->where('r.name', 'superadmin')
            ->where('r.is_system', true)
            ->where('r.entity', self::ENTITY_SHARED)
            ->select('u.id as admin_user_id');

        $delegatedQuery = DB::table('users as u')
            ->join('role_user as ru', 'ru.user_id', '=', 'u.id')
            ->join('roles as r', 'r.id', '=', 'ru.role_id')
            ->join('permission_role as pr', 'pr.role_id', '=', 'r.id')
            ->join('permissions as p', 'p.id', '=', 'pr.permission_id')
            ->where('u.is_active', true)
            ->where('r.is_active', true)
            ->where('r.entity', self::ENTITY_MAKRO)
            ->where('p.entity', self::ENTITY_MAKRO)
            ->where('p.name', self::PROTECTED_PERMISSION)
            ->whereNot(function ($query) use ($targetUserId, $revokedRoleId) {
                $query->where('u.id', $targetUserId)
                    ->where('ru.role_id', $revokedRoleId);
            })
            ->select('u.id as admin_user_id');

        $union = $superadminQuery->union($delegatedQuery);

        return (int) DB::query()->fromSub($union, 'active_admins')->count();
    }

    private function assertWillRetainAdministratorAfterRoleDetachment(int $targetUserId, int $revokedRoleId): void
    {
        $remaining = $this->countEffectiveAdminsAfterRoleDetachment($targetUserId, $revokedRoleId);
        if ($remaining < 1) {
            throw ValidationException::withMessages([
                'roles' => ['Pencabutan role ditolak karena akan menyebabkan sistem kehilangan administrator aktif terakhir.'],
            ]);
        }
    }

    private function assertWillRetainAdministratorAfterRoleDeactivation(int $deactivatedRoleId): void
    {
        // Hitung admin jika role ini dinonaktifkan
        $superadminQuery = DB::table('users as u')
            ->join('role_user as ru', 'ru.user_id', '=', 'u.id')
            ->join('roles as r', 'r.id', '=', 'ru.role_id')
            ->where('u.is_active', true)
            ->where('r.is_active', true)
            ->where('r.id', '<>', $deactivatedRoleId)
            ->where('r.name', 'superadmin')
            ->where('r.is_system', true)
            ->where('r.entity', self::ENTITY_SHARED)
            ->select('u.id as admin_user_id');

        $delegatedQuery = DB::table('users as u')
            ->join('role_user as ru', 'ru.user_id', '=', 'u.id')
            ->join('roles as r', 'r.id', '=', 'ru.role_id')
            ->join('permission_role as pr', 'pr.role_id', '=', 'r.id')
            ->join('permissions as p', 'p.id', '=', 'pr.permission_id')
            ->where('u.is_active', true)
            ->where('r.is_active', true)
            ->where('r.id', '<>', $deactivatedRoleId)
            ->where('r.entity', self::ENTITY_MAKRO)
            ->where('p.entity', self::ENTITY_MAKRO)
            ->where('p.name', self::PROTECTED_PERMISSION)
            ->select('u.id as admin_user_id');

        $union = $superadminQuery->union($delegatedQuery);
        $remaining = (int) DB::query()->fromSub($union, 'active_admins')->count();

        if ($remaining < 1) {
            throw ValidationException::withMessages([
                'is_active' => ['Penonaktifan role ditolak karena akan menghilangkan administrator aktif terakhir.'],
            ]);
        }
    }

    private function assertWillRetainAdministratorAfterPermissionRevocation(int $roleId): void
    {
        // Hitung apakah masih ada Superadmin atau role makro lain dengan shm.role.update
        $superadminQuery = DB::table('users as u')
            ->join('role_user as ru', 'ru.user_id', '=', 'u.id')
            ->join('roles as r', 'r.id', '=', 'ru.role_id')
            ->where('u.is_active', true)
            ->where('r.is_active', true)
            ->where('r.name', 'superadmin')
            ->where('r.is_system', true)
            ->where('r.entity', self::ENTITY_SHARED)
            ->select('u.id as admin_user_id');

        $delegatedQuery = DB::table('users as u')
            ->join('role_user as ru', 'ru.user_id', '=', 'u.id')
            ->join('roles as r', 'r.id', '=', 'ru.role_id')
            ->join('permission_role as pr', 'pr.role_id', '=', 'r.id')
            ->join('permissions as p', 'p.id', '=', 'pr.permission_id')
            ->where('u.is_active', true)
            ->where('r.is_active', true)
            ->where('r.id', '<>', $roleId) // Kecualikan role ini dari pemegang shm.role.update
            ->where('r.entity', self::ENTITY_MAKRO)
            ->where('p.entity', self::ENTITY_MAKRO)
            ->where('p.name', self::PROTECTED_PERMISSION)
            ->select('u.id as admin_user_id');

        $union = $superadminQuery->union($delegatedQuery);
        $remaining = (int) DB::query()->fromSub($union, 'active_admins')->count();

        if ($remaining < 1) {
            throw ValidationException::withMessages([
                'permissions' => ['Pencabutan permission pengelola role ditolak karena akan menghilangkan administrator aktif terakhir.'],
            ]);
        }
    }

    /**
     * Preflight validator untuk canonical Superadmin.
     * Fail-fast jika database berada dalam kondisi tidak konsisten atau duplikat.
     *
     * @throws ValidationException
     */
    public function validateSuperadminPreflight(): bool
    {
        $id1Role = Role::query()->where('id', 1)->first();

        // 1. Apakah ID 1 ada?
        if (! $id1Role) {
            throw ValidationException::withMessages([
                'superadmin' => ['PREFLIGHT_ABORT: Role ID 1 tidak ditemukan pada database.'],
            ]);
        }

        // 2. Apakah ID 1 memenuhi seluruh atribut 4-faktor?
        if ($id1Role->name !== 'superadmin') {
            throw ValidationException::withMessages([
                'superadmin' => ["PREFLIGHT_ABORT: Role ID 1 ('{$id1Role->name}') tidak memenuhi canonical identity Superadmin (name=superadmin)."],
            ]);
        }

        if (! (bool) $id1Role->is_active) {
            throw ValidationException::withMessages([
                'superadmin' => ["PREFLIGHT_ABORT: Role ID 1 ('{$id1Role->name}') tidak aktif (is_active=false). Canonical Superadmin wajib aktif."],
            ]);
        }

        if ($id1Role->entity !== self::ENTITY_SHARED) {
            throw ValidationException::withMessages([
                'superadmin' => ["PREFLIGHT_ABORT: Role ID 1 ('{$id1Role->name}') memiliki entity '{$id1Role->entity}' (seharusnya 'shared')."],
            ]);
        }

        if (! (bool) $id1Role->is_system) {
            throw ValidationException::withMessages([
                'superadmin' => ["PREFLIGHT_ABORT: Role ID 1 ('{$id1Role->name}') bukan role sistem (is_system=false)."],
            ]);
        }

        // 3. Apakah ada role lain bernama 'superadmin' di luar ID 1?
        $duplicateRolesCount = Role::query()->where('name', 'superadmin')->where('id', '<>', 1)->count();
        if ($duplicateRolesCount > 0) {
            throw ValidationException::withMessages([
                'superadmin' => ["PREFLIGHT_ABORT: Terdeteksi {$duplicateRolesCount} role duplikat bernama 'superadmin' di luar ID 1."],
            ]);
        }

        return true;
    }
}
