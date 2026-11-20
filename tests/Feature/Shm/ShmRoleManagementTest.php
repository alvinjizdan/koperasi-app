<?php

namespace Tests\Feature\Shm;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShmRoleManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    private User $shmAdmin;

    private Role $shmAdminRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superadminRole = Role::query()->firstOrCreate(
            ['name' => 'superadmin'],
            [
                'display_name' => 'Superadmin',
                'entity' => 'shared',
                'is_system' => true,
                'is_active' => true,
            ]
        );
        $this->superadmin = User::factory()->create(['is_active' => true]);
        $this->superadmin->roles()->attach($this->superadminRole->id);

        // Create shm.role.update permission
        $roleUpdatePerm = Permission::query()->create([
            'name' => 'shm.role.update',
            'module' => 'shm',
            'action' => 'role.update',
            'display_name' => 'Mengelola role SHM',
            'entity' => 'makro',
        ]);

        // Create other makro permissions
        Permission::query()->create([
            'name' => 'shm.customer.view',
            'module' => 'shm',
            'action' => 'customer.view',
            'display_name' => 'Lihat Nasabah',
            'entity' => 'makro',
        ]);

        Permission::query()->create([
            'name' => 'anggota.view',
            'module' => 'anggota',
            'action' => 'view',
            'display_name' => 'Lihat Anggota Mikro',
            'entity' => 'mikro',
        ]);

        // Delegated SHM Admin
        $this->shmAdminRole = Role::query()->create([
            'name' => 'admin_makro_custom',
            'display_name' => 'Admin Makro',
            'entity' => 'makro',
            'is_system' => false,
            'is_active' => true,
        ]);
        $this->shmAdminRole->permissions()->attach($roleUpdatePerm->id);

        $this->shmAdmin = User::factory()->create(['is_active' => true]);
        $this->shmAdmin->roles()->attach($this->shmAdminRole->id);
    }

    public function test_shm_role_index_displays_only_makro_roles(): void
    {
        Role::query()->create([
            'name' => 'teller_mikro',
            'display_name' => 'Teller Mikro',
            'entity' => 'mikro',
            'is_system' => false,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->shmAdmin)
            ->get(route('shm.settings.roles.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Shm/Settings/Roles/Index')
            ->where('roles', fn ($roles) => collect($roles)->contains('name', 'admin_makro_custom')
                && ! collect($roles)->contains('name', 'teller_mikro'))
        );
    }

    public function test_create_shm_role_enforces_entity_makro(): void
    {
        $response = $this->actingAs($this->shmAdmin)
            ->post(route('shm.settings.roles.store'), [
                'name' => 'surveyor_shm',
                'display_name' => 'Surveyor SHM',
                'description' => 'Surveyor operasional',
            ]);

        $response->assertRedirect(route('shm.settings.roles.index'));

        $this->assertDatabaseHas('roles', [
            'name' => 'surveyor_shm',
            'entity' => 'makro',
            'is_system' => false,
        ]);
    }

    public function test_cannot_assign_mikro_permission_to_shm_role(): void
    {
        $mikroPerm = Permission::query()->where('name', 'anggota.view')->first();
        $role = Role::query()->create([
            'name' => 'test_role',
            'display_name' => 'Test Role',
            'entity' => 'makro',
            'is_system' => false,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->shmAdmin)
            ->put(route('shm.settings.roles.update', $role->id), [
                'display_name' => 'Updated Name',
                'permissions' => [$mikroPerm->id],
            ]);

        $response->assertSessionHasErrors('permissions');
        $this->assertDatabaseMissing('permission_role', [
            'role_id' => $role->id,
            'permission_id' => $mikroPerm->id,
        ]);
    }

    public function test_only_superadmin_can_grant_shm_role_update_permission(): void
    {
        $roleUpdatePerm = Permission::query()->where('name', 'shm.role.update')->first();
        $newRole = Role::query()->create([
            'name' => 'new_target_role',
            'display_name' => 'Target Role',
            'entity' => 'makro',
            'is_system' => false,
            'is_active' => true,
        ]);

        // Delegated SHM Admin tries to grant shm.role.update -> REJECTED
        $response = $this->actingAs($this->shmAdmin)
            ->put(route('shm.settings.roles.update', $newRole->id), [
                'display_name' => 'Target Role',
                'permissions' => [$roleUpdatePerm->id],
            ]);

        $response->assertSessionHasErrors('permissions');

        // Superadmin grants shm.role.update -> ALLOWED
        $this->superadmin->unsetRelation('roles');
        $superadminUser = User::query()->find($this->superadmin->id);
        $response2 = $this->actingAs($superadminUser)
            ->put(route('shm.settings.roles.update', $newRole->id), [
                'display_name' => 'Target Role',
                'permissions' => [$roleUpdatePerm->id],
            ]);

        $response2->assertRedirect(route('shm.settings.roles.index'));
        $this->assertDatabaseHas('permission_role', [
            'role_id' => $newRole->id,
            'permission_id' => $roleUpdatePerm->id,
        ]);
    }

    public function test_cannot_delete_system_role_or_role_with_assigned_users(): void
    {
        // System role
        $systemRole = Role::query()->create([
            'name' => 'system_makro',
            'display_name' => 'System Makro',
            'entity' => 'makro',
            'is_system' => true,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->shmAdmin)
            ->delete(route('shm.settings.roles.destroy', $systemRole->id));
        $response->assertSessionHasErrors('role');

        // Role with assigned user
        $response2 = $this->actingAs($this->shmAdmin)
            ->delete(route('shm.settings.roles.destroy', $this->shmAdminRole->id));
        $response2->assertSessionHasErrors('role');
    }
}
