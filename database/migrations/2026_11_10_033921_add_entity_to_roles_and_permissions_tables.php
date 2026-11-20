<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Map eksplisit dan deterministik 117 permissions ke entity: 'mikro', 'makro', atau 'shared'.
     *
     * @return array<string, string>
     */
    private function permissionEntityMap(): array
    {
        return [
            // SHARED (14 permissions) - Platform, Autentikasi, Pengguna, Role, Pengaturan, Audit
            'dashboard.view' => 'shared',
            'pengguna.view' => 'shared',
            'pengguna.create' => 'shared',
            'pengguna.update' => 'shared',
            'pengguna.deactivate' => 'shared',
            'pengguna.reset_password' => 'shared',
            'pengguna.assign_role' => 'shared',
            'role.view' => 'shared',
            'role.create' => 'shared',
            'role.update' => 'shared',
            'role.delete' => 'shared',
            'pengaturan.view' => 'shared',
            'pengaturan.update' => 'shared',
            'audit.view' => 'shared',

            // BSM MIKRO (44 permissions) - Koperasi, Simpanan, Pinjaman Reguler, Angsuran, Kasir, Akuntansi Koperasi, Laporan, Rekonsiliasi, Titik Lokasi Anggota
            'anggota.view' => 'mikro',
            'anggota.create' => 'mikro',
            'anggota.update' => 'mikro',
            'anggota.deactivate' => 'mikro',
            'simpanan.view' => 'mikro',
            'simpanan.create' => 'mikro',
            'simpanan.deposit' => 'mikro',
            'simpanan.withdraw' => 'mikro',
            'simpanan.reverse' => 'mikro',
            'pinjaman.view' => 'mikro',
            'pinjaman.create' => 'mikro',
            'pinjaman.approve' => 'mikro',
            'pinjaman.reject' => 'mikro',
            'pinjaman.disburse' => 'mikro',
            'angsuran.view' => 'mikro',
            'angsuran.record_payment' => 'mikro',
            'angsuran.reverse' => 'mikro',
            'kasir.view' => 'mikro',
            'kasir.open_session' => 'mikro',
            'kasir.receive' => 'mikro',
            'kasir.disburse' => 'mikro',
            'kasir.deposit' => 'mikro',
            'kasir.close_session' => 'mikro',
            'kasir.reverse' => 'mikro',
            'marketing.member_location.view' => 'mikro',
            'marketing.member_location.update' => 'mikro',
            'laporan.view' => 'mikro',
            'laporan.export' => 'mikro',
            'rekonsiliasi.view' => 'mikro',
            'rekonsiliasi.run' => 'mikro',
            'rekonsiliasi.resolve' => 'mikro',
            'accounting.view' => 'mikro',
            'accounting.accounts.manage' => 'mikro',
            'accounting.journals.view' => 'mikro',
            'accounting.journals.create' => 'mikro',
            'accounting.journals.post' => 'mikro',
            'accounting.journals.reverse' => 'mikro',
            'accounting.ledger.view' => 'mikro',
            'accounting.reports.view' => 'mikro',
            'accounting.mappings.view' => 'mikro',
            'accounting.mappings.manage' => 'mikro',
            'accounting.configurations.view' => 'mikro',
            'accounting.configurations.manage' => 'mikro',
            'accounting.period.close' => 'mikro',

            // BSM MAKRO (59 permissions) - SHM Core, Nasabah, Agen, Dokumen, Memorandum, Approval, Pinjaman SHM, Simpanan SHM, Collection, Akuntansi SHM, Marketing Kunjungan
            'shm.view' => 'makro',
            'shm.view_all' => 'makro',
            'shm.create' => 'makro',
            'shm.update' => 'makro',
            'shm.submit' => 'makro',
            'shm.property.manage' => 'makro',
            'shm.credit_checking.view' => 'makro',
            'shm.credit_checking.update' => 'makro',
            'shm.memorandum.update' => 'makro',
            'shm.approval.decide' => 'makro',
            'approval.decide' => 'makro', // legacy alias untuk shm.approval.decide
            'shm.document.update' => 'makro',
            'shm.disburse' => 'makro',
            'shm.complete' => 'makro',
            'shm.payment.create' => 'makro',
            'shm.settlement.create' => 'makro',
            'shm.settlement.policy.manage' => 'makro',
            'shm.customer.view' => 'makro',
            'shm.customer.create' => 'makro',
            'shm.customer.update' => 'makro',
            'shm.product.manage' => 'makro',
            'shm.collection.view' => 'makro',
            'shm.collection.transition' => 'makro',
            'shm.collection.activity.create' => 'makro',
            'shm.collection.ptp.create' => 'makro',
            'shm.collection.ptp.transition' => 'makro',
            'shm.customer.delete' => 'makro',
            'shm.export' => 'makro',
            'shm.payment.reverse' => 'makro',
            'shm.settlement.approve' => 'makro',
            'shm.settlement.verify' => 'makro',
            'accounting.shm.view' => 'makro',
            'accounting.shm.accounts.manage' => 'makro',
            'accounting.shm.mappings.view' => 'makro',
            'accounting.shm.mappings.manage' => 'makro',
            'accounting.shm.period.close' => 'makro',
            'accounting.shm.journals.view' => 'makro',
            'accounting.shm.journals.create' => 'makro',
            'accounting.shm.journals.post' => 'makro',
            'accounting.shm.journals.reverse' => 'makro',
            'accounting.shm.ledger.view' => 'makro',
            'accounting.shm.reports.view' => 'makro',
            'shm.savings.view' => 'makro',
            'shm.savings.product.manage' => 'makro',
            'shm.savings.account.create' => 'makro',
            'shm.savings.transaction.create' => 'makro',
            'shm.agent.view' => 'makro',
            'shm.agent.create' => 'makro',
            'shm.agent.update' => 'makro',
            'shm.agent.delete' => 'makro',
            'shm.memorandum.submit' => 'makro',
            'shm.memorandum.review' => 'makro',
            'shm.memorandum.approve' => 'makro',
            'shm.memorandum.reject' => 'makro',
            'shm.memorandum.history.view' => 'makro',
            'marketing.view' => 'makro',
            'marketing.create' => 'makro',
            'marketing.result.update' => 'makro',
            'marketing.monitoring.view' => 'makro',
        ];
    }

    public function up(): void
    {
        // 1. Modifikasi Skema: roles
        Schema::table('roles', function (Blueprint $table) {
            $table->string('entity', 20)->default('mikro')->after('description');
            $table->dropUnique('roles_name_unique');
            $table->unique(['name', 'entity'], 'roles_name_entity_unique');
            $table->index(['entity', 'is_active'], 'roles_entity_is_active_index');
        });

        // 2. Modifikasi Skema: permissions
        Schema::table('permissions', function (Blueprint $table) {
            $table->string('entity', 20)->default('mikro')->after('action');
            $table->index(['entity', 'module'], 'permissions_entity_module_index');
        });

        // 3. Validasi Pra-Kondisi: Cek apakah ada permission di database yang belum terpetakan
        $dbPermissions = DB::table('permissions')->pluck('name')->all();
        $mapping = $this->permissionEntityMap();
        $unmapped = array_diff($dbPermissions, array_keys($mapping));

        if (! empty($unmapped)) {
            throw new RuntimeException('Migration aborted: Terdapat permission di database yang belum terpetakan: '.implode(', ', $unmapped));
        }

        // 4. Backfill Entity Permissions secara deterministik
        foreach ($mapping as $name => $entity) {
            DB::table('permissions')
                ->where('name', $name)
                ->update(['entity' => $entity]);
        }

        // 5. Verifikasi Pasca-Backfill: Pastikan tidak ada permissions.entity yang NULL atau di luar mikro|makro|shared
        $invalidPermissionsCount = DB::table('permissions')
            ->whereNotIn('entity', ['mikro', 'makro', 'shared'])
            ->count();

        if ($invalidPermissionsCount > 0) {
            throw new RuntimeException("Migration error: Terdeteksi {$invalidPermissionsCount} permission dengan entity tidak valid setelah backfill.");
        }
    }

    public function down(): void
    {
        // 1. Validasi Keamanan Rollback: Cek apakah ada role dengan nama sama di entitas berbeda
        $duplicateRoleNames = DB::table('roles')
            ->select('name', DB::raw('count(*) as count'))
            ->groupBy('name')
            ->having(DB::raw('count(*)'), '>', 1)
            ->pluck('name')
            ->all();

        if (! empty($duplicateRoleNames)) {
            throw new RuntimeException('Rollback aborted: Terdeteksi duplikasi nama role antar entitas: '.implode(', ', $duplicateRoleNames).'. Kembalikan keunikan nama role sebelum rollback.');
        }

        // 2. Rollback skema permissions
        Schema::table('permissions', function (Blueprint $table) {
            $table->dropIndex('permissions_entity_module_index');
            $table->dropColumn('entity');
        });

        // 3. Rollback skema roles
        Schema::table('roles', function (Blueprint $table) {
            $table->dropIndex('roles_entity_is_active_index');
            $table->dropUnique('roles_name_entity_unique');
            $table->unique('name', 'roles_name_unique');
            $table->dropColumn('entity');
        });
    }
};
