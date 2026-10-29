import React from 'react';
import { router } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import { ScrollArea, ScrollBar } from '@/Components/ui/scroll-area';
import EmptyState from '@/Components/Admin/EmptyState';
import { formatRupiah } from '@/utils/format';
import { CheckCircle2, AlertCircle, Scale, Download } from 'lucide-react';

export default function TrialBalance({ report, filters }) {
    const { rows, totals, balanced, status, meta } = report;
    const inputClass = 'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 focus:border-emerald-500 focus:outline-none';

    const handleFilterSubmit = (e) => {
        e.preventDefault();
        const formData = new FormData(e.currentTarget);
        const asOfDate = formData.get('as_of_date');
        router.get(route('shm.trial-balance.index'), { as_of_date: asOfDate }, { preserveState: true });
    };

    const handleExport = () => {
        const url = route('shm.trial-balance.export', { as_of_date: filters.as_of_date });
        window.location.href = url;
    };

    return (
        <ShmLayout title="Neraca Saldo SHM" pageTitle="Akuntansi SHM — Neraca Saldo (Trial Balance)">
            <div className="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h2 className="text-xl font-bold text-slate-900">Neraca Saldo SHM</h2>
                    <p className="mt-1 text-xs sm:text-sm text-slate-500">
                        Laporan saldo akun dari jurnal berstatus <strong>POSTED</strong> pada entitas <strong>Akuntansi SHM</strong>. Terisolasi penuh dari Koperasi.
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

            {/* Filter */}
            <form onSubmit={handleFilterSubmit} className="mb-6 grid grid-cols-1 gap-4 rounded-xl border border-slate-200 bg-white p-6 shadow-xs sm:grid-cols-4">
                <div className="sm:col-span-2">
                    <label className="text-xs font-semibold text-slate-600" htmlFor="as_of_date">
                        Per Tanggal (As of Date)
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
                        Tampilkan
                    </button>
                </div>
            </form>

            {/* Metadata / Limitation Warning */}
            {meta?.limitations && meta.limitations.length > 0 && (
                <div className="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-800">
                    <strong>Informasi:</strong> {meta.limitations.join(' ')}
                </div>
            )}

            {/* Status Balance Banner */}
            <div className={`mb-4 flex flex-wrap items-center justify-between gap-4 rounded-lg border px-4 py-3 text-xs font-semibold ${
                balanced
                    ? 'border-emerald-200 bg-emerald-50 text-emerald-800'
                    : 'border-rose-200 bg-rose-50 text-rose-800'
            }`}>
                <div className="flex items-center gap-2">
                    {balanced ? (
                        <CheckCircle2 className="h-4 w-4 text-emerald-600" />
                    ) : (
                        <AlertCircle className="h-4 w-4 text-rose-600" />
                    )}
                    <span>
                        Status: <strong>{status ?? (balanced ? 'BALANCED' : 'NOT BALANCED')}</strong> — {
                            balanced
                                ? 'Neraca saldo SEIMBANG (Total Debit = Total Kredit).'
                                : 'Neraca saldo TIDAK SEIMBANG — Terdapat selisih antara Total Debit dan Total Kredit.'
                        }
                    </span>
                </div>
                {totals?.difference > 0 && (
                    <div>
                        Selisih: <strong className="font-mono">{formatRupiah(totals.difference)}</strong>
                    </div>
                )}
            </div>

            {/* Content Table */}
            {rows.length === 0 ? (
                <EmptyState
                    icon={Scale}
                    title="Belum Ada Akun SHM"
                    description="Belum ada akun SHM yang terdaftar dalam Chart of Accounts atau belum ada transaksi jurnal POSTED."
                />
            ) : (
                <ScrollArea className="rounded-xl border border-slate-200 bg-white shadow-xs">
                    <ScrollBar orientation="horizontal" />
                    <table className="min-w-full divide-y divide-slate-200 text-xs sm:text-sm">
                        <thead className="bg-slate-50">
                            <tr>
                                <th className="px-4 py-3 text-left font-semibold text-slate-500">Kode Akun</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-500">Nama Akun</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-500">Tipe Akun</th>
                                <th className="px-4 py-3 text-center font-semibold text-slate-500">Saldo Normal</th>
                                <th className="px-4 py-3 text-right font-semibold text-slate-500">Debit</th>
                                <th className="px-4 py-3 text-right font-semibold text-slate-500">Kredit</th>
                                <th className="px-4 py-3 text-right font-semibold text-slate-500">Saldo</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {rows.map((row) => (
                                <tr key={row.id} className="hover:bg-slate-50">
                                    <td className="px-4 py-3 font-mono font-medium text-slate-900">{row.code}</td>
                                    <td className="px-4 py-3 text-slate-900">{row.name}</td>
                                    <td className="px-4 py-3 text-slate-600">{row.category_label}</td>
                                    <td className="px-4 py-3 text-center uppercase font-mono text-xs text-slate-600">
                                        {row.normal_balance}
                                    </td>
                                    <td className="px-4 py-3 text-right font-mono text-slate-900">
                                        {row.total_debit > 0 ? formatRupiah(row.total_debit) : '-'}
                                    </td>
                                    <td className="px-4 py-3 text-right font-mono text-slate-900">
                                        {row.total_credit > 0 ? formatRupiah(row.total_credit) : '-'}
                                    </td>
                                    <td className={`px-4 py-3 text-right font-mono font-semibold ${
                                        row.balance < 0 ? 'text-rose-600' : 'text-slate-900'
                                    }`}>
                                        {formatRupiah(row.balance)}
                                    </td>
                                </tr>
                            ))}
                            <tr className="bg-slate-50 font-bold border-t-2 border-slate-300">
                                <td colSpan={4} className="px-4 py-3 text-right text-slate-700 uppercase">
                                    TOTAL
                                </td>
                                <td className="px-4 py-3 text-right font-mono text-slate-900">
                                    {formatRupiah(totals.debit)}
                                </td>
                                <td className="px-4 py-3 text-right font-mono text-slate-900">
                                    {formatRupiah(totals.credit)}
                                </td>
                                <td className="px-4 py-3 text-right font-mono text-slate-500">
                                    -
                                </td>
                            </tr>
                            {totals.difference > 0 && (
                                <tr className="bg-rose-50 font-bold text-rose-700">
                                    <td colSpan={4} className="px-4 py-2 text-right">
                                        SELISIH (DIFFERENCE)
                                    </td>
                                    <td colSpan={2} className="px-4 py-2 text-center font-mono">
                                        {formatRupiah(totals.difference)}
                                    </td>
                                    <td className="px-4 py-2 text-right">
                                        {status}
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </ScrollArea>
            )}
        </ShmLayout>
    );
}

