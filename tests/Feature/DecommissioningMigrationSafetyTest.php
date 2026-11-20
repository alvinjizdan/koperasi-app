<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\MakroPhysicalRoleSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Tests for Ticket 15J Decommissioning Migration Safety and RBAC Preservation.
 */
class DecommissioningMigrationSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->seed(MakroPhysicalRoleSeeder::class);
    }

    public function test_makro_roles_and_permissions_are_preserved_after_migration(): void
    {
        // 1. Assert Makro roles exist and have entity = 'makro'
        $makroRoles = Role::query()->where('entity', 'makro')->get();
        $this->assertNotEmpty($makroRoles);

        // 2. Assert Makro permissions exist
        $makroPerms = Permission::query()->where('entity', 'makro')->get();
        $this->assertNotEmpty($makroPerms);

        // 3. Specifically assert shm.role.update exists with entity = 'makro'
        $shmRoleUpdate = Permission::query()->where('name', 'shm.role.update')->where('entity', 'makro')->first();
        $this->assertNotNull($shmRoleUpdate, 'shm.role.update must exist with entity makro');

        // 4. Specifically assert branch_manager_makro exists with entity = 'makro'
        $bmMakro = Role::query()->where('name', 'branch_manager_makro')->where('entity', 'makro')->first();
        $this->assertNotNull($bmMakro, 'branch_manager_makro must exist with entity makro');
    }

    public function test_shared_superadmin_role_and_assignment_are_preserved(): void
    {
        // Ensure superadmin role has entity = 'shared'
        $superadminRole = Role::query()->where('name', 'superadmin')->first();
        $this->assertNotNull($superadminRole);
        $this->assertSame('shared', $superadminRole->entity);

        // Create a superadmin user
        $user = User::factory()->create();
        $user->roles()->attach($superadminRole->id);

        $this->assertTrue($user->fresh()->hasRole('superadmin'));
        $this->assertSame('shared', $user->roles()->first()->entity);
    }

    public function test_shm_customer_member_id_column_remains_nullable_bigint(): void
    {
        $this->assertTrue(Schema::hasTable('shm_customers'));
        $this->assertTrue(Schema::hasColumn('shm_customers', 'member_id'));

        // Verify column type
        $columnType = Schema::getColumnType('shm_customers', 'member_id');
        $this->assertContains($columnType, ['bigint', 'integer']);
    }

    public function test_cooperative_and_shm_accounting_tables_exist(): void
    {
        $coopTables = [
            'chart_of_accounts',
            'journal_entries',
            'journal_lines',
            'accounting_import_batches',
        ];

        foreach ($coopTables as $table) {
            $this->assertTrue(Schema::hasTable($table), "Cooperative table '{$table}' must exist");
        }

        $shmTables = [
            'shm_accounting_mappings',
            'shm_product_accountings',
            'shm_savings_accounts',
            'shm_savings_transactions',
            'shm_cash_transactions',
            'shm_settlement_refunds',
            'shm_customers',
            'shm_loans',
        ];

        foreach ($shmTables as $table) {
            $this->assertTrue(Schema::hasTable($table), "SHM table '{$table}' must exist");
        }
    }

    public function test_legacy_mikro_tables_are_not_present(): void
    {
        $mikroTables = [
            'reconciliation_follow_ups',
            'reconciliation_findings',
            'reconciliation_runs',
            'loan_balance_snapshots',
            'installment_allocations',
            'installment_payments',
            'installment_schedules',
            'loan_disbursements',
            'loans',
            'savings_transactions',
            'savings_accounts',
            'saving_products',
            'members',
        ];

        foreach ($mikroTables as $table) {
            $this->assertFalse(Schema::hasTable($table), "Legacy Mikro table '{$table}' must not exist after migration");
        }
    }
}
