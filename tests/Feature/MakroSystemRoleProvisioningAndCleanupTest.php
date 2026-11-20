<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MakroSystemRoleProvisioningAndCleanupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
    }

    public function test_migration_provisions_all_five_makro_system_roles_and_cleans_up_mikro_roles(): void
    {
        // 1. Setup pre-migration state with legacy mikro roles and canonical Superadmin
        $superadmin = Role::firstOrCreate(
            ['id' => 1, 'name' => 'superadmin'],
            ['display_name' => 'Superadmin', 'entity' => 'shared', 'is_system' => true, 'is_active' => true]
        );

        $mikroRole = Role::create([
            'name' => 'teller_mikro',
            'display_name' => 'Teller Mikro',
            'entity' => 'mikro',
            'is_system' => true,
            'is_active' => true,
        ]);

        $tempLegacy1001 = Role::create([
            'id' => 1001,
            'name' => 'temp_legacy_role_1001',
            'display_name' => 'Temp Legacy Role 1001',
            'entity' => 'mikro',
            'is_system' => true,
            'is_active' => true,
        ]);

        $testUser = User::factory()->create();
        $testUser->roles()->attach([$mikroRole->id, $tempLegacy1001->id]);

        $mikroPerm = Permission::firstOrCreate(
            ['name' => 'test.mikro.perm'],
            ['module' => 'test', 'action' => 'perm', 'display_name' => 'Test Mikro', 'entity' => 'mikro']
        );
        $mikroRole->permissions()->attach($mikroPerm->id);
        $tempLegacy1001->permissions()->attach($mikroPerm->id);

        // Run migration
        $migration = require database_path('migrations/2026_11_18_080406_provision_makro_roles_and_cleanup_legacy_mikro_roles.php');
        $migration->up();

        // 2. Verify Canonical Superadmin preserved
        $postSuperadmin = Role::find(1);
        $this->assertNotNull($postSuperadmin);
        $this->assertSame('superadmin', $postSuperadmin->name);
        $this->assertSame('shared', $postSuperadmin->entity);
        $this->assertTrue((bool) $postSuperadmin->is_system);
        $this->assertTrue((bool) $postSuperadmin->is_active);

        // 3. Verify Legacy Mikro roles cleaned up
        $this->assertDatabaseMissing('roles', ['name' => 'teller_mikro']);
        $this->assertDatabaseMissing('roles', ['name' => 'temp_legacy_role_1001']);
        $this->assertSame(0, Role::where('entity', 'mikro')->count());

        // 4. Verify Pivots cleaned up with no orphans
        $this->assertDatabaseMissing('role_user', ['role_id' => $mikroRole->id]);
        $this->assertDatabaseMissing('role_user', ['role_id' => $tempLegacy1001->id]);
        $this->assertDatabaseMissing('permission_role', ['role_id' => $mikroRole->id]);
        $this->assertDatabaseMissing('permission_role', ['role_id' => $tempLegacy1001->id]);

        // 5. Verify All 5 Makro System Roles provisioned
        $expectedMakroRoles = [
            'admin_makro',
            'marketing_makro',
            'branch_manager_makro',
            'direktur_utama_makro',
            'finance_operational_makro',
        ];

        foreach ($expectedMakroRoles as $name) {
            $role = Role::where('name', $name)->first();
            $this->assertNotNull($role, "Role {$name} must exist");
            $this->assertSame('makro', $role->entity);
            $this->assertTrue((bool) $role->is_system);
            $this->assertTrue((bool) $role->is_active);
            $this->assertGreaterThan(0, $role->permissions()->count(), "Role {$name} must have permissions assigned");
        }
    }

    public function test_migration_is_idempotent_on_repeated_runs(): void
    {
        $migration = require database_path('migrations/2026_11_18_080406_provision_makro_roles_and_cleanup_legacy_mikro_roles.php');

        // Run first time
        $migration->up();
        $rolesCountRun1 = Role::count();
        $permPivotCountRun1 = DB::table('permission_role')->count();

        // Run second time (re-execution)
        $migration->up();
        $rolesCountRun2 = Role::count();
        $permPivotCountRun2 = DB::table('permission_role')->count();

        $this->assertSame($rolesCountRun1, $rolesCountRun2, 'Running migration repeatedly must not create duplicate roles');
        $this->assertSame($permPivotCountRun1, $permPivotCountRun2, 'Running migration repeatedly must not create duplicate permission assignments');
    }

    public function test_custom_roles_created_via_role_controller_automatically_receive_makro_entity(): void
    {
        $superadmin = User::factory()->create();
        $superadmin->roles()->attach(1);

        $response = $this->actingAs($superadmin)->post(route('pengaturan.roles.store'), [
            'name' => 'surveyor_lapangan',
            'display_name' => 'Surveyor Lapangan',
            'description' => 'Petugas survey objek agunan di lapangan.',
            'permissions' => [],
        ]);

        $response->assertRedirect(route('pengaturan.roles.index'));

        $customRole = Role::where('name', 'surveyor_lapangan')->first();
        $this->assertNotNull($customRole);
        $this->assertSame('makro', $customRole->entity, 'Custom roles must automatically belong to entity makro');
        $this->assertFalse((bool) $customRole->is_system);
        $this->assertTrue((bool) $customRole->is_active);
    }
}
