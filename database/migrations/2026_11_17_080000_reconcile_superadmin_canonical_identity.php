<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * BSM TICKET 15J.4 — Canonical Superadmin Identity Reconciliation Migration.
 *
 * Reconciles the Superadmin role to canonical ID 1 across:
 * - Fresh installations (where historical migrations created branch_manager at ID 1)
 * - Known legacy environments (where ID 1 is branch_manager/mikro and ID 2 is superadmin/shared)
 * - Already canonical environments (where ID 1 is superadmin/shared)
 *
 * Enforces strict transactional safety, foreign key referential integrity, and fail-fast validation.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Must run within a transaction
        DB::transaction(function () {
            $this->reconcileSuperadminIdentity();
        });
    }

    public function down(): void
    {
        // Reverting canonical ID 1 back to fractured IDs is not supported and violates canonical invariants.
    }

    private function reconcileSuperadminIdentity(): void
    {
        // Acquire table locks if on PostgreSQL to serialize RBAC mutations
        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            DB::statement('LOCK TABLE roles, role_user, permission_role IN ACCESS EXCLUSIVE MODE');
        }

        $id1Role = DB::table('roles')->where('id', 1)->first();

        // -------------------------------------------------------------
        // SCENARIO A: Already Canonical
        // -------------------------------------------------------------
        if ($id1Role && $id1Role->name === 'superadmin') {
            // Verify and ensure canonical attributes
            DB::table('roles')->where('id', 1)->update([
                'entity' => 'shared',
                'is_system' => true,
                'is_active' => true,
                'updated_at' => now(),
            ]);

            // Fail-fast if there are duplicate superadmin roles outside ID 1
            $duplicates = DB::table('roles')->where('name', 'superadmin')->where('id', '<>', 1)->count();
            if ($duplicates > 0) {
                throw new RuntimeException("RECONCILIATION_ABORT: Detected {$duplicates} duplicate 'superadmin' role(s) outside ID 1.");
            }

            return;
        }

        // -------------------------------------------------------------
        // SCENARIO B: Known Legacy / Fresh Install State
        // (ID 1 is branch_manager / mikro, Superadmin is at ID 2 or uncreated)
        // -------------------------------------------------------------
        $superadminRole = DB::table('roles')->where('name', 'superadmin')->first();

        // If ID 1 is occupied by another role and Superadmin exists on another ID:
        if ($superadminRole && (int) $superadminRole->id !== 1) {
            $oldSuperadminId = (int) $superadminRole->id;

            // 1. Calculate an unused, overflow-safe temporary ID within BIGINT range
            $maxId = (int) DB::table('roles')->max('id');
            $tempId = max($maxId, 1000) + 1;

            // Check if $tempId already exists (collision prevention)
            while (DB::table('roles')->where('id', $tempId)->exists()) {
                $tempId++;
            }

            // 2. STEP A: Create temporary parent row at $tempId FIRST!
            // Parent must exist before any child FK (role_user, permission_role) can point to it.
            $legacyRole1 = DB::table('roles')->where('id', 1)->first();
            DB::table('roles')->insert([
                'id' => $tempId,
                'name' => 'temp_legacy_role_'.$tempId,
                'display_name' => $legacyRole1 ? $legacyRole1->display_name.' (Temporary)' : 'Temporary Legacy Role',
                'description' => 'Temporary parent to preserve referential integrity during canonical Superadmin ID remapping',
                'entity' => $legacyRole1 ? $legacyRole1->entity : 'mikro',
                'is_system' => $legacyRole1 ? (bool) $legacyRole1->is_system : true,
                'is_active' => $legacyRole1 ? (bool) $legacyRole1->is_active : true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 3. STEP B: Move role ID 1's child references (role_user, permission_role) to the newly created $tempId
            DB::table('role_user')->where('role_id', 1)->update(['role_id' => $tempId]);
            DB::table('permission_role')->where('role_id', 1)->update(['role_id' => $tempId]);

            // 4. STEP C: Delete the old role ID 1 row (now completely unreferenced by child FKs)
            DB::table('roles')->where('id', 1)->delete();

            // 5. STEP D: Create temporary parent placeholder at canonical ID 1
            DB::table('roles')->insert([
                'id' => 1,
                'name' => 'superadmin_temp_placeholder',
                'display_name' => 'Temporary Superadmin Placeholder',
                'description' => 'Temporary placeholder to maintain referential integrity during ID remapping',
                'entity' => 'shared',
                'is_system' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 6. STEP E: Move Superadmin child references from oldSuperadminId to newly created ID 1
            DB::table('role_user')->where('role_id', $oldSuperadminId)->update(['role_id' => 1]);
            DB::table('permission_role')->where('role_id', $oldSuperadminId)->update(['role_id' => 1]);

            // 7. STEP F: Delete old Superadmin parent row (now completely unreferenced)
            DB::table('roles')->where('id', $oldSuperadminId)->delete();

            // 8. STEP G: Update ID 1 attributes to final canonical Superadmin state
            DB::table('roles')->where('id', 1)->update([
                'name' => 'superadmin',
                'display_name' => 'Superadmin',
                'description' => 'Akses penuh ke seluruh modul dan pengaturan sistem.',
                'entity' => 'shared',
                'is_system' => true,
                'is_active' => true,
                'updated_at' => now(),
            ]);

            // 9. STEP H: Reset sequences if PostgreSQL
            if ($driver === 'pgsql') {
                DB::statement("SELECT setval('roles_id_seq', (SELECT COALESCE(MAX(id), 1) FROM roles))");
                DB::statement("SELECT setval('role_user_id_seq', (SELECT COALESCE(MAX(id), 1) FROM role_user))");
                DB::statement("SELECT setval('permission_role_id_seq', (SELECT COALESCE(MAX(id), 1) FROM permission_role))");
            }
        } elseif (! $superadminRole) {
            // Fresh installation case where Superadmin has not been seeded yet, but branch_manager took ID 1
            if ($id1Role) {
                $maxId = (int) DB::table('roles')->max('id');
                $tempId = max($maxId, 1000) + 1;

                while (DB::table('roles')->where('id', $tempId)->exists()) {
                    $tempId++;
                }

                // Create temporary parent first
                DB::table('roles')->insert([
                    'id' => $tempId,
                    'name' => 'temp_legacy_role_'.$tempId,
                    'display_name' => $id1Role->display_name.' (Temporary)',
                    'description' => 'Temporary parent to preserve referential integrity during ID remapping',
                    'entity' => $id1Role->entity,
                    'is_system' => (bool) $id1Role->is_system,
                    'is_active' => (bool) $id1Role->is_active,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Repoint pivots to temp parent
                DB::table('role_user')->where('role_id', 1)->update(['role_id' => $tempId]);
                DB::table('permission_role')->where('role_id', 1)->update(['role_id' => $tempId]);

                // Delete old ID 1
                DB::table('roles')->where('id', 1)->delete();
            }

            // Create canonical Superadmin at ID 1
            DB::table('roles')->insert([
                'id' => 1,
                'name' => 'superadmin',
                'display_name' => 'Superadmin',
                'description' => 'Akses penuh ke seluruh modul dan pengaturan sistem.',
                'entity' => 'shared',
                'is_system' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($driver === 'pgsql') {
                DB::statement("SELECT setval('roles_id_seq', (SELECT COALESCE(MAX(id), 1) FROM roles))");
            }
        }

        // -------------------------------------------------------------
        // POST-RECONCILIATION ASSERTION
        // -------------------------------------------------------------
        $finalId1 = DB::table('roles')->where('id', 1)->first();
        if (! $finalId1 || $finalId1->name !== 'superadmin' || $finalId1->entity !== 'shared' || ! (bool) $finalId1->is_system || ! (bool) $finalId1->is_active) {
            throw new RuntimeException('RECONCILIATION_ABORT: Final assertion failed for canonical Superadmin ID 1.');
        }

        $duplicateCount = DB::table('roles')->where('name', 'superadmin')->where('id', '<>', 1)->count();
        if ($duplicateCount > 0) {
            throw new RuntimeException("RECONCILIATION_ABORT: Final assertion detected {$duplicateCount} duplicate superadmin role(s).");
        }
    }
};
