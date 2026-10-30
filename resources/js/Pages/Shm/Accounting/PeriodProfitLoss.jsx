import React from 'react';
import { router } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import { ScrollArea, ScrollBar } from '@/Components/ui/scroll-area';
import EmptyState from '@/Components/Admin/EmptyState';
import { formatRupiah, formatDate } from '@/utils/format';
import { TrendingUp, TrendingDown, Minus, Download } from 'lucide-react';

export default function PeriodProfitLoss({ report, filters }) {
    const { sections, summary, meta } = report;
    const inputClass = 'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 focus:border-emerald-500 focus:outline-none';

    const revenueSection = sections?.revenue ?? { label: 'Pendapatan', accounts: [], total: 0 };
    const expenseSection = sections?.expense ?? { label: 'Beban', accounts: [], total: 0 };

    const hasData = revenueSection.accounts.length > 0 || expenseSection.accounts.length > 0;

    const handleFilterSubmit = (e) => {
        e.preventDefault();
        const formData = new FormData(e.currentTarget);
        const startDate = formData.get('start_date');
        const endDate = formData.get('end_date');
        router.get(route('shm.period-profit-loss.index'), {
            start_date: startDate,
            end_date: endDate,
        }, { preserveState: true });
    };

    const handleExport = () => {
        const url = route('shm.period-profit-loss.export', {
            start_date: filters.start_date,
            end_date: filters.end_date,
        });
        window.location.href = url;
    };

    const isProfit = summary.surplus_deficit > 0;
    const isLoss = summary.surplus_deficit < 0;

    return (
        <ShmLayout title="Laba Rugi Periode BSM Makro" pageTitle="Akuntansi BSM Makro — Laba Rugi Periode">
            <div className="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 className="text-xl font-semibold text-gray-800">Laporan Laba Rugi Periode BSM Makro</h1>
                    <p className="mt-1 text-sm text-gray-500">
                        Periode {filters.start_date ? formatDate(filters.start_date) : 'awal'} s.d. {filters.end_date ? formatDate(filters.end_date) : 'sekarang'} — berdasarkan jurnal <strong>POSTED</strong> pada entitas <strong>Akuntansi BSM Makro</strong>.
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
                <div>
                    <label className="text-xs font-semibold text-slate-600" htmlFor="start_date">Dari Tanggal</label>
                    <input id="start_date" name="start_date" type="date" defaultValue={filters.start_date ?? ''} className={inputClass} />
                </div>
                <div>
                    <label className="text-xs font-semibold text-slate-600" htmlFor="end_date">Sampai Tanggal</label>
                    <input id="end_date" name="end_date" type="date" defaultValue={filters.end_date ?? ''} className={inputClass} />
                </div>
                <div className="sm:col-span-2 flex items-end">
                    <button type="submit" className="w-full rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                        Tampilkan Laporan
                    </button>
                </div>
            </form>

            {/* Limitations Notice */}
            {meta?.limitations && meta.limitations.length > 0 && (
                <div className="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-800">
                    <strong>Batasan:</strong> {meta.limitations.join(' ')} Laba/Rugi adalah hasil operasional murni periode berjalan, tanpa penutupan buku atau pajak.
                </div>
            )}

            {!hasData ? (
                <EmptyState
                    icon={TrendingUp}
                    title="Belum Ada Mutasi Pendapatan / Beban"
                    description="Belum ada jurnal POSTED pada akun pendapatan maupun beban untuk rentang periode yang dipilih."
                />
            ) : (
                <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    {/* Revenue Section */}
                    <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-xs flex flex-col justify-between">
                        <div>
                            <div className="flex items-center justify-between pb-3 border-b border-slate-200">
                                <h3 className="text-sm font-bold uppercase tracking-wider text-slate-700">
                                    {revenueSection.label} (Revenue)
                                </h3>
                                <span className="text-xs font-semibold text-slate-500">Saldo Normal: Kredit</span>
                            </div>
                            <ScrollArea className="mt-3 max-h-96">
                                <table className="w-full text-xs sm:text-sm">
                                    <tbody className="divide-y divide-slate-100">
                                        {revenueSection.accounts.length === 0 ? (
                                            <tr>
                                                <td className="py-4 text-center text-slate-400 italic">
                                                    Tidak ada mutasi pendapatan.
                                                </td>
                                            </tr>
                                        ) : (
                                            revenueSection.accounts.map((acc) => (
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
                            <span className="text-slate-700">TOTAL {revenueSection.label.toUpperCase()}</span>
                            <span className="text-slate-900 font-mono text-base">{formatRupiah(revenueSection.total)}</span>
                        </div>
                    </div>

                    {/* Expense Section */}
                    <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-xs flex flex-col justify-between">
                        <div>
                            <div className="flex items-center justify-between pb-3 border-b border-slate-200">
                                <h3 className="text-sm font-bold uppercase tracking-wider text-slate-700">
                                    {expenseSection.label} (Expense)
                                </h3>
                                <span className="text-xs font-semibold text-slate-500">Saldo Normal: Debit</span>
                            </div>
                            <ScrollArea className="mt-3 max-h-96">
                                <table className="w-full text-xs sm:text-sm">
                                    <tbody className="divide-y divide-slate-100">
                                        {expenseSection.accounts.length === 0 ? (
                                            <tr>
                                                <td className="py-4 text-center text-slate-400 italic">
                                                    Tidak ada mutasi beban.
                                                </td>
                                            </tr>
                                        ) : (
                                            expenseSection.accounts.map((acc) => (
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
                            <span className="text-slate-700">TOTAL {expenseSection.label.toUpperCase()}</span>
                            <span className="text-slate-900 font-mono text-base">{formatRupiah(expenseSection.total)}</span>
                        </div>
                    </div>
                </div>
            )}

            {/* Summary Cards */}
            <div className="mt-6 rounded-xl border border-slate-200 bg-white p-6 shadow-xs">
                <h3 className="text-sm font-bold uppercase tracking-wider text-slate-700 mb-4">
                    Ringkasan Hasil Finansial Periode
                </h3>
                <div className="grid grid-cols-1 sm:grid-cols-3 gap-6">
                    <div className="rounded-lg bg-slate-50 p-4 border border-slate-200">
                        <span className="text-xs font-semibold text-slate-500">Total Pendapatan</span>
                        <div className="mt-1 text-xl font-bold font-mono text-slate-900">
                            {formatRupiah(summary.total_revenue)}
                        </div>
                    </div>
                    <div className="rounded-lg bg-slate-50 p-4 border border-slate-200">
                        <span className="text-xs font-semibold text-slate-500">Total Beban</span>
                        <div className="mt-1 text-xl font-bold font-mono text-slate-900">
                            {formatRupiah(summary.total_expense)}
                        </div>
                    </div>
                    <div className={`rounded-lg p-4 border ${
                        isProfit
                            ? 'bg-emerald-50 border-emerald-200 text-emerald-900'
                            : isLoss
                            ? 'bg-rose-50 border-rose-200 text-rose-900'
                            : 'bg-slate-50 border-slate-200 text-slate-900'
                    }`}>
                        <div className="flex items-center justify-between">
                            <span className="text-xs font-semibold uppercase">
                                Hasil: {summary.status ?? (isProfit ? 'LABA' : isLoss ? 'RUGI' : 'IMPAS')}
                            </span>
                            {isProfit && <TrendingUp className="h-5 w-5 text-emerald-600" />}
                            {isLoss && <TrendingDown className="h-5 w-5 text-rose-600" />}
                            {!isProfit && !isLoss && <Minus className="h-5 w-5 text-slate-500" />}
                        </div>
                        <div className={`mt-1 text-xl font-bold font-mono ${
                            isProfit ? 'text-emerald-700' : isLoss ? 'text-rose-700' : 'text-slate-900'
                        }`}>
                            {formatRupiah(summary.surplus_deficit)}
                        </div>
                    </div>
                </div>
            </div>
        </ShmLayout>
    );
}

