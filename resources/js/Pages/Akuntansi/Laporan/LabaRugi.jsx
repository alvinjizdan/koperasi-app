import React from 'react';
import { Link } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import EmptyState from '@/Components/Admin/EmptyState';
import { formatRupiah, formatDate } from '@/utils/format';
import { TrendingUp, TrendingDown } from 'lucide-react';

export default function LabaRugi({ report, filters }) {
    const { sections, summary, meta } = report;
    const inputClass = 'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 focus:border-emerald-500 focus:outline-none';
    const hasData = sections.revenue?.accounts.length > 0 || sections.expense?.accounts.length > 0;

    return (
        <AdminLayout title="Laba Rugi" pageTitle="Akuntansi — Laporan Keuangan">
            <div className="mb-6">
                <h2 className="text-xl font-bold text-slate-900">Laporan Laba Rugi</h2>
                <p className="mt-1 text-xs sm:text-sm text-slate-500">
                    Periode {filters.start_date ? formatDate(filters.start_date) : 'awal'} s.d. {filters.end_date ? formatDate(filters.end_date) : 'sekarang'} —
                    berdasarkan jurnal POSTED dalam periode.
                </p>
            </div>

            <form method="get" action={route('akuntansi.laporan-keuangan.laba-rugi')} className="mb-6 grid grid-cols-1 gap-4 rounded-xl border border-slate-200 bg-white p-6 shadow-xs sm:grid-cols-4">
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
                        Terapkan Filter
                    </button>
                </div>
            </form>

            <div className="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-800">
                <strong>Batasan:</strong> {meta.limitations.join(' ')} Surplus/defisit adalah hasil perhitungan periode, bukan saldo ekuitas.
            </div>

            {!hasData ? (
                <EmptyState
                    icon={TrendingUp}
                    title="Belum Ada Mutasi Pendapatan/Beban"
                    description="Belum ada jurnal POSTED dengan akun pendapatan atau beban pada periode ini."
                />
            ) : (
                <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    {Object.entries(sections).map(([key, section]) => (
                        <div key={key} className="rounded-xl border border-slate-200 bg-white p-6 shadow-xs">
                            <h3 className="text-sm font-semibold uppercase tracking-wider text-slate-500">{section.label}</h3>
                            <table className="mt-3 w-full text-xs sm:text-sm">
                                <tbody className="divide-y divide-slate-100">
                                    {section.accounts.length === 0 ? (
                                        <tr><td className="py-3 text-slate-400 italic">Tidak ada akun dengan mutasi.</td></tr>
                                    ) : section.accounts.map((account) => (
                                        <tr key={account.id}>
                                            <td className="py-2 font-mono text-slate-600">{account.code}</td>
                                            <td className="py-2 text-slate-900">{account.name}</td>
                                            <td className="py-2 text-right font-semibold text-slate-900">{formatRupiah(account.balance)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                                <tfoot>
                                    <tr className="border-t-2 border-slate-300 font-bold">
                                        <td colSpan={2} className="py-2 text-right text-slate-700">Total {section.label}</td>
                                        <td className="py-2 text-right text-slate-900">{formatRupiah(section.total)}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    ))}
                </div>
            )}

            <div className="mt-6 rounded-xl border border-slate-200 bg-white p-6 shadow-xs">
                <h3 className="text-sm font-semibold uppercase tracking-wider text-slate-500">Ringkasan Periode</h3>
                <dl className="mt-3 grid grid-cols-1 gap-4 text-xs sm:grid-cols-3">
                    <div><dt className="text-slate-400">Total Pendapatan</dt><dd className="text-lg font-bold text-slate-900">{formatRupiah(summary.total_revenue)}</dd></div>
                    <div><dt className="text-slate-400">Total Beban</dt><dd className="text-lg font-bold text-slate-900">{formatRupiah(summary.total_expense)}</dd></div>
                    <div>
                        <dt className="text-slate-400">{summary.surplus_deficit >= 0 ? 'Surplus' : 'Defisit'}</dt>
                        <dd className={`flex items-center gap-1.5 text-lg font-bold ${summary.surplus_deficit >= 0 ? 'text-emerald-700' : 'text-rose-600'}`}>
                            {summary.surplus_deficit >= 0 ? <TrendingUp className="h-5 w-5" /> : <TrendingDown className="h-5 w-5" />}
                            {formatRupiah(summary.surplus_deficit)}
                        </dd>
                    </div>
                </dl>
                <p className="mt-3 text-xs text-slate-500">
                    Angka konsisten dengan <Link href={route('akuntansi.buku-besar.index')} className="font-semibold text-emerald-700 hover:underline">Buku Besar</Link>; dapat ditelusuri ke jurnal sumber.
                </p>
            </div>
        </AdminLayout>
    );
}
