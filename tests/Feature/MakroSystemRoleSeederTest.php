<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\MakroSystemRoleSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MakroSystemRoleSeederTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure base permission and role seeders run
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $superadminRole = Role::firstOrCreate(['name' => 'superadmin'], [
            'display_name' => 'Super Administrator',
            'entity' => 'shared',
            'is_system' => true,
            'is_active' => true,
        ]);

        $this->superadmin = User::factory()->create([
            'id' => 1,
            'name' => 'Super Administrator',
            'email' => 'superadmin@ksp.local',
        ]);
        $this->superadmin->roles()->syncWithoutDetaching([$superadminRole->id]);
    }

    public function test_fresh_database_provisions_all_five_makro_system_roles(): void
    {
        $this->seed(MakroSystemRoleSeeder::class);

        $expectedRoles = [
            'admin_makro' => 57,
            'marketing_makro' => 22,
            'branch_manager_makro' => 12,
            'direktur_utama_makro' => 12,
            'finance_operational_makro' => 19,
        ];

        foreach ($expectedRoles as $name => $permCount) {
            $role = Role::where('name', $name)->first();
            $this->assertNotNull($role, "Role {$name} must exist");
            $this->assertSame('makro', $role->entity);
            $this->assertTrue((bool) $role->is_system);
            $this->assertTrue((bool) $role->is_active);

            // Verify permissions are strictly makro
            $this->assertSame(0, $role->permissions()->where('entity', '!=', 'makro')->count());
            $this->assertSame($permCount, $role->permissions()->count(), "Role {$name} permissions count mismatch");
        }
    }

    public function test_seeder_is_idempotent_on_repeated_execution(): void
    {
        $this->seed(MakroSystemRoleSeeder::class);
        $roleCountInitial = Role::count();
        $permCountInitial = Permission::count();
        $pivotCountInitial = DB::table('permission_role')->count();

        // Run second time
        $this->seed(MakroSystemRoleSeeder::class);

        $this->assertSame($roleCountInitial, Role::count(), 'Role count must not change on re-seed');
        $this->assertSame($permCountInitial, Permission::count(), 'Permission count must not change on re-seed');
        $this->assertSame($pivotCountInitial, DB::table('permission_role')->count(), 'Pivot count must not change on re-seed');
    }

    public function test_custom_roles_and_user_assignments_are_preserved(): void
    {
        // Create custom role
        $customRole = Role::create([
            'name' => 'custom_surveyor',
            'display_name' => 'Custom Surveyor',
            'entity' => 'makro',
            'is_system' => false,
            'is_active' => true,
        ]);
        $perm = Permission::where('entity', 'makro')->first();
        if ($perm) {
            $customRole->permissions()->attach($perm->id);
        }

        // Create user with assignment
        $user = User::factory()->create();
        $user->roles()->attach($customRole->id);

        $this->seed(MakroSystemRoleSeeder::class);

        // Assert custom role exists intact
        $this->assertDatabaseHas('roles', [
            'id' => $customRole->id,
            'name' => 'custom_surveyor',
            'is_system' => false,
        ]);

        if ($perm) {
            $this->assertDatabaseHas('permission_role', [
                'role_id' => $customRole->id,
                'permission_id' => $perm->id,
            ]);
        }

        $this->assertDatabaseHas('role_user', [
            'user_id' => $user->id,
            'role_id' => $customRole->id,
        ]);
    }

    public function test_superadmin_is_preserved_and_unmodified(): void
    {
        $superadminBefore = DB::table('roles')->where('id', 1)->first();

        $this->seed(MakroSystemRoleSeeder::class);

        $superadminAfter = DB::table('roles')->where('id', 1)->first();
        $this->assertSame($superadminBefore->name, $superadminAfter->name);
        $this->assertSame($superadminBefore->entity, $superadminAfter->entity);
        $this->assertSame(1, DB::table('role_user')->where('user_id', $this->superadmin->id)->where('role_id', 1)->count());
    }

    public function test_seeder_does_not_perform_user_role_assignment(): void
    {
        $unassignedUser = User::factory()->create();
        $this->assertSame(0, $unassignedUser->roles()->count());

        $this->seed(MakroSystemRoleSeeder::class);

        $unassignedUser->refresh();
        $this->assertSame(0, $unassignedUser->roles()->count(), 'Seeder must never assign roles to users');
    }
}
