import { Link } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import EmptyState from '@/Components/Admin/EmptyState';
import StatusBadge from '@/Components/Admin/StatusBadge';
import { ClipboardCheck, Play, ListChecks, AlertTriangle, CheckCircle2 } from 'lucide-react';

const MODULE_LABELS = {
    savings: 'Simpanan',
    loans: 'Pinjaman & Angsuran',
    cash: 'Kas',
};

const TYPE_LABELS = {
    BALANCE_MISMATCH: 'Selisih Saldo',
    DUPLICATE_REFERENCE: 'Referensi Ganda',
    MISSING_REFERENCE: 'Referensi Hilang',
    INVALID_STATUS: 'Status Tidak Valid',
    UNALLOCATED_PAYMENT: 'Pembayaran Tanpa Alokasi',
    CASH_VARIANCE: 'Selisih Kas',
    INCOMPLETE_DATA: 'Data Tidak Lengkap',
};

export default function Index({ lastRuns, openByModule, resolvedByModule, openByType, can }) {
    const totalOpen = Object.values(openByModule).reduce((a, b) => a + Number(b), 0);
    const totalResolved = Object.values(resolvedByModule).reduce((a, b) => a + Number(b), 0);

    return (
        <AdminLayout title="Rekonsiliasi" pageTitle="Rekonsiliasi Data">
            <div className="mb-6 flex flex-col gap-4 rounded-xl border border-slate-200 bg-white p-6 shadow-xs sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 className="flex items-center gap-2 text-xl font-bold tracking-tight text-slate-900 sm:text-2xl">
                        <ClipboardCheck className="h-6 w-6 text-emerald-600" />
                        <span>Pemeriksaan Konsistensi Data</span>
                    </h2>
                    <p className="mt-1 text-xs text-slate-500 sm:text-sm">
                        Memverifikasi saldo simpanan, pinjaman & angsuran, dan kas terhadap transaksi sumber. Pemeriksaan
                        hanya membaca data dan tidak pernah mengubah saldo.
                    </p>
                </div>
                <Link
                    href={route('rekonsiliasi.runs')}
                    className="inline-flex items-center justify-center gap-2 rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-emerald-700 active:scale-95"
                >
                    <Play className="h-4 w-4" />
                    Jalankan Rekonsiliasi
                </Link>
            </div>

            {/* Ringkasan temuan */}
            <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-xs">
                    <div className="flex items-center justify-between">
                        <p className="text-xs font-medium text-slate-500">Temuan Belum Ditangani</p>
                        <AlertTriangle className="h-4 w-4 text-amber-500" />
                    </div>
                    <p className="mt-2 text-2xl font-bold text-slate-900">{totalOpen}</p>
                </div>
                <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-xs">
                    <div className="flex items-center justify-between">
                        <p className="text-xs font-medium text-slate-500">Temuan Selesai</p>
                        <CheckCircle2 className="h-4 w-4 text-emerald-500" />
                    </div>
                    <p className="mt-2 text-2xl font-bold text-slate-900">{totalResolved}</p>
                </div>
                {Object.entries(MODULE_LABELS).map(([module, label]) => (
                    <div key={module} className="rounded-xl border border-slate-200 bg-white p-5 shadow-xs">
                        <p className="text-xs font-medium text-slate-500">Terbuka: {label}</p>
                        <p className="mt-2 text-2xl font-bold text-slate-900">{Number(openByModule[module] ?? 0)}</p>
                        <p className="mt-1 text-[11px] text-slate-400">Selesai: {Number(resolvedByModule[module] ?? 0)}</p>
                    </div>
                ))}
            </div>

            {/* Temuan per jenis */}
            {Object.keys(openByType).length > 0 && (
                <div className="mb-6 rounded-xl border border-slate-200 bg-white p-6 shadow-xs">
                    <h3 className="text-sm font-semibold text-slate-900">Temuan Terbuka per Jenis Pemeriksaan</h3>
                    <div className="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        {Object.entries(openByType).map(([type, total]) => (
                            <Link
                                key={type}
                                href={route('rekonsiliasi.findings', { finding_type: type, status: 'OPEN' })}
                                className="rounded-lg border border-slate-200 bg-slate-50/60 px-4 py-3 transition-colors hover:bg-slate-100"
                            >
                                <p className="text-xs font-semibold text-slate-700">{TYPE_LABELS[type] || type}</p>
                                <p className="mt-1 text-xl font-bold text-slate-900">{Number(total)}</p>
                            </Link>
                        ))}
                    </div>
                </div>
            )}

            {/* Proses terakhir */}
            <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xs">
                <div className="flex items-center justify-between border-b border-slate-200 px-6 py-4">
                    <h3 className="text-sm font-semibold text-slate-900">Rekonsiliasi Terakhir</h3>
                    <Link href={route('rekonsiliasi.runs')} className="text-xs font-semibold text-emerald-600 hover:text-emerald-700">
                        Lihat semua &rarr;
                    </Link>
                </div>
                {lastRuns.length > 0 ? (
                    <div className="divide-y divide-slate-100">
                        {lastRuns.map((run) => (
                            <Link
                                key={run.id}
                                href={route('rekonsiliasi.runs.show', run.id)}
                                className="flex items-center justify-between px-6 py-4 transition-colors hover:bg-slate-50"
                            >
                                <div>
                                    <p className="font-mono text-xs font-semibold text-slate-900">{run.run_number}</p>
                                    <p className="mt-0.5 text-[11px] text-slate-500">
                                        {run.period_start} s/d {run.period_end} · {run.modules.map((m) => MODULE_LABELS[m] || m).join(', ')}
                                        {run.runner ? ` · ${run.runner.name}` : ''}
                                    </p>
                                </div>
                                <div className="flex items-center gap-3">
                                    <span className="text-xs text-slate-500">{run.findings_count} temuan</span>
                                    <StatusBadge status={run.status} />
                                </div>
                            </Link>
                        ))}
                    </div>
                ) : (
                    <div className="p-8">
                        <EmptyState
                            icon={ListChecks}
                            title="Belum Ada Proses Rekonsiliasi"
                            description="Jalankan rekonsiliasi pertama untuk memeriksa konsistensi saldo terhadap transaksi sumber."
                        />
                    </div>
                )}
            </div>
        </AdminLayout>
    );
}

