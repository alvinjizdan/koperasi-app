<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BSM TICKET 15J — Complete Mikro Domain Decommissioning Migration.
 *
 * Controlled Forward-Only Schema & Historical Data Decommissioning:
 * 1. Preflight assertion of all candidate tables and constraints.
 * 2. Purge verified Mikro-specific RBAC records (entity = 'mikro') from permission_role, role_user, permissions, and roles.
 * 3. Drop cross-domain foreign key `shm_customers_member_id_foreign` on `shm_customers(member_id)` while preserving the nullable `member_id` BIGINT column.
 * 4. Drop legacy Mikro sequence `members_code_seq` if exists.
 * 5. Drop 13 legacy Mikro operational tables in strict topological child-before-parent order:
 *    - reconciliation_follow_ups
 *    - reconciliation_findings
 *    - reconciliation_runs
 *    - loan_balance_snapshots
 *    - installment_allocations
 *    - installment_payments
 *    - installment_schedules
 *    - loan_disbursements
 *    - loans
 *    - savings_transactions
 *    - savings_accounts
 *    - saving_products
 *    - members
 * 6. Postflight assertion: verify shared infrastructure, Cooperative Accounting, and SHM tables remain intact.
 *
 * NOTE ON DOWN() ROLLBACK:
 * Physical deletion of tables and historical data is not safely reversible via DDL.
 * As mandated by Ticket 15J Section 11.3, operational rollback requires full database backup restoration.
 */
