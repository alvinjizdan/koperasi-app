import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import EmptyState from '@/Components/Admin/EmptyState';
import { Combobox, ComboboxContent, ComboboxEmpty, ComboboxInput, ComboboxItem, ComboboxList } from '@/Components/ui/combobox';
import { ChevronLeft, ListChecks, AlertTriangle, CheckCircle2 } from 'lucide-react';
import StatusBadge from '@/Components/Admin/StatusBadge';

const TYPE_LABELS = {
    BALANCE_MISMATCH: 'Selisih Saldo',
    DUPLICATE_REFERENCE: 'Referensi Ganda',
    MISSING_REFERENCE: 'Referensi Hilang',
    INVALID_STATUS: 'Status Tidak Valid',
    UNALLOCATED_PAYMENT: 'Pembayaran Tanpa Alokasi',
    CASH_VARIANCE: 'Selisih Kas',
    INCOMPLETE_DATA: 'Data Tidak Lengkap',
};

const PRIORITY_STYLES = {
    HIGH: 'bg-red-50 text-red-700 border-red-200',
    MEDIUM: 'bg-amber-50 text-amber-700 border-amber-200',
    LOW: 'bg-slate-100 text-slate-600 border-slate-200',
};

const FINDING_STATUS_STYLES = {
    OPEN: 'bg-red-50 text-red-700 border-red-200',
    UNDER_REVIEW: 'bg-amber-50 text-amber-700 border-amber-200',
    RESOLVED: 'bg-emerald-50 text-emerald-700 border-emerald-200',
    ACCEPTED_WITH_REASON: 'bg-slate-100 text-slate-600 border-slate-200',
};

const FINDING_STATUS_OPTIONS = [
    { value: 'all', label: 'Semua Status Temuan' },
    { value: 'OPEN', label: 'OPEN' },
    { value: 'UNDER_REVIEW', label: 'UNDER_REVIEW' },
    { value: 'RESOLVED', label: 'RESOLVED' },
    { value: 'ACCEPTED_WITH_REASON', label: 'ACCEPTED_WITH_REASON' },
];

