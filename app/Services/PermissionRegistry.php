<?php

namespace App\Services;

/**
 * Pembaca registry permission pada config/permissions.php.
 */
class PermissionRegistry
{
    /**
     * Seluruh modul beserta label dan action-nya.
     *
     * @return array<string, mixed>
     */
    public function modules(): array
    {
        return config('permissions.modules', []);
    }

    /**
     * Seluruh nama permission yang valid ({module}.{action}).
     *
     * @return array<int, string>
     */
    public function allNames(): array
    {
        $names = [];

        foreach ($this->modules() as $module => $definition) {
            foreach (array_keys($definition['actions'] ?? []) as $action) {
                $names[] = "$module.$action";
            }
        }

        return $names;
    }

    /**
     * Map eksplisit permission entity: 'mikro', 'makro', atau 'shared'.
     *
     * @return array<string, string>
     */
    public function entityMap(): array
    {
        return [
            // SHARED (14 permissions)
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

            // BSM MIKRO (44 permissions)
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

            // BSM MAKRO (59 permissions)
            'shm.view' => 'makro',
            'shm.view_all' => 'makro',
            'shm.create' => 'makro',
            'shm.update' => 'makro',
            'shm.submit' => 'makro',
            'shm.property.manage' => 'makro',
            'shm.credit_checking.view' => 'makro',
            'shm.credit_checking.update' => 'makro',
            'shm.memorandum.update' => 'makro',
            'shm.approval.bm' => 'makro',
            'shm.approval.decide' => 'makro',
            'approval.decide' => 'makro',
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
            'shm.role.update' => 'makro',
            'shm.agent.view' => 'makro',
            'shm.agent.create' => 'makro',
            'shm.agent.update' => 'makro',
            'shm.agent.delete' => 'makro',
            'shm.memorandum.submit' => 'makro',
            'shm.memorandum.review' => 'makro',
            'shm.memorandum.approve' => 'makro',
            'shm.memorandum.reject' => 'makro',
            'shm.memorandum.history.view' => 'makro',
            'shm.refunds.view' => 'makro',
            'shm.refunds.create' => 'makro',
            'shm.refunds.approve' => 'makro',
            'shm.refunds.reject' => 'makro',
            'shm.refunds.cancel' => 'makro',
            'shm.refunds.execute' => 'makro',
            'shm.refunds.reverse' => 'makro',
            'shm.refunds.confirm_recovery' => 'makro',
            'marketing.view' => 'makro',
            'marketing.create' => 'makro',
            'marketing.result.update' => 'makro',
            'marketing.monitoring.view' => 'makro',
        ];
    }

    /**
     * Dapatkan entity untuk permission tertentu (default: mikro).
     */
    public function entityFor(string $name): string
    {
        $map = $this->entityMap();

        if (isset($map[$name])) {
            return $map[$name];
        }

        if (str_starts_with($name, 'shm.') || str_starts_with($name, 'accounting.shm.')) {
            return 'makro';
        }

        $sharedModules = ['dashboard', 'pengguna', 'role', 'pengaturan', 'audit'];
        $parts = explode('.', $name);
        if (in_array($parts[0], $sharedModules, true)) {
            return 'shared';
        }

        return 'mikro';
    }

    /**
     * Apakah nama permission terdaftar?
     */
    public function isValid(string $name): bool
    {
        return in_array($name, $this->allNames(), true);
    }
}
