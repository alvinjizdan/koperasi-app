<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * BSM TICKET 15J.17 — Makro System Role Provisioning & Legacy Mikro Role Cleanup.
 *
 * Requirements:
 * 1. Verify and enforce canonical Superadmin invariant (roles.id = 1, shared, is_system, is_active).
 * 2. Provision / reconcile canonical Makro system roles:
 *    - admin_makro (entity = makro, is_system = true, is_active = true)
 *    - branch_manager_makro (entity = makro, is_system = true, is_active = true)
 *    - direktur_utama_makro (entity = makro, is_system = true, is_active = true)
 *    - finance_operational_makro (entity = makro, is_system = true, is_active = true)
 *    - marketing_makro (entity = makro, is_system = true, is_active = true)
 * 3. Assign canonical permissions strictly according to approved Makro authorization matrix.
 * 4. Safely cleanup legacy Mikro roles (entity = 'mikro'), including:
 *    - Detach child pivots (role_user and permission_role) for entity = 'mikro' roles.
 *    - Delete role records where entity = 'mikro'.
 *    - Specifically delete temp_legacy_role_1001.
 * 5. Re-verify canonical Superadmin invariant and Makro roles count.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            // Acquire exclusive lock on RBAC tables if running on PostgreSQL
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('LOCK TABLE roles, role_user, permission_role IN ACCESS EXCLUSIVE MODE');
            }

            // 1. STEP 1: Verify Superadmin Invariant BEFORE cleanup
            $superadmin = DB::table('roles')->where('id', 1)->first();
            if (! $superadmin || $superadmin->name !== 'superadmin' || $superadmin->entity !== 'shared') {
                throw new RuntimeException('PREFLIGHT_ABORT: Canonical Superadmin (ID 1, shared) is invalid or missing.');
            }

            // 2. STEP 2: Provision / Reconcile Makro System Roles
            $this->provisionMakroSystemRoles();

            // 3. STEP 3: Cleanup Legacy Mikro Roles and Pivots
            $this->cleanupLegacyMikroRoles();

            // 4. STEP 4: Verify Post-Migration Invariants
            $postSuperadmin = DB::table('roles')->where('id', 1)->first();
            if (! $postSuperadmin || $postSuperadmin->name !== 'superadmin' || $postSuperadmin->entity !== 'shared') {
                throw new RuntimeException('POSTFLIGHT_ABORT: Canonical Superadmin invariant compromised after migration.');
            }

            $remainingMikroRoles = DB::table('roles')->where('entity', 'mikro')->count();
            if ($remainingMikroRoles > 0) {
                throw new RuntimeException("POSTFLIGHT_ABORT: {$remainingMikroRoles} legacy Mikro role(s) still exist.");
            }

            $orphanRoleUsers = DB::table('role_user')
                ->whereNotIn('role_id', DB::table('roles')->pluck('id'))
                ->count();
            if ($orphanRoleUsers > 0) {
                throw new RuntimeException("POSTFLIGHT_ABORT: Detected {$orphanRoleUsers} orphan role_user pivot(s).");
            }

            $orphanPermissionRoles = DB::table('permission_role')
                ->whereNotIn('role_id', DB::table('roles')->pluck('id'))
                ->count();
            if ($orphanPermissionRoles > 0) {
                throw new RuntimeException("POSTFLIGHT_ABORT: Detected {$orphanPermissionRoles} orphan permission_role pivot(s).");
            }
        });
    }

    public function down(): void
    {
        // Reverting deletion of deprecated legacy Mikro roles is not supported to prevent resurrecting decommissioned Mikro domain.
    }

    private function provisionMakroSystemRoles(): void
    {
        $makroRolesConfig = [
            'admin_makro' => [
                'display_name' => 'Admin Makro',
                'description' => 'Pengelola sistem operasional dan administrasi BSM Makro (SHM).',
                'permissions' => function () {
                    return DB::table('permissions')
                        ->where('entity', 'makro')
                        ->whereNotIn('name', [
                            'shm.approval.decide',
                            'approval.decide',
                            'shm.memorandum.approve',
                            'shm.memorandum.reject',
                        ])
                        ->pluck('id')
                        ->all();
                },
            ],
            'marketing_makro' => [
                'display_name' => 'Marketing Makro',
                'description' => 'Aktivitas pemasaran, akuisisi nasabah, credit checking, dan pengajuan SHM.',
                'permissions' => function () {
                    $names = [
                        'marketing.view',
                        'marketing.create',
                        'marketing.result.update',
                        'shm.view',
                        'shm.create',
                        'shm.update',
                        'shm.submit',
                        'shm.property.manage',
                        'shm.credit_checking.view',
                        'shm.credit_checking.update',
                        'shm.memorandum.update',
                        'shm.memorandum.submit',
                        'shm.document.update',
                        'shm.customer.view',
                        'shm.customer.create',
                        'shm.customer.update',
                        'shm.agent.view',
                        'shm.collection.view',
                        'shm.collection.transition',
                        'shm.collection.activity.create',
                        'shm.collection.ptp.create',
                        'shm.collection.ptp.transition',
                    ];

                    return DB::table('permissions')
                        ->where('entity', 'makro')
                        ->whereIn('name', $names)
                        ->pluck('id')
                        ->all();
                },
            ],
            'branch_manager_makro' => [
                'display_name' => 'Branch Manager Makro',
                'description' => 'Pimpinan cabang BSM Makro: pengawasan portofolio SHM, review memorandum, dan monitoring collection.',
                'permissions' => function () {
                    $names = [
                        'shm.view',
                        'shm.view_all',
                        'shm.collection.view',
                        'shm.customer.view',
                        'shm.agent.view',
                        'shm.credit_checking.view',
                        'shm.memorandum.update',
                        'shm.memorandum.submit',
                        'shm.memorandum.review',
                        'shm.memorandum.history.view',
                        'shm.approval.bm',
                        'shm.savings.view',
                        'shm.export',
                    ];

                    return DB::table('permissions')
                        ->where('entity', 'makro')
                        ->whereIn('name', $names)
                        ->pluck('id')
                        ->all();
                },
            ],
            'direktur_utama_makro' => [
                'display_name' => 'President Director (Direktur Utama Makro)',
                'description' => 'Otoritas approval tertinggi pembiayaan dan memorandum BSM Makro (SHM).',
                'permissions' => function () {
                    $names = [
                        'shm.view',
                        'shm.view_all',
                        'shm.approval.decide',
                        'approval.decide',
                        'shm.memorandum.approve',
                        'shm.memorandum.reject',
                        'shm.memorandum.history.view',
                        'shm.customer.view',
                        'shm.collection.view',
                        'accounting.shm.reports.view',
                        'shm.refunds.view',
                        'shm.refunds.approve',
                        'shm.refunds.reject',
                    ];

                    return DB::table('permissions')
                        ->where('entity', 'makro')
                        ->whereIn('name', $names)
                        ->pluck('id')
                        ->all();
                },
            ],
            'finance_operational_makro' => [
                'display_name' => 'Finance Operational Makro',
                'description' => 'Pencatatan dan pengelolaan operasional akuntansi SHM (COA, Mapping, Jurnal, dan Laporan).',
                'permissions' => function () {
                    $names = [
                        'accounting.shm.view',
                        'accounting.shm.accounts.manage',
                        'accounting.shm.mappings.view',
                        'accounting.shm.mappings.manage',
                        'accounting.shm.period.close',
                        'accounting.shm.journals.view',
                        'accounting.shm.journals.create',
                        'accounting.shm.journals.post',
                        'accounting.shm.journals.reverse',
                        'accounting.shm.ledger.view',
                        'accounting.shm.reports.view',
                        'shm.view',
                        'shm.view_all',
                        'shm.disburse',
                        'shm.payment.create',
                        'shm.settlement.create',
                        'shm.refunds.view',
                        'shm.refunds.create',
                        'shm.refunds.cancel',
                        'shm.refunds.execute',
                        'shm.refunds.reverse',
                        'shm.refunds.confirm_recovery',
                    ];

                    return DB::table('permissions')
                        ->where('entity', 'makro')
                        ->whereIn('name', $names)
                        ->pluck('id')
                        ->all();
                },
            ],
        ];

        foreach ($makroRolesConfig as $roleName => $config) {
            $existingRole = DB::table('roles')->where('name', $roleName)->first();

            if ($existingRole) {
                // Ensure existing role matches Makro system specification
                DB::table('roles')->where('id', $existingRole->id)->update([
                    'display_name' => $config['display_name'],
                    'description' => $config['description'],
                    'entity' => 'makro',
                    'is_system' => true,
                    'is_active' => true,
                    'updated_at' => now(),
                ]);
                $roleId = $existingRole->id;
            } else {
                $roleId = DB::table('roles')->insertGetId([
                    'name' => $roleName,
                    'display_name' => $config['display_name'],
                    'description' => $config['description'],
                    'entity' => 'makro',
                    'is_system' => true,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Sync permissions for this Makro system role
            $permIds = $config['permissions']();
            if (! empty($permIds)) {
                // Detach existing permissions
                DB::table('permission_role')->where('role_id', $roleId)->delete();

                // Attach new permissions
                $inserts = array_map(function ($pId) use ($roleId) {
                    return [
                        'role_id' => $roleId,
                        'permission_id' => $pId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }, $permIds);

                DB::table('permission_role')->insert($inserts);
            }
        }
    }

    private function cleanupLegacyMikroRoles(): void
    {
        // 1. Ambil seluruh ID role yang ber-entity 'mikro'
        // Safety guard: Jangan sentuh role ID 1 atau role dengan entity 'shared' / 'makro'
        $mikroRoleIds = DB::table('roles')
            ->where('entity', 'mikro')
            ->where('id', '<>', 1)
            ->pluck('id')
            ->all();

        if (empty($mikroRoleIds)) {
            return;
        }

        // 2. Detach pivots role_user untuk role-role mikro tersebut
        DB::table('role_user')->whereIn('role_id', $mikroRoleIds)->delete();

        // 3. Detach pivots permission_role untuk role-role mikro tersebut
        DB::table('permission_role')->whereIn('role_id', $mikroRoleIds)->delete();

        // 4. Hapus record role mikro
        DB::table('roles')->whereIn('id', $mikroRoleIds)->delete();
    }
};
