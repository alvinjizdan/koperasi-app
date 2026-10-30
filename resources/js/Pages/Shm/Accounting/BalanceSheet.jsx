import React from 'react';
import { router } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import { ScrollArea, ScrollBar } from '@/Components/ui/scroll-area';
import EmptyState from '@/Components/Admin/EmptyState';
import { formatRupiah, formatDate } from '@/utils/format';
import { CheckCircle2, AlertCircle, Scale, Download } from 'lucide-react';

export default function BalanceSheet({ report, filters }) {
    const { sections, summary, balanced, meta } = report;
    const inputClass = 'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 focus:border-emerald-500 focus:outline-none';

    const handleFilterSubmit = (e) => {
        e.preventDefault();
        const formData = new FormData(e.currentTarget);
        const asOfDate = formData.get('as_of_date');
        router.get(route('shm.balance-sheet.index'), { as_of_date: asOfDate }, { preserveState: true });
    };

    const handleExport = () => {
        const url = route('shm.balance-sheet.export', { as_of_date: filters.as_of_date });
        window.location.href = url;
    };

    return (
        <ShmLayout title="Neraca BSM Makro" pageTitle="Akuntansi BSM Makro — Neraca (Balance Sheet)">
            <div className="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 className="text-xl font-semibold text-gray-800">Neraca BSM Makro</h1>
                    <p className="mt-1 text-sm text-gray-500">
                        Posisi keuangan per <strong>{filters.as_of_date ? formatDate(filters.as_of_date) : 'sekarang'}</strong> — berdasarkan jurnal <strong>POSTED</strong> pada entitas <strong>Akuntansi BSM Makro</strong>.
                    </p>
                </div>
                <div>
                    <button
                        type="button"
                        onClick={handleExport}
                        className="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2 text-xs font-semibold text-slate-700 shadow-xs hover:bg-slate-50"
                    >
                        <Download className="h-4 w-4" />
                        <span>Ekspor XLSX</span>
                    </button>
                </div>
            </div>

            {/* Filter Form */}
            <form onSubmit={handleFilterSubmit} className="mb-6 grid grid-cols-1 gap-4 rounded-xl border border-slate-200 bg-white p-6 shadow-xs sm:grid-cols-4">
                <div className="sm:col-span-2">
                    <label className="text-xs font-semibold text-slate-600" htmlFor="as_of_date">
                        Posisi Per Tanggal (As of Date)
                    </label>
                    <input
                        id="as_of_date"
                        name="as_of_date"
                        type="date"
                        defaultValue={filters.as_of_date ?? ''}
                        className={inputClass}
                        required
                    />
                </div>
                <div className="sm:col-span-2 flex items-end">
                    <button
                        type="submit"
                        className="w-full rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800"
                    >
                        Tampilkan Neraca
                    </button>
                </div>
            </form>

            {/* Limitations Notice */}
            {meta?.limitations && meta.limitations.length > 0 && (
                <div className="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-800">
                    <strong>Batasan:</strong> {meta.limitations.join(' ')} Posisi neraca mencerminkan mutasi riil jurnal POSTED tanpa jurnal penutup otomatis.
                </div>
            )}

            {/* Balance Status Banner */}
            <div className={`mb-6 flex flex-wrap items-center justify-between gap-4 rounded-lg border px-4 py-3 text-xs font-semibold ${
                balanced
                    ? 'border-emerald-200 bg-emerald-50 text-emerald-800'
                    : 'border-rose-200 bg-rose-50 text-rose-800'
            }`}>
                <div className="flex items-center gap-2">
                    {balanced ? (
                        <CheckCircle2 className="h-4 w-4 text-emerald-600 shrink-0" />
                    ) : (
                        <AlertCircle className="h-4 w-4 text-rose-600 shrink-0" />
                    )}
                    <span>
                        Status: <strong>{summary.status ?? (balanced ? 'BALANCED' : 'NOT BALANCED')}</strong> — {
                            balanced
                                ? 'Persamaan Neraca SEIMBANG (Aset = Liabilitas + Ekuitas).'
                                : `Persamaan Neraca TIDAK SEIMBANG — Selisih: ${formatRupiah(summary.difference)}.`
                        }
                        {summary.current_period_result !== 0 && (
                            <span className="block sm:inline sm:ml-1 text-slate-600 font-normal">
                                (Hasil kumulatif berjalan: {formatRupiah(summary.current_period_result)} belum diposting ke ekuitas).
                            </span>
                        )}
                    </span>
                </div>
                {summary.difference !== 0 && (
                    <div>
                        Selisih: <strong className="font-mono">{formatRupiah(summary.difference)}</strong>
                    </div>
                )}
            </div>

            {/* 3 Columns: Asset, Liability, Equity */}
            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                {['asset', 'liability', 'equity'].map((categoryKey) => {
                    const section = sections[categoryKey] ?? { label: categoryKey, accounts: [], total: 0 };
                    return (
                        <div key={categoryKey} className="rounded-xl border border-slate-200 bg-white p-6 shadow-xs flex flex-col justify-between">
                            <div>
                                <div className="flex items-center justify-between pb-3 border-b border-slate-200">
                                    <h3 className="text-sm font-bold uppercase tracking-wider text-slate-700">
                                        {section.label}
                                    </h3>
                                    <span className="text-xs font-semibold text-slate-500 uppercase">
                                        {categoryKey === 'asset' ? 'Normal: Debit' : 'Normal: Kredit'}
                                    </span>
                                </div>
                                <ScrollArea className="mt-3 max-h-96">
                                    <table className="w-full text-xs sm:text-sm">
                                        <tbody className="divide-y divide-slate-100">
                                            {section.accounts.length === 0 ? (
                                                <tr>
                                                    <td className="py-4 text-center text-slate-400 italic">
                                                        Tidak ada akun dengan mutasi.
                                                    </td>
                                                </tr>
                                            ) : (
                                                section.accounts.map((acc) => (
                                                    <tr key={acc.id} className="hover:bg-slate-50">
                                                        <td className="py-2.5 font-mono text-slate-600">{acc.code}</td>
                                                        <td className="py-2.5 text-slate-900 font-medium">{acc.name}</td>
                                                        <td className="py-2.5 text-right font-mono font-semibold text-slate-900">
                                                            {formatRupiah(acc.balance)}
                                                        </td>
                                                    </tr>
                                                ))
                                            )}
                                        </tbody>
                                    </table>
                                </ScrollArea>
                            </div>
                            <div className="mt-4 pt-3 border-t-2 border-slate-300 flex items-center justify-between font-bold">
                                <span className="text-slate-700">TOTAL {section.label.toUpperCase()}</span>
                                <span className="text-slate-900 font-mono text-base">{formatRupiah(section.total)}</span>
                            </div>
                        </div>
                    );
                })}
            </div>

            {/* Accounting Equation Summary Box */}
            <div className="mt-6 rounded-xl border border-slate-200 bg-white p-6 shadow-xs">
                <h3 className="text-sm font-bold uppercase tracking-wider text-slate-700 mb-4">
                    Ringkasan Persamaan Neraca (Assets = Liabilities + Equity)
                </h3>
                <dl className="grid grid-cols-1 sm:grid-cols-4 gap-4 text-xs">
                    <div className="rounded-lg bg-slate-50 p-4 border border-slate-200">
                        <dt className="text-slate-500 font-semibold">Total Aset (A)</dt>
                        <dd className="mt-1 text-xl font-bold font-mono text-slate-900">{formatRupiah(summary.total_assets)}</dd>
                    </div>
                    <div className="rounded-lg bg-slate-50 p-4 border border-slate-200">
                        <dt className="text-slate-500 font-semibold">Total Liabilitas (L)</dt>
                        <dd className="mt-1 text-xl font-bold font-mono text-slate-900">{formatRupiah(summary.total_liabilities)}</dd>
                    </div>
                    <div className="rounded-lg bg-slate-50 p-4 border border-slate-200">
                        <dt className="text-slate-500 font-semibold">Total Ekuitas (E)</dt>
                        <dd className="mt-1 text-xl font-bold font-mono text-slate-900">{formatRupiah(summary.total_equity)}</dd>
                    </div>
                    <div className={`rounded-lg p-4 border ${
                        balanced ? 'bg-emerald-50 border-emerald-200' : 'bg-rose-50 border-rose-200'
                    }`}>
                        <dt className="text-slate-600 font-semibold">Liabilitas + Ekuitas (L + E)</dt>
                        <dd className={`mt-1 text-xl font-bold font-mono ${balanced ? 'text-emerald-700' : 'text-rose-700'}`}>
                            {formatRupiah(summary.total_liabilities_and_equity)}
                        </dd>
                    </div>
                </dl>
            </div>
        </ShmLayout>
    );
}

