<?php

namespace Tests\Feature;

use App\Enums\EntityContext;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\MakroPhysicalRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * TICKET 25B — BSM MAKRO: Physical Role Seeding & SHM User Role Assignment Verification.
 *
 * Verifies:
 * 1. Makro Physical roles existence, attributes, and permission classification (strictly makro).
 * 2. User 10, 11, 12 role assignments (exclusive makro roles).
 * 3. Finance Operational limited authority (SHM accounting only, no memorandum approval, no user/role admin, no mikro).
 * 4. Isolation against Mikro routes and Shared platform administration.
 * 5. Absence of reconciliation permissions in operational makro roles.
 */
class MakroPhysicalRoleIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $pdUser;

    private User $bmUser;

    private User $foUser;

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure users 10, 11, 12 exist in test sqlite
        $this->pdUser = User::factory()->create(['id' => 10, 'name' => 'Presiden Direktur', 'email' => 'pd@ksp.test']);
        $this->bmUser = User::factory()->create(['id' => 11, 'name' => 'Branch Manager', 'email' => 'bm@ksp.test']);
        $this->foUser = User::factory()->create(['id' => 12, 'name' => 'Finance Operational', 'email' => 'fo@ksp.test']);

        // Seed base permissions and Makro physical roles
        $this->seed(MakroPhysicalRoleSeeder::class);
    }

    public function test_five_makro_physical_roles_exist_with_makro_entity(): void
    {
        $expectedRoles = [
            'admin_makro',
            'marketing_makro',
            'branch_manager_makro',
            'direktur_utama_makro',
            'finance_operational_makro',
        ];

        foreach ($expectedRoles as $name) {
            $role = Role::where('name', $name)->where('entity', 'makro')->first();
            $this->assertNotNull($role, "Role {$name} with entity 'makro' must exist.");
            $this->assertTrue($role->is_active, "Role {$name} must be active.");
            $this->assertTrue($role->is_system, "Role {$name} must be a system role.");

            // Verify all assigned permissions have entity = 'makro'
            $nonMakroPerms = $role->permissions()->where('entity', '!=', 'makro')->count();
            $this->assertSame(0, $nonMakroPerms, "Role {$name} must only have permissions classified as 'makro'.");

            // Verify no reconciliation permissions assigned
            $rekonPerms = $role->permissions()->where('name', 'like', 'rekonsiliasi.%')->count();
            $this->assertSame(0, $rekonPerms, "Role {$name} must not have any reconciliation permissions.");
        }
    }

    public function test_existing_users_have_exclusive_makro_role_assignments(): void
    {
        // User 10 -> direktur_utama_makro
        $user10Roles = $this->pdUser->roles;
        $this->assertCount(1, $user10Roles);
        $this->assertSame('direktur_utama_makro', $user10Roles->first()->name);
        $this->assertSame('makro', $user10Roles->first()->entity);

        // User 11 -> branch_manager_makro
        $user11Roles = $this->bmUser->roles;
        $this->assertCount(1, $user11Roles);
        $this->assertSame('branch_manager_makro', $user11Roles->first()->name);
        $this->assertSame('makro', $user11Roles->first()->entity);

        // User 12 -> finance_operational_makro
        $user12Roles = $this->foUser->roles;
        $this->assertCount(1, $user12Roles);
        $this->assertSame('finance_operational_makro', $user12Roles->first()->name);
        $this->assertSame('makro', $user12Roles->first()->entity);
    }

    public function test_finance_operational_has_shm_accounting_and_no_approval_or_admin_privileges(): void
    {
        app()->instance(EntityContext::class, EntityContext::Makro);

        // Refresh user relation so newly attached permissions in setUp are loaded
        $this->foUser->refresh();

        // Can access SHM Accounting via Gate and direct context model check
        $this->assertTrue(Gate::forUser($this->foUser)->allows('accounting.shm.view'));
        $this->assertTrue(Gate::forUser($this->foUser)->allows('accounting.shm.accounts.manage'));
        $this->assertTrue(Gate::forUser($this->foUser)->allows('accounting.shm.journals.create'));
        $this->assertTrue(Gate::forUser($this->foUser)->allows('accounting.shm.journals.post'));
        $this->assertTrue(Gate::forUser($this->foUser)->allows('accounting.shm.reports.view'));
        $this->assertTrue($this->foUser->hasPermission('accounting.shm.mappings.manage', EntityContext::Makro));

        // CANNOT approve memorandum or loan application
        $this->assertFalse(Gate::forUser($this->foUser)->allows('shm.approval.decide'));
        $this->assertFalse(Gate::forUser($this->foUser)->allows('shm.memorandum.approve'));
        $this->assertFalse(Gate::forUser($this->foUser)->allows('shm.memorandum.reject'));

        // CANNOT access User or Role administration
        $this->assertFalse(Gate::forUser($this->foUser)->allows('pengguna.view'));
        $this->assertFalse(Gate::forUser($this->foUser)->allows('role.view'));
        $this->assertFalse(Gate::forUser($this->foUser)->allows('role.create'));

        // CANNOT access Cooperative (Mikro) Accounting
        $this->assertFalse(Gate::forUser($this->foUser)->allows('accounting.view'));
        $this->assertFalse(Gate::forUser($this->foUser)->allows('accounting.accounts.manage'));
    }

    public function test_makro_users_are_forbidden_from_mikro_operational_routes(): void
    {
        // Direct URL access to decommissioned Mikro endpoints must return 404 Not Found
        $this->actingAs($this->foUser)
            ->get('/anggota')
            ->assertNotFound();

        $this->actingAs($this->bmUser)
            ->get('/simpanan')
            ->assertNotFound();

        $this->actingAs($this->pdUser)
            ->get('/pinjaman')
            ->assertNotFound();

        $this->actingAs($this->pdUser)
            ->get('/kasir')
            ->assertNotFound();

        $this->actingAs($this->pdUser)
            ->get('/rekonsiliasi')
            ->assertNotFound();
    }

    public function test_makro_users_are_forbidden_from_shared_management_routes(): void
    {
        // Attempting to access Shared User/Role management should yield 403 Forbidden
        $this->actingAs($this->foUser)
            ->get(route('pengaturan.users.index'))
            ->assertForbidden();

        $this->actingAs($this->bmUser)
            ->get(route('pengaturan.roles.index'))
            ->assertForbidden();

        $this->actingAs($this->pdUser)
            ->get(route('pengaturan.audit.index'))
            ->assertForbidden();
    }
}
