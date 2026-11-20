<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\ShmCustomer;
use App\Models\ShmLoanProduct;
use App\Models\User;
use Database\Seeders\MakroSystemRoleSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RoleAndUserDeletionTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    private User $regularUser;

    private Role $customRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->seed(MakroSystemRoleSeeder::class);

        // Superadmin role is shared, ID 1
        $superadminRole = Role::where('name', 'superadmin')->first();

        $this->superadmin = User::factory()->create([
            'id' => 1,
            'name' => 'Super Administrator',
            'email' => 'superadmin@ksp.local',
            'is_active' => true,
        ]);
        $this->superadmin->roles()->syncWithoutDetaching([$superadminRole->id]);

        $this->regularUser = User::factory()->create([
            'name' => 'Regular User',
            'email' => 'regular@ksp.local',
            'is_active' => true,
        ]);

        $this->customRole = Role::create([
            'name' => 'custom_analyst',
            'display_name' => 'Custom Analyst',
            'entity' => 'makro',
            'is_system' => false,
            'is_active' => true,
        ]);
    }

    // ==========================================
    // ROLE DELETION TESTS
    // ==========================================

    public function test_authorized_custom_role_deletion_succeeds(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->delete(route('pengaturan.roles.destroy', $this->customRole->id));

        $response->assertRedirect(route('pengaturan.roles.index'));
        $response->assertSessionHas('success');
        $this->assertDatabaseMissing('roles', ['id' => $this->customRole->id]);
    }

    public function test_unauthorized_role_deletion_returns_403(): void
    {
        $response = $this->actingAs($this->regularUser)
            ->delete(route('pengaturan.roles.destroy', $this->customRole->id));

        $response->assertForbidden();
        $this->assertDatabaseHas('roles', ['id' => $this->customRole->id]);
    }

    public function test_system_role_deletion_is_blocked(): void
    {
        $systemRoles = [
            'superadmin',
            'admin_makro',
            'branch_manager_makro',
            'direktur_utama_makro',
            'finance_operational_makro',
            'marketing_makro',
        ];

        foreach ($systemRoles as $name) {
            $role = Role::where('name', $name)->first();
            $this->assertNotNull($role);

            $response = $this->actingAs($this->superadmin)
                ->delete(route('pengaturan.roles.destroy', $role->id));

            $response->assertSessionHas('error', 'Role sistem tidak dapat dihapus.');
            $this->assertDatabaseHas('roles', ['id' => $role->id]);
        }
    }

    public function test_role_with_user_assignments_cannot_be_deleted(): void
    {
        $this->regularUser->roles()->attach($this->customRole->id);

        $response = $this->actingAs($this->superadmin)
            ->delete(route('pengaturan.roles.destroy', $this->customRole->id));

        $response->assertSessionHas('error');
        $this->assertStringContainsString('masih digunakan oleh', session('error'));
        $this->assertDatabaseHas('roles', ['id' => $this->customRole->id]);
    }

    // ==========================================
    // USER DELETION TESTS
    // ==========================================

    public function test_authorized_user_deletion_succeeds_for_eligible_user(): void
    {
        $targetUser = User::factory()->create([
            'name' => 'Target User',
            'email' => 'target@ksp.local',
        ]);
        $targetUser->roles()->attach($this->customRole->id);

        $response = $this->actingAs($this->superadmin)
            ->delete(route('pengaturan.users.destroy', $targetUser->id));

        $response->assertRedirect(route('pengaturan.users.index'));
        $response->assertSessionHas('success');
        $this->assertDatabaseMissing('users', ['id' => $targetUser->id]);
        $this->assertDatabaseMissing('role_user', ['user_id' => $targetUser->id]);
    }

    public function test_unauthorized_user_deletion_returns_403(): void
    {
        $targetUser = User::factory()->create();

        $response = $this->actingAs($this->regularUser)
            ->delete(route('pengaturan.users.destroy', $targetUser->id));

        $response->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $targetUser->id]);
    }

    public function test_superadmin_user_deletion_is_blocked(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->delete(route('pengaturan.users.destroy', $this->superadmin->id));

        $response->assertSessionHas('error');
        $this->assertDatabaseHas('users', ['id' => $this->superadmin->id]);
    }

    public function test_self_deletion_is_blocked(): void
    {
        $admin2 = User::factory()->create();
        $roleUpdatePerm = Permission::where('name', 'pengguna.deactivate')->first();
        $managerRole = Role::create([
            'name' => 'user_manager',
            'display_name' => 'User Manager',
            'entity' => 'makro',
            'is_system' => false,
            'is_active' => true,
        ]);
        $managerRole->permissions()->attach($roleUpdatePerm->id);
        $admin2->roles()->attach($managerRole->id);

        $response = $this->actingAs($admin2)
            ->delete(route('pengaturan.users.destroy', $admin2->id));

        $response->assertSessionHas('error', 'Anda tidak dapat menghapus akun Anda sendiri.');
        $this->assertDatabaseHas('users', ['id' => $admin2->id]);
    }

    public function test_user_with_historical_financial_records_is_blocked_from_deletion(): void
    {
        $targetUser = User::factory()->create();

        // Create a fake journal entry referencing targetUser as created_by
        DB::table('journal_entries')->insert([
            'journal_number' => 'JRN-TEST-001',
            'transaction_date' => now()->toDateString(),
            'description' => 'Test Entry',
            'status' => 'draft',
            'created_by' => $targetUser->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->superadmin)
            ->delete(route('pengaturan.users.destroy', $targetUser->id));

        $response->assertSessionHas('error');
        $this->assertStringContainsString('terhubung dengan riwayat operasional/keuangan', session('error'));
        $this->assertDatabaseHas('users', ['id' => $targetUser->id]);
    }

    public function test_user_with_shm_loan_application_is_blocked_from_deletion(): void
    {
        $marketingUser = User::factory()->create();

        if (DB::getSchemaBuilder()->hasTable('shm_loan_applications')) {
            $customer = ShmCustomer::factory()->create();
            $product = ShmLoanProduct::factory()->create();

            DB::table('shm_loan_applications')->insert([
                'application_number' => 'SHM-APP-TEST-001',
                'application_date' => now()->toDateString(),
                'shm_customer_id' => $customer->id,
                'shm_loan_product_id' => $product->id,
                'principal' => 10000000,
                'tenor_months' => 12,
                'purpose' => 'Test',
                'marketing_id' => $marketingUser->id,
                'status' => 'Draft',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $response = $this->actingAs($this->superadmin)
                ->delete(route('pengaturan.users.destroy', $marketingUser->id));

            $response->assertSessionHas('error');
            $this->assertStringContainsString('terhubung dengan riwayat operasional/keuangan', session('error'));
            $this->assertDatabaseHas('users', ['id' => $marketingUser->id]);
        } else {
            $this->assertTrue(true);
        }
    }
}
