import React, { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import EmptyState from '@/Components/Admin/EmptyState';
import { Combobox, ComboboxContent, ComboboxEmpty, ComboboxInput, ComboboxItem, ComboboxList } from '@/Components/ui/combobox';
import { History, Search, ExternalLink } from 'lucide-react';
import { formatDateTime } from '@/utils/format';

const ACTION_LABELS = {
    'user.created': 'Akun dibuat',
    'user.updated': 'Akun diubah',
    'user.activated': 'Akun diaktifkan',
    'user.deactivated': 'Akun dinonaktifkan',
    'user.self_deactivated': 'Akun menonaktifkan diri',
    'user.password_reset': 'Password direset admin',
    'user.roles_changed': 'Role diubah',
    'role.created': 'Role dibuat',
    'role.updated': 'Role diubah',
    'role.deleted': 'Role dihapus',
    'settings.updated': 'Pengaturan diubah',
    'member.created': 'Anggota dibuat',
    'member.updated': 'Anggota diubah',
    'member.deactivated': 'Anggota dinonaktifkan',
    'savings.account.opened': 'Rekening dibuka',
    'savings.transaction.deposit': 'Setoran simpanan',
    'savings.transaction.withdrawal': 'Penarikan simpanan',
    'loan.submitted': 'Pinjaman diajukan',
    'loan.approved': 'Pinjaman disetujui',
    'loan.rejected': 'Pinjaman ditolak',
    'loan.disbursed': 'Pinjaman dicairkan',
    'installment.payment.recorded': 'Angsuran dibayar',
    'cashier.session.opened': 'Sesi kasir dibuka',
    'cashier.session.closed': 'Sesi kasir ditutup',
    'cashier.transaction.created': 'Transaksi kas dicatat',
    'cashier.transaction.reversed': 'Transaksi kas dibatalkan',
    'cashier.source.skipped': 'Dampak kas dilewati',
    'reconciliation.run.completed': 'Rekonsiliasi selesai',
    'reconciliation.finding.follow_up': 'Tindak lanjut temuan',
};

const MODULE_OPTIONS = [
    { value: 'all', label: 'Semua Modul' },
    { value: 'App\\Models\\User', label: 'Pengguna & Keamanan' },
    { value: 'App\\Models\\Role', label: 'Role & Permission' },
    { value: 'App\\Models\\Setting', label: 'Pengaturan' },
    { value: 'App\\Models\\Member', label: 'Anggota' },
    { value: 'App\\Models\\SavingsAccount', label: 'Rekening Simpanan' },
    { value: 'App\\Models\\SavingsTransaction', label: 'Transaksi Simpanan' },
    { value: 'App\\Models\\Loan', label: 'Pinjaman' },
    { value: 'App\\Models\\LoanDisbursement', label: 'Pencairan Pinjaman' },
    { value: 'App\\Models\\InstallmentPayment', label: 'Angsuran' },
    { value: 'App\\Models\\CashierSession', label: 'Sesi Kasir' },
    { value: 'App\\Models\\CashTransaction', label: 'Transaksi Kas' },
    { value: 'App\\Models\\Reconciliation', label: 'Rekonsiliasi' },
];

const ACTION_FILTER_OPTIONS = [{ value: 'all', label: 'Semua Tindakan' }];
const ACTOR_FILTER_OPTIONS = [{ value: 'all', label: 'Semua Pengguna' }];

function AuditOptionsCombobox({ options, value, onChange, ariaLabel }) {
    const items = options.map((o) => o.value);
    return (
        <Combobox
            items={items}
            value={value}
            onValueChange={(v) => onChange(v ?? 'all')}
            itemToStringValue={(v) => options.find((o) => o.value === v)?.label ?? v}
        >
            <ComboboxInput aria-label={ariaLabel} />
            <ComboboxContent>
                <ComboboxEmpty>Tidak ada pilihan.</ComboboxEmpty>
                <ComboboxList>
                    {(v) => (
                        <ComboboxItem key={v} value={v}>
                            {options.find((o) => o.value === v)?.label}
                        </ComboboxItem>
                    )}
                </ComboboxList>
            </ComboboxContent>
        </Combobox>
    );
}

export default function AuditLog({ logs, actions, actors, filters }) {
    const [local, setLocal] = useState({
        search: filters.search || '',
        action: filters.action || 'all',
        module: filters.module || 'all',
        actor: filters.actor || 'all',
        date_from: filters.date_from || '',
        date_to: filters.date_to || '',
    });

    const apply = (overrides = {}) => {
        router.get(route('pengaturan.audit.index'), { ...local, ...overrides }, { preserveState: true, replace: true });
    };

    const setFilter = (key, value) => {
        const next = { ...local, [key]: value };
        setLocal(next);
        router.get(route('pengaturan.audit.index'), next, { preserveState: true, replace: true });
    };

    const inputClass =
        'rounded-lg border border-slate-300 bg-slate-50/50 px-3 py-2 text-xs text-slate-900 placeholder:text-slate-400 focus:border-slate-900 focus:bg-white focus:outline-none focus:ring-1 focus:ring-slate-900 sm:text-sm';
    const selectClass =
        'rounded-lg border border-slate-300 bg-slate-50/50 px-3 py-2 text-xs font-medium text-slate-700 focus:border-slate-900 focus:bg-white focus:outline-none focus:ring-1 focus:ring-slate-900 sm:text-sm';

    return (
        <AdminLayout title="Audit Trail" pageTitle="Audit Trail Sistem">
            <div className="mb-6 flex flex-col gap-4 rounded-xl border border-slate-200 bg-white p-6 shadow-xs sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 className="flex items-center gap-2 text-xl font-bold tracking-tight text-slate-900 sm:text-2xl">
                        <History className="h-6 w-6 text-emerald-600" />
                        <span>Riwayat Perubahan Penting</span>
                    </h2>
                    <p className="mt-1 text-xs text-slate-500 sm:text-sm">
                        Mencatat aktor, tindakan, objek, waktu, dan ringkasan perubahan. Kredensial tidak pernah dicatat
                        dan catatan tidak dapat diubah atau dihapus melalui aplikasi.
                    </p>
                </div>
            </div>

            {/* Filter */}
            <div className="mb-6 rounded-xl border border-slate-200 bg-white p-4 shadow-xs">
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        apply();
                    }}
                    className="flex flex-col gap-3"
                >
                    <div className="flex flex-col gap-3 sm:flex-row">
                        <div className="relative flex-1">
                            <Search className="pointer-events-none absolute inset-y-0 left-0 flex h-full w-4 pl-3 text-slate-400" />
                            <input
                                type="text"
                                value={local.search}
                                onChange={(e) => setLocal({ ...local, search: e.target.value })}
                                placeholder="Cari tindakan, objek, atau deskripsi..."
                                className={`w-full pl-9 ${inputClass}`}
                            />
                        </div>
                        <button
                            type="submit"
                            className="rounded-lg border border-slate-200 bg-slate-100 px-4 py-2 text-xs font-semibold text-slate-800 transition-colors hover:bg-slate-200 active:scale-95 sm:text-sm"
                        >
                            Cari
                        </button>
                    </div>
                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                        <AuditOptionsCombobox
                            ariaLabel="Filter tindakan"
                            value={local.action}
                            onChange={(v) => setFilter('action', v)}
                            options={[
                                ...ACTION_FILTER_OPTIONS,
                                ...actions.map((name) => ({ value: name, label: ACTION_LABELS[name] || name })),
                            ]}
                        />
                        <AuditOptionsCombobox
                            ariaLabel="Filter modul"
                            value={local.module}
                            onChange={(v) => setFilter('module', v)}
                            options={MODULE_OPTIONS}
                        />
                        <AuditOptionsCombobox
                            ariaLabel="Filter pengguna"
                            value={local.actor}
                            onChange={(v) => setFilter('actor', v)}
                            options={[
                                ...ACTOR_FILTER_OPTIONS,
                                ...actors.map((a) => ({ value: String(a.id), label: a.name })),
                            ]}
                        />
                        <input
                            type="date"
                            value={local.date_from}
                            onChange={(e) => setFilter('date_from', e.target.value)}
                            title="Tanggal mulai"
                            className={inputClass}
                        />
                        <input
                            type="date"
                            value={local.date_to}
                            onChange={(e) => setFilter('date_to', e.target.value)}
                            title="Tanggal akhir"
                            className={inputClass}
                        />
                    </div>
                </form>
            </div>

            {/* Log list */}
            <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xs">
                {logs.data.length > 0 ? (
                    <div className="divide-y divide-slate-100">
                        {logs.data.map((log) => (
                            <div key={log.id} className="px-6 py-4">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="rounded-full bg-slate-100 px-2.5 py-0.5 text-[11px] font-semibold text-slate-700">
                                                {ACTION_LABELS[log.action] || log.action}
                                            </span>
                                            <span className="font-mono text-[11px] text-slate-400">
                                                {log.object_type}
                                                {log.object_label ? ` · ${log.object_label}` : ''}
                                            </span>
                                        </div>
                                        <p className="mt-1 text-xs text-slate-700">{log.description}</p>
                                        <p className="mt-0.5 text-[11px] text-slate-400">
                                            {log.actor ? `${log.actor.name} (${log.actor.email})` : 'Sistem'}
                                            {' · '}
                                            {formatDateTime(log.created_at)}
                                            {log.ip_address ? ` · ${log.ip_address}` : ''}
                                            {log.changes ? ` · ${Object.keys(log.changes).length} field berubah` : ''}
                                        </p>
                                    </div>
                                    <Link
                                        href={route('pengaturan.audit.show', log.id)}
                                        className="inline-flex shrink-0 items-center gap-1 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-[11px] font-semibold text-slate-600 transition-colors hover:bg-slate-100"
                                    >
                                        Detail
                                        <ExternalLink className="h-3 w-3" />
                                    </Link>
                                </div>
                            </div>
                        ))}
                    </div>
                ) : (
                    <div className="p-8">
                        <EmptyState
                            icon={History}
                            title="Belum Ada Aktivitas"
                            description="Perubahan penting pada seluruh modul akan tampil di sini. Coba ubah filter atau kata kunci pencarian."
                        />
                    </div>
                )}

                {logs.links && logs.links.length > 3 && (
                    <div className="flex flex-col items-center justify-between gap-4 border-t border-slate-200 bg-slate-50/50 px-6 py-4 text-xs text-slate-500 sm:flex-row">
                        <div>
                            Menampilkan <span className="font-semibold text-slate-900">{logs.from || 0}</span>–
                            <span className="font-semibold text-slate-900">{logs.to || 0}</span> dari{' '}
                            <span className="font-semibold text-slate-900">{logs.total}</span> catatan
                        </div>
                        <div className="flex items-center gap-1">
                            {logs.links.map((link, idx) =>
                                link.url ? (
                                    <Link
                                        key={idx}
                                        href={link.url}
                                        className={`rounded-md px-3 py-1.5 font-semibold transition-colors ${
                                            link.active
                                                ? 'bg-slate-900 text-white shadow-xs'
                                                : 'border border-slate-200 bg-white text-slate-700 hover:bg-slate-200/80'
                                        }`}
                                        dangerouslySetInnerHTML={{ __html: link.label }}
                                    />
                                ) : (
                                    <span
                                        key={idx}
                                        className="rounded-md bg-slate-100/60 px-3 py-1.5 font-medium text-slate-400"
                                        dangerouslySetInnerHTML={{ __html: link.label }}
                                    />
                                )
                            )}
                        </div>
                    </div>
                )}
            </div>
        </AdminLayout>
    );
}