export default function Show({ run, findings, filters, can }) {
    const [status, setStatus] = useState(filters.status || 'all');

    const applyStatus = (value) => {
        setStatus(value);
        router.get(route('rekonsiliasi.runs.show', run.id), { status: value }, { preserveState: true, replace: true });
    };

    const summary = run.summary;

    return (
        <AdminLayout title={`Rekonsiliasi ${run.run_number}`} pageTitle={`Detail Rekonsiliasi ${run.run_number}`}>
            <Link
                href={route('rekonsiliasi.runs')}
                className="mb-4 inline-flex items-center gap-1 text-xs font-semibold text-slate-500 hover:text-slate-900"
            >
                <ChevronLeft className="h-3.5 w-3.5" />
                Kembali ke daftar proses
            </Link>

            {/* Ringkasan proses */}
            <div className="mb-6 rounded-xl border border-slate-200 bg-white p-6 shadow-xs">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <p className="font-mono text-sm font-bold text-slate-900">{run.run_number}</p>
                        <p className="mt-1 text-xs text-slate-500">
                            Periode {run.period_start} s/d {run.period_end} · Modul: {run.modules.join(', ')}
                        </p>
                        <p className="mt-0.5 text-[11px] text-slate-400">
                            Dijalankan oleh {run.runner?.name ?? 'Sistem'} · Mulai {run.started_at ?? '-'} · Selesai{' '}
                            {run.finished_at ?? '-'}
                        </p>
                    </div>
                    <StatusBadge status={run.status} />
                </div>

                {run.status === 'FAILED' && run.error_message && (
                    <div className="mt-4 rounded-lg border border-red-200 bg-red-50 p-3 text-xs text-red-700">
                        <p className="font-semibold">Proses gagal:</p>
                        <p className="mt-0.5">{run.error_message}</p>
                    </div>
                )}

                {summary && (
                    <div className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                        <div className="rounded-lg bg-slate-50 px-4 py-3">
                            <p className="text-[11px] font-medium text-slate-500">Baris Diperiksa</p>
                            <p className="mt-0.5 text-lg font-bold text-slate-900">{run.checked_rows}</p>
                        </div>
                        <div className="rounded-lg bg-slate-50 px-4 py-3">
                            <p className="text-[11px] font-medium text-slate-500">Total Temuan</p>
                            <p className="mt-0.5 text-lg font-bold text-slate-900">{run.findings_count}</p>
                        </div>
                        {Array.isArray(summary) &&
                            summary.map((s) => (
                                <div key={s.module} className="rounded-lg bg-slate-50 px-4 py-3">
                                    <p className="text-[11px] font-medium text-slate-500">Modul {s.module}</p>
                                    <p className="mt-0.5 text-sm font-semibold text-slate-900">
                                        {Object.entries(s)
                                            .filter(([key]) => key.endsWith('_count') || key === 'accounts_checked' || key === 'loans_checked' || key === 'sessions_checked')
                                            .map(([key, value]) => `${key.replace('_count', '').replace(/_/g, ' ')}: ${value}`)
                                            .join(' · ') || 'tidak ada temuan'}
                                    </p>
                                </div>
                            ))}
                    </div>
                )}

                {summary && Array.isArray(summary) && summary.some((s) => s.note) && (
                    <div className="mt-4 rounded-lg border border-slate-200 bg-slate-50/80 p-3 text-[11px] text-slate-600">
                        {summary.filter((s) => s.note).map((s) => (
                            <p key={s.module}>
                                <span className="font-semibold">{s.module}:</span> {s.note}
                            </p>
                        ))}
                    </div>
                )}
            </div>

            {/* Filter status temuan */}
            <div className="mb-4 flex items-center gap-3">
                <Combobox
                    items={FINDING_STATUS_OPTIONS.map((o) => o.value)}
                    value={status}
                    onValueChange={(v) => applyStatus(v ?? 'all')}
                    itemToStringValue={(v) => FINDING_STATUS_OPTIONS.find((o) => o.value === v)?.label ?? v}
                >
                    <ComboboxInput aria-label="Filter status temuan" />
                    <ComboboxContent>
                        <ComboboxEmpty>Tidak ada status.</ComboboxEmpty>
                        <ComboboxList>
                            {(v) => (
                                <ComboboxItem key={v} value={v}>
                                    {FINDING_STATUS_OPTIONS.find((o) => o.value === v)?.label}
                                </ComboboxItem>
                            )}
                        </ComboboxList>
                    </ComboboxContent>
                </Combobox>
            </div>

            {/* Daftar temuan */}
            <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xs">
                {findings.data.length > 0 ? (
                    <div className="divide-y divide-slate-100">
                        {findings.data.map((finding) => (
                            <Link
                                key={finding.id}
                                href={route('rekonsiliasi.findings.show', finding.id)}
                                className="flex flex-col gap-2 px-6 py-4 transition-colors hover:bg-slate-50 sm:flex-row sm:items-center sm:justify-between"
                            >
                                <div className="min-w-0">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="rounded-full bg-slate-100 px-2.5 py-0.5 text-[11px] font-semibold text-slate-700">
                                            {TYPE_LABELS[finding.finding_type] || finding.finding_type}
                                        </span>
                                        <span className={`rounded-full border px-2 py-0.5 text-[10px] font-semibold ${PRIORITY_STYLES[finding.priority]}`}>
                                            {finding.priority}
                                        </span>
                                        <span className="font-mono text-[11px] text-slate-400">
                                            {finding.object_label ?? finding.object_type ?? '-'}
                                        </span>
                                    </div>
                                    {finding.variance !== null && finding.variance !== undefined && (
                                        <p className="mt-1 text-[11px] font-medium text-slate-600">
                                            Selisih: Rp {Number(finding.variance).toLocaleString('id-ID')}
                                        </p>
                                    )}
                                </div>
                                <span className={`shrink-0 rounded-full border px-2.5 py-0.5 text-[11px] font-semibold ${FINDING_STATUS_STYLES[finding.status]}`}>
                                    {finding.status}
                                </span>
                            </Link>
                        ))}
                    </div>
                ) : (
                    <div className="p-8">
                        <EmptyState
                            icon={run.findings_count === 0 ? CheckCircle2 : ListChecks}
                            title={run.findings_count === 0 ? 'Tidak Ada Temuan' : 'Tidak Ada Temuan pada Filter Ini'}
                            description={
                                run.findings_count === 0
                                    ? 'Seluruh pemeriksaan konsistensi menghasilkan data yang sesuai pada periode ini.'
                                    : 'Coba ubah filter status untuk melihat temuan lain.'
                            }
                        />
                    </div>
                )}

                {findings.links && findings.links.length > 3 && (
                    <div className="flex flex-wrap items-center justify-center gap-1 border-t border-slate-200 bg-slate-50/50 px-6 py-4">
                        {findings.links.map((link, idx) =>
                            link.url ? (
                                <Link
                                    key={idx}
                                    href={link.url}
                                    className={`rounded-md px-3 py-1.5 text-xs font-semibold transition-colors ${
                                        link.active
                                            ? 'bg-slate-900 text-white shadow-xs'
                                            : 'border border-slate-200 bg-white text-slate-700 hover:bg-slate-200/80'
                                    }`}
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                />
                            ) : (
                                <span
                                    key={idx}
                                    className="rounded-md bg-slate-100/60 px-3 py-1.5 text-xs font-medium text-slate-400"
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                />
                            )
                        )}
                    </div>
                )}
            </div>

            {can?.run && (
                <div className="mt-4 flex items-center gap-2 text-[11px] text-slate-400">
                    <AlertTriangle className="h-3.5 w-3.5" />
                    Rekonsiliasi tidak pernah mengubah saldo atau transaksi sumber. Perbaikan hanya melalui mekanisme
                    koreksi resmi pada modul terkait.
                </div>
            )}
        </AdminLayout>
    );
}
