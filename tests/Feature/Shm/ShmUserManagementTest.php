<?php

namespace Tests\Feature\Shm;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShmUserManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    private User $shmAdmin;

    private Role $shmAdminRole;

    private Role $surveyorMakroRole;

    private Role $mikroRole;

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

        $roleUpdatePerm = Permission::query()->create([
            'name' => 'shm.role.update',
            'module' => 'shm',
            'action' => 'role.update',
            'display_name' => 'Mengelola role SHM',
            'entity' => 'makro',
        ]);

        $this->shmAdminRole = Role::query()->create([
            'name' => 'admin_makro_custom',
            'display_name' => 'Admin Makro',
            'entity' => 'makro',
            'is_system' => false,
            'is_active' => true,
        ]);
        $this->shmAdminRole->permissions()->attach($roleUpdatePerm->id);

        $this->surveyorMakroRole = Role::query()->create([
            'name' => 'surveyor_makro',
            'display_name' => 'Surveyor Makro',
            'entity' => 'makro',
            'is_system' => false,
            'is_active' => true,
        ]);

        $this->mikroRole = Role::query()->create([
            'name' => 'teller_mikro',
            'display_name' => 'Teller Mikro',
            'entity' => 'mikro',
            'is_system' => false,
            'is_active' => true,
        ]);

        $this->shmAdmin = User::factory()->create(['is_active' => true]);
        $this->shmAdmin->roles()->attach($this->shmAdminRole->id);
    }

    public function test_shm_user_index_lists_users(): void
    {
        $targetUser = User::factory()->create(['name' => 'Budi Santoso', 'is_active' => true]);
        $targetUser->roles()->attach($this->surveyorMakroRole->id);

        $response = $this->actingAs($this->shmAdmin)
            ->get(route('shm.settings.users.index', ['search' => 'Budi']));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Shm/Settings/Users/Index')
            ->has('users.data', 1)
            ->where('users.data.0.name', 'Budi Santoso')
        );
    }

    public function test_shm_admin_can_attach_and_detach_makro_roles(): void
    {
        $targetUser = User::factory()->create(['is_active' => true]);

        // Attach surveyor_makro
        $response = $this->actingAs($this->shmAdmin)
            ->patch(route('shm.settings.users.update-roles', $targetUser->id), [
                'roles' => [$this->surveyorMakroRole->id],
            ]);

        $response->assertRedirect(route('shm.settings.users.index'));
        $this->assertDatabaseHas('role_user', [
            'user_id' => $targetUser->id,
            'role_id' => $this->surveyorMakroRole->id,
        ]);

        // Detach surveyor_makro
        $response2 = $this->actingAs($this->shmAdmin)
            ->patch(route('shm.settings.users.update-roles', $targetUser->id), [
                'roles' => [],
            ]);

        $response2->assertRedirect(route('shm.settings.users.index'));
        $this->assertDatabaseMissing('role_user', [
            'user_id' => $targetUser->id,
            'role_id' => $this->surveyorMakroRole->id,
        ]);
    }

    public function test_shm_settings_users_cannot_assign_mikro_roles(): void
    {
        $targetUser = User::factory()->create(['is_active' => true]);

        $response = $this->actingAs($this->shmAdmin)
            ->patch(route('shm.settings.users.update-roles', $targetUser->id), [
                'roles' => [$this->mikroRole->id],
            ]);

        $response->assertSessionHasErrors('roles');
        $this->assertDatabaseMissing('role_user', [
            'user_id' => $targetUser->id,
            'role_id' => $this->mikroRole->id,
        ]);
    }

    public function test_shm_settings_users_update_preserves_global_is_active_flag(): void
    {
        $targetUser = User::factory()->create(['is_active' => true]);

        $response = $this->actingAs($this->shmAdmin)
            ->patch(route('shm.settings.users.update-roles', $targetUser->id), [
                'roles' => [$this->surveyorMakroRole->id],
                'is_active' => false, // Attempt to deactivate global account via SHM settings
            ]);

        $response->assertRedirect(route('shm.settings.users.index'));
        $targetUser->refresh();
        $this->assertTrue($targetUser->is_active, 'SHM Settings User update must not alter global users.is_active flag');
    }
}
