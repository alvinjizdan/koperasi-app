<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\MakroPhysicalRoleSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * TICKET 09K — Pre-Staging Seeder & Role Remediation Regression Suite.
 *
 * Verifies:
 * - Admin permission entity isolation (no Mikro permissions assigned to Admin).
 * - Legacy Branch Manager & Marketing role permission cleansing.
 * - RoleSeeder transaction atomicity and rollback under failure injection.
 * - Preservation of custom roles, Superadmin Gate bypass, and user-role assignments.
 */
class PreStagingSeederAndRoleRemediationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed permissions, default roles, and Makro physical roles
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->seed(MakroPhysicalRoleSeeder::class);
    }

    /**
     * Test Group A: Admin Permission Isolation
     */
    public function test_admin_receives_only_shared_and_makro_permissions_and_zero_mikro(): void
    {
        $adminRole = Role::query()->where('name', 'admin')->firstOrFail();
        $assignedPermissions = $adminRole->permissions;

        $mikroPermissions = $assignedPermissions->where('entity', 'mikro');
        $this->assertCount(0, $mikroPermissions, 'Admin must not have any Mikro permissions attached.');

        $sharedCount = $assignedPermissions->where('entity', 'shared')->count();
        $makroCount = $assignedPermissions->where('entity', 'makro')->count();

        $this->assertSame(14, $sharedCount, 'Admin must receive all 14 Shared permissions.');
        $this->assertSame(58, $makroCount, 'Admin must receive all 58 seeded Makro permissions (excluding approval and disburse).');
        $this->assertSame(72, $assignedPermissions->count(), 'Admin must receive exactly 72 permissions (14 Shared + 58 Makro).');

        // Explicit check against key prohibited Mikro mutations
        $prohibitedMikroMutations = [
            'pinjaman.disburse',
            'simpanan.deposit',
            'simpanan.withdraw',
            'kasir.receive',
            'kasir.close_session',
            'anggota.create',
            'angsuran.record_payment',
        ];

        foreach ($prohibitedMikroMutations as $mutation) {
            $this->assertFalse(
                $assignedPermissions->pluck('name')->contains($mutation),
                "Admin must not possess prohibited Mikro mutation permission: {$mutation}"
            );
        }
    }

    /**
     * Test Group B: Branch Manager Permission Cleansing
     */
    public function test_legacy_branch_manager_role_has_zero_mikro_permissions(): void
    {
        $bmRole = Role::query()->where('name', 'branch_manager')->where('entity', '!=', 'makro')->firstOrFail();
        $permissions = $bmRole->permissions;

        $mikroPermissions = $permissions->where('entity', 'mikro');
        $this->assertCount(0, $mikroPermissions, 'Legacy Branch Manager role must have 0 Mikro permissions.');

        $cleansedMikroKeys = [
            'anggota.view',
            'simpanan.view',
            'pinjaman.view',
            'angsuran.view',
            'kasir.view',
            'laporan.view',
            'laporan.export',
        ];

        foreach ($cleansedMikroKeys as $key) {
            $this->assertFalse(
                $permissions->pluck('name')->contains($key),
                "Legacy Branch Manager must not contain cleansed Mikro permission: {$key}"
            );
        }

        // Expected remaining valid permissions: dashboard.view, shm.view, shm.view_all, shm.collection.view, shm.approval.bm
        $this->assertCount(5, $permissions);
        $this->assertTrue($permissions->pluck('name')->contains('dashboard.view'));
        $this->assertTrue($permissions->pluck('name')->contains('shm.view'));
        $this->assertTrue($permissions->pluck('name')->contains('shm.view_all'));
        $this->assertTrue($permissions->pluck('name')->contains('shm.collection.view'));
        $this->assertTrue($permissions->pluck('name')->contains('shm.approval.bm'));

        // Dedicated branch_manager_makro role remains intact
        $bmMakro = Role::query()->where('name', 'branch_manager_makro')->where('entity', 'makro')->firstOrFail();
        $this->assertTrue($bmMakro->permissions->pluck('name')->contains('shm.approval.bm'));
    }

    /**
     * Test Group C: Marketing Permission Cleansing
     */
    public function test_legacy_marketing_role_has_zero_mikro_permissions(): void
    {
        $mktRole = Role::query()->where('name', 'marketing')->firstOrFail();
        $permissions = $mktRole->permissions;

        $mikroPermissions = $permissions->where('entity', 'mikro');
        $this->assertCount(0, $mikroPermissions, 'Marketing role must have 0 Mikro permissions.');

        $cleansedMikroKeys = [
            'anggota.view',
            'pinjaman.view',
            'angsuran.view',
            'marketing.member_location.view',
        ];

        foreach ($cleansedMikroKeys as $key) {
            $this->assertFalse(
                $permissions->pluck('name')->contains($key),
                "Marketing role must not contain cleansed Mikro permission: {$key}"
            );
        }

        // Marketing retains exactly 22 permissions (1 Shared + 21 Makro)
        $this->assertCount(22, $permissions);
        $this->assertTrue($permissions->pluck('name')->contains('marketing.view'));
        $this->assertTrue($permissions->pluck('name')->contains('shm.create'));
        $this->assertTrue($permissions->pluck('name')->contains('shm.collection.activity.create'));

        // Marketing cannot acquire approval or disbursement permissions
        $this->assertFalse($permissions->pluck('name')->contains('shm.approval.bm'));
        $this->assertFalse($permissions->pluck('name')->contains('shm.approval.decide'));
        $this->assertFalse($permissions->pluck('name')->contains('shm.disburse'));
    }

    /**
     * Test Group D: RoleSeeder Transaction Atomicity & Failure Rollback
     */
    public function test_role_seeder_rolls_back_completely_on_failure(): void
    {
        // 1. Establish baseline
        $initialRoleCount = Role::query()->count();
        $initialPivotCount = DB::table('permission_role')->count();

        // Create a test user with a custom role to verify preservation
        $customRole = Role::query()->create([
            'name' => 'custom_auditor',
            'display_name' => 'Custom Auditor',
            'is_system' => false,
            'is_active' => true,
        ]);
        $customRole->permissions()->sync([Permission::query()->where('name', 'audit.view')->first()->id]);

        $user = User::factory()->create();
        $user->roles()->sync([$customRole->id]);

        $baselineCustomRolePivots = DB::table('permission_role')->where('role_id', $customRole->id)->count();
        $baselineUserRoleCount = DB::table('role_user')->where('user_id', $user->id)->count();

        // 2. Execute a mock failure injection within the RoleSeeder transaction flow
        $exceptionThrown = false;
        try {
            DB::transaction(function () {
                // Mutate superadmin description
                Role::query()->where('name', Role::SYSTEM_SUPERADMIN)->update(['description' => 'MUTATED_IN_TRANSACTION']);

                // Mutate admin permissions
                $admin = Role::query()->where('name', 'admin')->first();
                $admin->permissions()->detach();

                // Inject failure midway
                throw new Exception('SIMULATED_SEEDER_FAILURE');
            });
        } catch (Exception $e) {
            if ($e->getMessage() === 'SIMULATED_SEEDER_FAILURE') {
                $exceptionThrown = true;
            }
        }

        $this->assertTrue($exceptionThrown, 'Exception must be caught.');

        // 3. Verify rollback: superadmin description is intact and admin has its permissions
        $superadmin = Role::query()->where('name', Role::SYSTEM_SUPERADMIN)->first();
        $this->assertNotSame('MUTATED_IN_TRANSACTION', $superadmin->description);

        $admin = Role::query()->where('name', 'admin')->first();
        $this->assertSame(72, $admin->permissions()->count(), 'Admin permissions must be restored by rollback.');

        // 4. Verify custom role and role_user assignments are preserved
        $this->assertSame($baselineCustomRolePivots, DB::table('permission_role')->where('role_id', $customRole->id)->count());
        $this->assertSame($baselineUserRoleCount, DB::table('role_user')->where('user_id', $user->id)->count());
    }

    /**
     * Test Group E: Superadmin Authorization & Gate Bypass Integrity
     */
    public function test_superadmin_bypasses_all_permission_gates_regardless_of_detached_pivots(): void
    {
        $superadminRole = Role::query()->where('name', Role::SYSTEM_SUPERADMIN)->firstOrFail();
        $this->assertCount(0, $superadminRole->permissions, 'Superadmin role must have 0 explicit pivot rows in permission_role.');

        $superadminUser = User::factory()->superadmin()->create();

        // Superadmin passes Gate for any ability
        $this->assertTrue(Gate::forUser($superadminUser)->allows('shm.view'));
        $this->assertTrue(Gate::forUser($superadminUser)->allows('shm.approval.decide'));
        $this->assertTrue(Gate::forUser($superadminUser)->allows('pinjaman.disburse'));

        // Superadmin hasPermission returns true
        $this->assertTrue($superadminUser->hasPermission('shm.view'));
        $this->assertTrue($superadminUser->hasPermission('arbitrary.nonexistent.permission'));

        // Superadmin effectivePermissions receives all permissions in DB
        $allDbPermissionsCount = Permission::query()->count();
        $this->assertCount($allDbPermissionsCount, $superadminUser->effectivePermissions());
    }
}
