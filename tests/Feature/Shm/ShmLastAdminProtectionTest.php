<?php

namespace Tests\Feature\Shm;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Shm\ShmAuthorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ShmLastAdminProtectionTest extends TestCase
{
    use RefreshDatabase;

    private User $shmAdmin;

    private Role $shmAdminRole;

    private Permission $roleUpdatePerm;

    protected function setUp(): void
    {
        parent::setUp();

        // Tidak ada Superadmin aktif di sistem untuk menguji batas delegated admin terakhir
        $this->roleUpdatePerm = Permission::query()->create([
            'name' => 'shm.role.update',
            'module' => 'shm',
            'action' => 'role.update',
            'display_name' => 'Mengelola role SHM',
            'entity' => 'makro',
        ]);

        $this->shmAdminRole = Role::query()->create([
            'name' => 'admin_makro_sole',
            'display_name' => 'Admin Makro Tunggal',
            'entity' => 'makro',
            'is_system' => false,
            'is_active' => true,
        ]);
        $this->shmAdminRole->permissions()->attach($this->roleUpdatePerm->id);

        $this->shmAdmin = User::factory()->create(['is_active' => true]);
        $this->shmAdmin->roles()->attach($this->shmAdminRole->id);
    }

    public function test_cannot_detach_last_shm_admin_role_from_sole_admin(): void
    {
        $authService = app(ShmAuthorizationService::class);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Pencabutan role ditolak karena akan menyebabkan sistem kehilangan administrator aktif terakhir.');

        // Mencoba mencabut admin_makro_sole dari satu-satunya admin
        $authService->syncUserShmRoles($this->shmAdmin, $this->shmAdmin, []);
    }

    public function test_cannot_deactivate_role_held_by_sole_admin(): void
    {
        $authService = app(ShmAuthorizationService::class);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Penonaktifan role ditolak karena akan menghilangkan administrator aktif terakhir.');

        // Mencoba menonaktifkan role admin tunggal
        $authService->updateRole($this->shmAdmin, $this->shmAdminRole, [
            'display_name' => 'Admin Makro Tunggal',
            'is_active' => false,
        ]);
    }

    public function test_non_superadmin_cannot_mutate_shm_role_update_permission(): void
    {
        $authService = app(ShmAuthorizationService::class);

        // Delegated non-superadmin actor attempting to revoke shm.role.update from their role
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Hanya Superadmin yang berwenang menetapkan atau mencabut permission pengelola role SHM (shm.role.update).');

        $authService->syncRolePermissions($this->shmAdmin, $this->shmAdminRole, []);
    }

    public function test_cannot_revoke_shm_role_update_permission_from_sole_admin_role_when_no_active_superadmin_user(): void
    {
        // Setup canonical Superadmin role in database
        $superadminRole = Role::query()->where('id', 1)->first();
        if ($superadminRole) {
            $superadminRole->update([
                'name' => 'superadmin',
                'display_name' => 'Superadmin',
                'entity' => 'shared',
                'is_system' => true,
                'is_active' => true,
            ]);
        } else {
            Role::query()->create([
                'id' => 1,
                'name' => 'superadmin',
                'display_name' => 'Superadmin',
                'entity' => 'shared',
                'is_system' => true,
                'is_active' => true,
            ]);
        }

        // Canonical Superadmin actor is active so $actor->isSuperadmin() returns true
        $superadminUser = User::factory()->create(['is_active' => true]);
        $superadminUser->roles()->attach(1);

        // However, before the transaction asserts remaining admins, if the superadmin role itself has is_active = false
        // then $actor->isSuperadmin() would be false.
        // Instead: if the actor is superadmin, but we want assertWillRetainAdministratorAfterPermissionRevocation
        // to see remaining < 1:
        // Notice: superadminQuery counts:
        // u.is_active = true AND r.is_active = true AND r.name = 'superadmin' AND r.is_system = true AND r.entity = 'shared'
        // If superadminUser is the ONLY user with superadmin role, superadminQuery returns 1 row (admin_user_id = $superadminUser->id).
        // If $superadminUser is detached from role 1 right before assertion?
        // But $actor->isSuperadmin() is evaluated at line 189 in syncRolePermissions!
        // At line 189, if $actor->isSuperadmin() is true, it passes.
        // Then at line 200, assertWillRetainAdministratorAfterPermissionRevocation runs a fresh DB query.
        // If there are no other active superadmins, but $superadminUser IS active and attached to role 1,
        // then superadminQuery finds $superadminUser! Thus remaining is 1, not 0!
        // Why? Because as long as an active Superadmin user exists in the system, removing shm.role.update
        // from a delegated role does NOT remove all administrators from the system! The Superadmin is still an administrator!
        // So the last admin check only fires if there are NO active Superadmins in the system AND we are removing shm.role.update
        // from the last delegated role!
        // But only an active Superadmin can revoke shm.role.update!
        // This is a defense-in-depth safety rail: even if someone bypassed or mocked the actor check, or if a Superadmin
        // user was concurrently deactivated or detached between line 189 and line 200,
        // assertWillRetainAdministratorAfterPermissionRevocation ensures the system never ends up with 0 administrators.
        // We can test assertWillRetainAdministratorAfterPermissionRevocation directly via reflection:
        $authService = app(ShmAuthorizationService::class);
        $method = new \ReflectionMethod(ShmAuthorizationService::class, 'assertWillRetainAdministratorAfterPermissionRevocation');
        $method->setAccessible(true);

        // Ensure no active superadmins in DB
        $superadminUser->roles()->detach();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Pencabutan permission pengelola role ditolak karena akan menghilangkan administrator aktif terakhir.');

        $method->invoke($authService, $this->shmAdminRole->id);
    }
}
