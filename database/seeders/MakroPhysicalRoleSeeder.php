<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\PermissionRegistry;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * TICKET 25B — BSM MAKRO: Physical Role Seeding & SHM User Role Assignment.
 *
 * Scope: Strictly BSM Makro (SHM).
 * - Synchronize missing Makro permissions only.
 * - Create/update the 5 Makro physical roles with entity = 'makro'.
 * - Assign permissions strictly matching the approved Ticket 23B/25B Makro matrix.
 * - Assign User 10 -> direktur_utama_makro, User 11 -> branch_manager_makro, User 12 -> finance_operational_makro.
 * - Detach obsolete roles for Users 10, 11, 12.
 * - Preserve all Mikro and Shared roles, permissions, and pivots untouched.
 */
class MakroPhysicalRoleSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $this->syncMakroPermissions();
            $this->seedMakroRoles();
            $this->assignMakroUsers();
        });
    }

    /**
     * Synchronize missing Makro-classified permissions only.
     */
    private function syncMakroPermissions(): void
    {
        $registry = app(PermissionRegistry::class);
        $entityMap = $registry->entityMap();

        // Target Makro permissions to ensure are created/synchronized
        $makroPermissions = [
            'accounting.shm.view' => [
                'module' => 'accounting',
                'action' => 'shm.view',
                'display_name' => 'Melihat akuntansi SHM',
            ],
            'accounting.shm.accounts.manage' => [
                'module' => 'accounting',
                'action' => 'shm.accounts.manage',
                'display_name' => 'Mengelola daftar perkiraan (COA) SHM',
            ],
            'accounting.shm.mappings.view' => [
                'module' => 'accounting',
                'action' => 'shm.mappings.view',
                'display_name' => 'Melihat mapping akun akuntansi SHM',
            ],
            'accounting.shm.mappings.manage' => [
                'module' => 'accounting',
                'action' => 'shm.mappings.manage',
                'display_name' => 'Mengelola mapping akun akuntansi SHM',
            ],
            'accounting.shm.period.close' => [
                'module' => 'accounting',
                'action' => 'shm.period.close',
                'display_name' => 'Menutup periode akuntansi SHM',
            ],
            'accounting.shm.journals.view' => [
                'module' => 'accounting',
                'action' => 'shm.journals.view',
                'display_name' => 'Melihat jurnal umum SHM',
            ],
            'accounting.shm.journals.create' => [
                'module' => 'accounting',
                'action' => 'shm.journals.create',
                'display_name' => 'Membuat & mengubah jurnal DRAFT SHM',
            ],
            'accounting.shm.journals.post' => [
                'module' => 'accounting',
                'action' => 'shm.journals.post',
                'display_name' => 'Memposting jurnal ke buku besar SHM',
            ],
            'accounting.shm.journals.reverse' => [
                'module' => 'accounting',
                'action' => 'shm.journals.reverse',
                'display_name' => 'Mereversi jurnal yang sudah diposting SHM',
            ],
            'accounting.shm.ledger.view' => [
                'module' => 'accounting',
                'action' => 'shm.ledger.view',
                'display_name' => 'Melihat buku besar SHM',
            ],
            'accounting.shm.reports.view' => [
                'module' => 'accounting',
                'action' => 'shm.reports.view',
                'display_name' => 'Melihat laporan keuangan SHM',
            ],
            'approval.decide' => [
                'module' => 'shm',
                'action' => 'approval.decide',
                'display_name' => 'Mengambil keputusan approval atau penolakan (legacy alias)',
            ],
            'shm.approval.bm' => [
                'module' => 'shm',
                'action' => 'approval.bm',
                'display_name' => 'Menyetujui tahap 1 (Branch Manager) pengajuan pinjaman',
            ],
            'shm.export' => [
                'module' => 'shm',
                'action' => 'export',
                'display_name' => 'Mengekspor data operasional SHM',
            ],
            'shm.payment.reverse' => [
                'module' => 'shm',
                'action' => 'payment.reverse',
                'display_name' => 'Membatalkan (reverse) transaksi pembayaran pinjaman SHM',
            ],
            'shm.settlement.approve' => [
                'module' => 'shm',
                'action' => 'settlement.approve',
                'display_name' => 'Menyetujui permohonan pelunasan SHM',
            ],
            'shm.settlement.verify' => [
                'module' => 'shm',
                'action' => 'settlement.verify',
                'display_name' => 'Memverifikasi pembayaran pelunasan SHM',
            ],
        ];

        foreach ($makroPermissions as $name => $meta) {
            $entity = $entityMap[$name] ?? 'makro';
            if ($entity !== 'makro') {
                continue; // Safety: only touch makro
            }

            Permission::query()->updateOrCreate(
                ['name' => $name],
                [
                    'module' => $meta['module'],
                    'action' => $meta['action'],
                    'display_name' => $meta['display_name'],
                    'entity' => 'makro',
                ]
            );
        }
    }

    /**
     * Create or update the 5 physical Makro roles and their permission matrix.
     */
    private function seedMakroRoles(): void
    {
        // 1. admin_makro
        $adminMakro = Role::query()->updateOrCreate(
            ['name' => 'admin_makro', 'entity' => 'makro'],
            [
                'display_name' => 'Admin Makro',
                'description' => 'Pengelola sistem operasional dan administrasi BSM Makro (SHM).',
                'is_system' => true,
                'is_active' => true,
            ]
        );

        // Entire makro permissions excluding approval decision (reserved for PD/BM)
        $adminMakroPerms = Permission::query()
            ->where('entity', 'makro')
            ->whereNotIn('name', ['shm.approval.decide', 'approval.decide', 'shm.memorandum.approve', 'shm.memorandum.reject'])
            ->pluck('id')
            ->all();
        $adminMakro->permissions()->sync($adminMakroPerms);

        // 2. marketing_makro
        $marketingMakro = Role::query()->updateOrCreate(
            ['name' => 'marketing_makro', 'entity' => 'makro'],
            [
                'display_name' => 'Marketing Makro',
                'description' => 'Aktivitas pemasaran, akuisisi nasabah, credit checking, dan pengajuan SHM.',
                'is_system' => true,
                'is_active' => true,
            ]
        );

        $marketingPermNames = [
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
        $marketingMakro->permissions()->sync(
            Permission::query()->where('entity', 'makro')->whereIn('name', $marketingPermNames)->pluck('id')->all()
        );

        // 3. branch_manager_makro
        $bmMakro = Role::query()->updateOrCreate(
            ['name' => 'branch_manager_makro', 'entity' => 'makro'],
            [
                'display_name' => 'Branch Manager Makro',
                'description' => 'Pimpinan cabang BSM Makro: pengawasan portofolio SHM, review memorandum, dan monitoring collection.',
                'is_system' => true,
                'is_active' => true,
            ]
        );

        $bmPermNames = [
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
        $bmMakro->permissions()->sync(
            Permission::query()->where('entity', 'makro')->whereIn('name', $bmPermNames)->pluck('id')->all()
        );

        // 4. direktur_utama_makro
        $pdMakro = Role::query()->updateOrCreate(
            ['name' => 'direktur_utama_makro', 'entity' => 'makro'],
            [
                'display_name' => 'President Director (Direktur Utama Makro)',
                'description' => 'Otoritas approval tertinggi pembiayaan dan memorandum BSM Makro (SHM).',
                'is_system' => true,
                'is_active' => true,
            ]
        );

        $pdPermNames = [
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
            'shm.credit_checking.override_fail',
            'shm.credit_checking.bypass_completion',
            // Ticket 42A: Dedicated Refund Approval & Rejection Authority
            'shm.refunds.view',
            'shm.refunds.approve',
            'shm.refunds.reject',
        ];
        $pdMakro->permissions()->sync(
            Permission::query()->where('entity', 'makro')->whereIn('name', $pdPermNames)->pluck('id')->all()
        );

        // 5. finance_operational_makro
        $foMakro = Role::query()->updateOrCreate(
            ['name' => 'finance_operational_makro', 'entity' => 'makro'],
            [
                'display_name' => 'Finance Operational Makro',
                'description' => 'Pencatatan dan pengelolaan operasional akuntansi SHM (COA, Mapping, Jurnal, dan Laporan).',
                'is_system' => true,
                'is_active' => true,
            ]
        );

        $foPermNames = [
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
            'shm.view', // Untuk melihat record pinjaman / pembayaran saat menjurnal
            'shm.view_all',
            'shm.disburse',
            'shm.payment.create',
            'shm.settlement.create',
            // Ticket 42A: Dedicated Refund Operational Permissions for Finance Operational
            'shm.refunds.view',
            'shm.refunds.create',
            'shm.refunds.cancel',
            'shm.refunds.execute',
            'shm.refunds.reverse',
            'shm.refunds.confirm_recovery',
        ];
        $foMakro->permissions()->sync(
            Permission::query()->where('entity', 'makro')->whereIn('name', $foPermNames)->pluck('id')->all()
        );
    }

    /**
     * Map Users 10, 11, 12 to their approved Makro physical roles.
     */
    private function assignMakroUsers(): void
    {
        $pdRole = Role::query()->where('name', 'direktur_utama_makro')->where('entity', 'makro')->firstOrFail();
        $bmRole = Role::query()->where('name', 'branch_manager_makro')->where('entity', 'makro')->firstOrFail();
        $foRole = Role::query()->where('name', 'finance_operational_makro')->where('entity', 'makro')->firstOrFail();

        // User 10 -> direktur_utama_makro
        $user10 = User::query()->find(10);
        if ($user10 !== null) {
            $user10->roles()->sync([$pdRole->id]);
        }

        // User 11 -> branch_manager_makro
        $user11 = User::query()->find(11);
        if ($user11 !== null) {
            $user11->roles()->sync([$bmRole->id]);
        }

        // User 12 -> finance_operational_makro
        $user12 = User::query()->find(12);
        if ($user12 !== null) {
            $user12->roles()->sync([$foRole->id]);
        }
    }
}
