<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * TICKET 15J.19 — Makro System Role Seeder.
 *
 * Provisioning reproducible lima system role Makro:
 * 1. admin_makro (57 canonical permissions)
 * 2. marketing_makro (22 canonical permissions)
 * 3. branch_manager_makro (12 canonical permissions)
 * 4. direktur_utama_makro (12 canonical permissions)
 * 5. finance_operational_makro (19 canonical permissions)
 *
 * Sifat: Idempotent, safe, tidak mengassign user, tidak mengubah Superadmin,
 * tidak mengganggu custom roles / permissions.
 */
class MakroSystemRoleSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $this->syncMakroPermissions();
            $this->seedMakroRoles();
        });
    }

    /**
     * Pastikan seluruh permission Makro terdaftar pada database.
     */
    private function syncMakroPermissions(): void
    {
        $makroPermissions = [
            'shm.credit_checking.override_fail' => [
                'module' => 'shm',
                'action' => 'credit_checking.override_fail',
                'display_name' => 'Memberikan dispensasi khusus eksekutif untuk hasil credit checking FAIL',
            ],
            'shm.credit_checking.bypass_completion' => [
                'module' => 'shm',
                'action' => 'credit_checking.bypass_completion',
                'display_name' => 'Memberikan dispensasi khusus eksekutif untuk bypass tahap credit checking',
            ],
            'shm.approval.bm' => [
                'module' => 'shm',
                'action' => 'approval.bm',
                'display_name' => 'Rekomendasi persetujuan BM (Level 1)',
            ],
            'shm.refunds.view' => [
                'module' => 'shm',
                'action' => 'refunds.view',
                'display_name' => 'Melihat permohonan dan riwayat refund kelebihan bayar SHM',
            ],
            'shm.refunds.create' => [
                'module' => 'shm',
                'action' => 'refunds.create',
                'display_name' => 'Mengajukan permohonan refund kelebihan bayar SHM',
            ],
            'shm.refunds.approve' => [
                'module' => 'shm',
                'action' => 'refunds.approve',
                'display_name' => 'Menyetujui permohonan refund kelebihan bayar SHM',
            ],
            'shm.refunds.reject' => [
                'module' => 'shm',
                'action' => 'refunds.reject',
                'display_name' => 'Menolak permohonan refund kelebihan bayar SHM',
            ],
            'shm.refunds.cancel' => [
                'module' => 'shm',
                'action' => 'refunds.cancel',
                'display_name' => 'Membatalkan permohonan refund kelebihan bayar SHM',
            ],
            'shm.refunds.execute' => [
                'module' => 'shm',
                'action' => 'refunds.execute',
                'display_name' => 'Mencairkan dana refund kelebihan bayar SHM',
            ],
            'shm.refunds.reverse' => [
                'module' => 'shm',
                'action' => 'refunds.reverse',
                'display_name' => 'Merevisi/membatalkan pencairan refund yang keliru',
            ],
            'shm.refunds.confirm_recovery' => [
                'module' => 'shm',
                'action' => 'refunds.confirm_recovery',
                'display_name' => 'Mengonfirmasi pemulihan dana refund',
            ],
        ];

        foreach ($makroPermissions as $name => $definition) {
            Permission::query()->updateOrCreate(
                ['name' => $name],
                [
                    'module' => $definition['module'],
                    'action' => $definition['action'],
                    'display_name' => $definition['display_name'],
                    'entity' => 'makro',
                ]
            );
        }
    }

    /**
     * Provision atau rekonsiliasi lima system role Makro dan canonical permissions.
     */
    private function seedMakroRoles(): void
    {
        // Canonical Permission Matrix persis sesuai audit Ticket 15J.18 dan host database
        $rolesMatrix = [
            'admin_makro' => [
                'display_name' => 'Admin Makro',
                'description' => 'Pengelola sistem operasional dan administrasi BSM Makro (SHM).',
                'permissions' => [
                    'marketing.view',
                    'marketing.create',
                    'marketing.result.update',
                    'marketing.monitoring.view',
                    'shm.view',
                    'shm.view_all',
                    'shm.create',
                    'shm.update',
                    'shm.submit',
                    'shm.property.manage',
                    'shm.credit_checking.view',
                    'shm.credit_checking.update',
                    'shm.memorandum.update',
                    'shm.memorandum.submit',
                    'shm.memorandum.review',
                    'shm.memorandum.history.view',
                    'shm.approval.bm',
                    'shm.document.update',
                    'shm.disburse',
                    'shm.complete',
                    'shm.payment.create',
                    'shm.settlement.create',
                    'shm.settlement.policy.manage',
                    'shm.customer.view',
                    'shm.customer.create',
                    'shm.customer.update',
                    'shm.customer.delete',
                    'shm.agent.view',
                    'shm.agent.create',
                    'shm.agent.update',
                    'shm.agent.delete',
                    'shm.product.manage',
                    'shm.collection.view',
                    'shm.collection.transition',
                    'shm.collection.activity.create',
                    'shm.collection.ptp.create',
                    'shm.collection.ptp.transition',
                    'shm.savings.view',
                    'shm.savings.product.manage',
                    'shm.savings.account.create',
                    'shm.savings.transaction.create',
                    'shm.refunds.view',
                    'shm.refunds.create',
                    'shm.refunds.approve',
                    'shm.refunds.reject',
                    'shm.refunds.cancel',
                    'shm.refunds.execute',
                    'shm.refunds.reverse',
                    'shm.refunds.confirm_recovery',
                    'accounting.shm.view',
                    'accounting.shm.accounts.manage',
                    'accounting.shm.journals.view',
                    'accounting.shm.journals.create',
                    'accounting.shm.journals.post',
                    'accounting.shm.journals.reverse',
                    'accounting.shm.ledger.view',
                    'accounting.shm.reports.view',
                ],
            ],
            'marketing_makro' => [
                'display_name' => 'Marketing Makro',
                'description' => 'Aktivitas pemasaran, akuisisi nasabah, credit checking, dan pengajuan SHM.',
                'permissions' => [
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
                ],
            ],
            'branch_manager_makro' => [
                'display_name' => 'Branch Manager Makro',
                'description' => 'Pimpinan cabang BSM Makro: pengawasan portofolio SHM, review memorandum, dan monitoring collection.',
                'permissions' => [
                    'shm.view',
                    'shm.view_all',
                    'shm.credit_checking.view',
                    'shm.memorandum.update',
                    'shm.memorandum.submit',
                    'shm.memorandum.review',
                    'shm.memorandum.history.view',
                    'shm.approval.bm',
                    'shm.customer.view',
                    'shm.agent.view',
                    'shm.collection.view',
                    'shm.savings.view',
                ],
            ],
            'direktur_utama_makro' => [
                'display_name' => 'President Director (Direktur Utama Makro)',
                'description' => 'Otoritas approval tertinggi pembiayaan dan memorandum BSM Makro (SHM).',
                'permissions' => [
                    'shm.view',
                    'shm.view_all',
                    'shm.memorandum.approve',
                    'shm.memorandum.reject',
                    'shm.memorandum.history.view',
                    'shm.approval.decide',
                    'shm.customer.view',
                    'shm.collection.view',
                    'shm.credit_checking.override_fail',
                    'shm.credit_checking.bypass_completion',
                    'shm.refunds.view',
                    'shm.refunds.approve',
                    'shm.refunds.reject',
                    'accounting.shm.reports.view',
                ],
            ],
            'finance_operational_makro' => [
                'display_name' => 'Finance Operational Makro',
                'description' => 'Pencatatan dan pengelolaan operasional akuntansi SHM (COA, Mapping, Jurnal, dan Laporan).',
                'permissions' => [
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
                    'accounting.shm.view',
                    'accounting.shm.accounts.manage',
                    'accounting.shm.journals.view',
                    'accounting.shm.journals.create',
                    'accounting.shm.journals.post',
                    'accounting.shm.journals.reverse',
                    'accounting.shm.ledger.view',
                    'accounting.shm.reports.view',
                ],
            ],
        ];

        foreach ($rolesMatrix as $roleName => $config) {
            $role = Role::query()->updateOrCreate(
                ['name' => $roleName, 'entity' => 'makro'],
                [
                    'display_name' => $config['display_name'],
                    'description' => $config['description'],
                    'is_system' => true,
                    'is_active' => true,
                ]
            );

            // Sync canonical permissions strictly
            $permissionIds = Permission::query()
                ->where('entity', 'makro')
                ->whereIn('name', $config['permissions'])
                ->pluck('id')
                ->all();

            $role->permissions()->sync($permissionIds);
        }
    }
}
