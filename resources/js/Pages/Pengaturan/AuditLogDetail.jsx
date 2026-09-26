import { Link } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { ChevronLeft, FileSearch, ShieldCheck } from 'lucide-react';
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

function formatValue(value) {
    if (value === null || value === undefined) {
        return '(kosong)';
    }

    return typeof value === 'object' ? JSON.stringify(value) : String(value);
}

export default function AuditLogDetail({ log }) {
    const changes = log.changes ? Object.entries(log.changes) : [];

    return (
        <AdminLayout title="Detail Audit" pageTitle={`Detail Audit #${log.id}`}>
            <Link
                href={route('pengaturan.audit.index')}
                className="mb-4 inline-flex items-center gap-1 text-xs font-semibold text-slate-500 hover:text-slate-900"
            >
                <ChevronLeft className="h-3.5 w-3.5" />
                Kembali ke audit trail
            </Link>

            <div className="mb-6 rounded-xl border border-slate-200 bg-white p-6 shadow-xs">
                <div className="flex items-center gap-2">
                    <span className="rounded-full bg-slate-100 px-2.5 py-0.5 text-[11px] font-semibold text-slate-700">
                        {ACTION_LABELS[log.action] || log.action}
                    </span>
                    <code className="font-mono text-[11px] text-slate-400">{log.action}</code>
                </div>
                <p className="mt-2 text-sm text-slate-800">{log.description}</p>

                <dl className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <dt className="text-[11px] font-medium uppercase tracking-wider text-slate-400">Pengguna</dt>
                        <dd className="mt-0.5 text-xs font-semibold text-slate-900">
                            {log.actor ? `${log.actor.name} (${log.actor.email})` : 'Sistem'}
                        </dd>
                    </div>
                    <div>
                        <dt className="text-[11px] font-medium uppercase tracking-wider text-slate-400">Waktu Kejadian</dt>
                        <dd className="mt-0.5 text-xs text-slate-900">{formatDateTime(log.created_at)}</dd>
                    </div>
                    <div>
                        <dt className="text-[11px] font-medium uppercase tracking-wider text-slate-400">Objek Terkait</dt>
                        <dd className="mt-0.5 font-mono text-xs text-slate-900">
                            {log.object_type}
                            {log.object_id ? `#${log.object_id}` : ''}
                            {log.object_label ? ` · ${log.object_label}` : ''}
                        </dd>
                    </div>
                    <div>
                        <dt className="text-[11px] font-medium uppercase tracking-wider text-slate-400">Alamat IP</dt>
                        <dd className="mt-0.5 font-mono text-xs text-slate-900">{log.ip_address ?? '-'}</dd>
                    </div>
                </dl>
            </div>

            <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xs">
                <div className="flex items-center gap-2 border-b border-slate-200 px-6 py-4">
                    <FileSearch className="h-4 w-4 text-slate-500" />
                    <h3 className="text-sm font-semibold text-slate-900">Ringkasan Perubahan</h3>
                </div>
                {changes.length > 0 ? (
                    <div className="overflow-x-auto p-6">
                        <table className="w-full text-left text-xs">
                            <thead className="text-slate-400">
                                <tr>
                                    <th className="pr-4 font-semibold uppercase tracking-wider">Field</th>
                                    <th className="pr-4 font-semibold uppercase tracking-wider">Sebelum</th>
                                    <th className="font-semibold uppercase tracking-wider">Sesudah</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-200">
                                {changes.map(([field, change]) => (
                                    <tr key={field}>
                                        <td className="py-2 pr-4 font-mono font-semibold text-slate-700">{field}</td>
                                        <td className="py-2 pr-4 text-slate-500">
                                            {change && typeof change === 'object' && 'old' in change
                                                ? formatValue(change.old)
                                                : formatValue(change)}
                                        </td>
                                        <td className="py-2 font-semibold text-slate-900">
                                            {change && typeof change === 'object' && 'new' in change
                                                ? formatValue(change.new)
                                                : formatValue(change)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                ) : (
                    <div className="px-6 py-6 text-xs text-slate-500">
                        Peristiwa ini tidak memiliki ringkasan perubahan field (tindakan status atau metadata saja).
                    </div>
                )}
            </div>

            <div className="mt-4 flex items-center gap-2 text-[11px] text-slate-400">
                <ShieldCheck className="h-3.5 w-3.5" />
                Catatan audit tidak dapat diubah atau dihapus melalui aplikasi. Field sensitif (password, token,
                kredensial) tidak pernah dicatat.
            </div>
        </AdminLayout>
    );
}