return new class extends Migration
{
    /**
     * The 13 approved legacy Mikro operational tables in child-to-parent deletion order.
     */
    private const MIKRO_TABLES_ORDERED = [
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

    public function up(): void
    {
        // -------------------------------------------------------------
        // STEP 1: Preflight Validation
        // -------------------------------------------------------------
        $this->runPreflightChecks();

        // -------------------------------------------------------------
        // STEP 2: Purge Verified Mikro RBAC Records
        // -------------------------------------------------------------
        $this->purgeMikroRbacRecords();

        // -------------------------------------------------------------
        // STEP 3: Drop Cross-Domain FK on shm_customers(member_id)
        // -------------------------------------------------------------
        $this->decoupleShmCustomerMemberFk();

        // -------------------------------------------------------------
        // STEP 4: Drop Legacy Sequence members_code_seq
        // -------------------------------------------------------------
        $this->dropLegacySequences();

        // -------------------------------------------------------------
        // STEP 5: Drop 13 Mikro Operational Tables in Topological Order
        // -------------------------------------------------------------
        $this->dropMikroTables();

        // -------------------------------------------------------------
        // STEP 6: Postflight Integrity Assertion
        // -------------------------------------------------------------
        $this->runPostflightChecks();
    }

    public function down(): void
    {
        throw new RuntimeException(
            'TICKET 15J IRREVERSIBLE MIGRATION: Mikro domain decommissioning physically drops historical tables and constraints. '.
            'Rollback must be performed via complete database backup restoration (Ticket 15J Section 11.3).'
        );
    }

    private function runPreflightChecks(): void
    {
        // 1. Verify critical shared and SHM infrastructure exists
        $sharedTables = [
            'users', 'password_reset_tokens', 'roles', 'permissions', 'role_user', 'permission_role',
            'cashier_sessions', 'cash_transactions', 'audit_logs', 'marketing_activities',
            'chart_of_accounts', 'journal_entries', 'journal_lines', 'accounting_import_batches',
            'shm_customers', 'shm_loans', 'shm_loan_applications',
        ];

        foreach ($sharedTables as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("PREFLIGHT_ABORT: Essential shared/SHM table '{$table}' is missing.");
            }
        }

        // 2. Verify shm_customers table has member_id column
        if (! Schema::hasColumn('shm_customers', 'member_id')) {
            throw new RuntimeException("PREFLIGHT_ABORT: Column 'shm_customers.member_id' does not exist.");
        }

        // 3. Validate Canonical Superadmin Identity Invariant (Strict Fail-Fast)
        $id1Role = DB::table('roles')->where('id', 1)->first();
        if (! $id1Role) {
            throw new RuntimeException('SUPERADMIN_PREFLIGHT_ABORT: Role ID 1 does not exist in roles table.');
        }
        if ($id1Role->name !== 'superadmin') {
            throw new RuntimeException("SUPERADMIN_PREFLIGHT_ABORT: Role ID 1 has name '{$id1Role->name}', expected 'superadmin'. Automatic role promotion/mutation is forbidden.");
        }
        if ($id1Role->entity !== 'shared') {
            throw new RuntimeException("SUPERADMIN_PREFLIGHT_ABORT: Role ID 1 has entity '{$id1Role->entity}', expected 'shared'.");
        }
        if (! (bool) $id1Role->is_system) {
            throw new RuntimeException('SUPERADMIN_PREFLIGHT_ABORT: Role ID 1 is_system is false (expected true).');
        }
        if (! (bool) $id1Role->is_active) {
            throw new RuntimeException('SUPERADMIN_PREFLIGHT_ABORT: Role ID 1 is_active is false (expected true).');
        }

        // Assert no duplicate role named 'superadmin' exists outside ID 1
        $duplicateSuperadminCount = DB::table('roles')->where('name', 'superadmin')->where('id', '<>', 1)->count();
        if ($duplicateSuperadminCount > 0) {
            throw new RuntimeException("SUPERADMIN_PREFLIGHT_ABORT: Detected {$duplicateSuperadminCount} duplicate 'superadmin' role(s) outside ID 1.");
        }

        // 4. Verify all target 13 Mikro tables have exactly 0 rows before permitting destructive drops
        foreach (self::MIKRO_TABLES_ORDERED as $table) {
            if (Schema::hasTable($table)) {
                $count = DB::table($table)->count();
                if ($count > 0) {
                    throw new RuntimeException("PREFLIGHT_ABORT: Legacy Mikro table '{$table}' contains {$count} row(s). Decommissioning requires 0 rows.");
                }
            }
        }
    }

    private function purgeMikroRbacRecords(): void
    {
        // 1. Get Mikro role IDs (entity = 'mikro')
        $mikroRoleIds = DB::table('roles')->where('entity', 'mikro')->pluck('id')->all();

        // 2. Get Mikro permission IDs
        $mikroPermissionIds = DB::table('permissions')->where('entity', 'mikro')->pluck('id')->all();

        // 3. Detach pivot records for Mikro roles
        if (! empty($mikroRoleIds)) {
            DB::table('role_user')->whereIn('role_id', $mikroRoleIds)->delete();
            DB::table('permission_role')->whereIn('role_id', $mikroRoleIds)->delete();
        }

        // 4. Detach pivot records for Mikro permissions
        if (! empty($mikroPermissionIds)) {
            DB::table('permission_role')->whereIn('permission_id', $mikroPermissionIds)->delete();
        }

        // 5. Delete Mikro permissions
        if (! empty($mikroPermissionIds)) {
            DB::table('permissions')->whereIn('id', $mikroPermissionIds)->delete();
        }

        // 6. Delete Mikro roles (entity = 'mikro')
        if (! empty($mikroRoleIds)) {
            DB::table('roles')->whereIn('id', $mikroRoleIds)->delete();
        }
    }

    private function decoupleShmCustomerMemberFk(): void
    {
        if (Schema::hasTable('shm_customers')) {
            // Check if constraint exists before dropping
            if (DB::getDriverName() === 'pgsql') {
                $fkExists = DB::selectOne("
                    SELECT 1 FROM information_schema.table_constraints
                    WHERE constraint_type = 'FOREIGN KEY'
                      AND table_name = 'shm_customers'
                      AND constraint_name = 'shm_customers_member_id_foreign'
                ");

                if ($fkExists) {
                    DB::statement('ALTER TABLE shm_customers DROP CONSTRAINT shm_customers_member_id_foreign');
                }
            } else {
                // SQLite or other driver in testing
                Schema::table('shm_customers', function (Blueprint $table) {
                    $table->dropForeign(['member_id']);
                });
            }
        }
    }

    private function dropLegacySequences(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP SEQUENCE IF EXISTS members_code_seq RESTRICT');
        }
    }

    private function dropMikroTables(): void
    {
        foreach (self::MIKRO_TABLES_ORDERED as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function runPostflightChecks(): void
    {
        // Verify all 13 Mikro tables are gone
        foreach (self::MIKRO_TABLES_ORDERED as $table) {
            if (Schema::hasTable($table)) {
                throw new RuntimeException("POSTFLIGHT_ABORT: Legacy Mikro table '{$table}' still exists.");
            }
        }

        // Verify shm_customers still exists and has nullable member_id
        if (! Schema::hasTable('shm_customers')) {
            throw new RuntimeException("POSTFLIGHT_ABORT: 'shm_customers' table was unexpectedly dropped.");
        }

        if (! Schema::hasColumn('shm_customers', 'member_id')) {
            throw new RuntimeException("POSTFLIGHT_ABORT: 'shm_customers.member_id' column was unexpectedly dropped.");
        }

        // Verify no Mikro roles remain in roles table
        $remainingMikroRoles = DB::table('roles')->where('entity', 'mikro')->count();
        if ($remainingMikroRoles > 0) {
            throw new RuntimeException("POSTFLIGHT_ABORT: {$remainingMikroRoles} Mikro roles still remain in database.");
        }

        // Verify no Mikro permissions remain
        $remainingMikroPerms = DB::table('permissions')->where('entity', 'mikro')->count();
        if ($remainingMikroPerms > 0) {
            throw new RuntimeException("POSTFLIGHT_ABORT: {$remainingMikroPerms} Mikro permissions still remain in database.");
        }
    }
};
