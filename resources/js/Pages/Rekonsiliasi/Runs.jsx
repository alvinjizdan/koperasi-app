import { useState } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import EmptyState from '@/Components/Admin/EmptyState';
import { Combobox, ComboboxContent, ComboboxEmpty, ComboboxInput, ComboboxItem, ComboboxList } from '@/Components/ui/combobox';
import { Play, ListChecks } from 'lucide-react';
import StatusBadge from '@/Components/Admin/StatusBadge';

const MODULE_OPTIONS = [
    { value: 'savings', label: 'Simpanan' },
    { value: 'loans', label: 'Pinjaman & Angsuran' },
    { value: 'cash', label: 'Kas' },
];

const RUN_STATUS_OPTIONS = [
    { value: 'all', label: 'Semua Status' },
    { value: 'PENDING', label: 'PENDING' },
    { value: 'RUNNING', label: 'RUNNING' },
    { value: 'COMPLETED', label: 'COMPLETED' },
    { value: 'FAILED', label: 'FAILED' },
];

export default function Runs({ runs, filters, can }) {
    const [status, setStatus] = useState(filters.status || 'all');

    const form = useForm({
        period_start: new Date().toISOString().slice(0, 10),
        period_end: new Date().toISOString().slice(0, 10),
        modules: [],
    });

    const submit = (e) => {
        e.preventDefault();
        form.post(route('rekonsiliasi.runs.store'), {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    const toggleModule = (value) => {
        const next = form.data.modules.includes(value)
            ? form.data.modules.filter((m) => m !== value)
            : [...form.data.modules, value];
        form.setData('modules', next);
    };

    const applyStatus = (value) => {
        setStatus(value);
        router.get(route('rekonsiliasi.runs'), { status: value }, { preserveState: true, replace: true });
    };

    return (
        <AdminLayout title="Proses Rekonsiliasi" pageTitle="Proses Rekonsiliasi">
            {can?.run && (
                <form
                    onSubmit={submit}
                    className="mb-6 rounded-xl border border-slate-200 bg-white p-6 shadow-xs"
                >
                    <h2 className="flex items-center gap-2 text-base font-bold text-slate-900">
                        <Play className="h-4 w-4 text-emerald-600" />
                        Jalankan Rekonsiliasi Baru
                    </h2>
                    <p className="mt-1 text-xs text-slate-500">
                        Pemeriksaan bersifat baca-saja terhadap data sumber dan aman dijalankan kapan pun. Untuk dataset
                        besar, proses berjalan sinkron pada waktu eksekusi.
                    </p>

                    <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label className="mb-1 block text-xs font-semibold text-slate-700" htmlFor="period_start">
                                Periode Awal
                            </label>
                            <input
                                id="period_start"
                                type="date"
                                value={form.data.period_start}
                                onChange={(e) => form.setData('period_start', e.target.value)}
                                className="w-full rounded-lg border border-slate-300 bg-slate-50/50 px-3 py-2 text-sm text-slate-900 focus:border-slate-900 focus:bg-white focus:outline-none focus:ring-1 focus:ring-slate-900"
                            />
                            {form.errors.period_start && <p className="mt-1 text-xs text-red-600">{form.errors.period_start}</p>}
                        </div>
                        <div>
                            <label className="mb-1 block text-xs font-semibold text-slate-700" htmlFor="period_end">
                                Periode Akhir
                            </label>
                            <input
                                id="period_end"
                                type="date"
                                value={form.data.period_end}
                                onChange={(e) => form.setData('period_end', e.target.value)}
                                className="w-full rounded-lg border border-slate-300 bg-slate-50/50 px-3 py-2 text-sm text-slate-900 focus:border-slate-900 focus:bg-white focus:outline-none focus:ring-1 focus:ring-slate-900"
                            />
                            {form.errors.period_end && <p className="mt-1 text-xs text-red-600">{form.errors.period_end}</p>}
                        </div>
                    </div>

                    <div className="mt-4">
                        <p className="mb-2 text-xs font-semibold text-slate-700">Modul yang Diperiksa</p>
                        <div className="flex flex-wrap gap-3">
                            {MODULE_OPTIONS.map((opt) => (
                                <label key={opt.value} className="flex cursor-pointer items-center gap-2 rounded-lg border border-slate-200 bg-slate-50/60 px-3 py-2 text-xs font-medium text-slate-700 has-checked:border-emerald-500 has-checked:bg-emerald-50">
                                    <input
                                        type="checkbox"
                                        checked={form.data.modules.includes(opt.value)}
                                        onChange={() => toggleModule(opt.value)}
                                        className="h-4 w-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500"
                                    />
                                    {opt.label}
                                </label>
                            ))}
                        </div>
                        {form.errors.modules && <p className="mt-1 text-xs text-red-600">{form.errors.modules}</p>}
                    </div>

                    <div className="mt-5 flex items-center gap-3">
                        <button
                            type="submit"
                            disabled={form.processing}
                            className="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-emerald-700 active:scale-95 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {form.processing ? 'Memproses...' : 'Jalankan Sekarang'}
                        </button>
                        {form.wasSuccessful && (
                            <span className="text-xs font-medium text-emerald-600">Rekonsiliasi selesai dijalankan.</span>
                        )}
                    </div>
                </form>
            )}

            <div className="mb-4 flex items-center gap-3">
                <Combobox
                    items={RUN_STATUS_OPTIONS.map((o) => o.value)}
                    value={status}
                    onValueChange={(v) => applyStatus(v ?? 'all')}
                    itemToStringValue={(v) => RUN_STATUS_OPTIONS.find((o) => o.value === v)?.label ?? v}
                >
                    <ComboboxInput aria-label="Filter status rekonsiliasi" />
                    <ComboboxContent>
                        <ComboboxEmpty>Tidak ada status.</ComboboxEmpty>
                        <ComboboxList>
                            {(v) => (
                                <ComboboxItem key={v} value={v}>
                                    {RUN_STATUS_OPTIONS.find((o) => o.value === v)?.label}
                                </ComboboxItem>
                            )}
                        </ComboboxList>
                    </ComboboxContent>
                </Combobox>
            </div>

            <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xs">
                {runs.data.length > 0 ? (
                    <div className="divide-y divide-slate-100">
                        {runs.data.map((run) => (
                            <Link
                                key={run.id}
                                href={route('rekonsiliasi.runs.show', run.id)}
                                className="flex flex-col gap-2 px-6 py-4 transition-colors hover:bg-slate-50 sm:flex-row sm:items-center sm:justify-between"
                            >
                                <div>
                                    <p className="font-mono text-xs font-semibold text-slate-900">{run.run_number}</p>
                                    <p className="mt-0.5 text-[11px] text-slate-500">
                                        {run.period_start} s/d {run.period_end} · {run.modules.map((m) => m).join(', ')}
                                        {run.runner ? ` · ${run.runner.name}` : ''}
                                        {run.finished_at ? ` · selesai ${run.finished_at}` : ''}
                                    </p>
                                    {run.error_message && (
                                        <p className="mt-1 text-[11px] text-red-600">{run.error_message}</p>
                                    )}
                                </div>
                                <div className="flex items-center gap-3">
                                    <span className="text-xs text-slate-500">
                                        {run.checked_rows} baris · {run.findings_count} temuan
                                    </span>
                                    <StatusBadge status={run.status} />
                                </div>
                            </Link>
                        ))}
                    </div>
                ) : (
                    <div className="p-8">
                        <EmptyState
                            icon={ListChecks}
                            title="Belum Ada Proses"
                            description="Rekonsiliasi yang dijalankan akan tampil di sini beserta status dan jumlah temuannya."
                        />
                    </div>
                )}

                {runs.links && runs.links.length > 3 && (
                    <Pagination links={runs.links} />
                )}
            </div>
        </AdminLayout>
    );
}

function Pagination({ links }) {
    return (
        <div className="flex flex-wrap items-center justify-center gap-1 border-t border-slate-200 bg-slate-50/50 px-6 py-4">
            {links.map((link, idx) =>
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
    );
}
