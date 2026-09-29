import React from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import { ScrollArea, ScrollBar } from '@/Components/ui/scroll-area';
import EmptyState from '@/Components/Admin/EmptyState';
import { formatRupiah } from '@/utils/format';
import { CheckCircle2, AlertCircle, Scale } from 'lucide-react';

export default function NeracaSaldo({ report, filters }) {
    const { rows, totals, balanced, meta } = report;
    const inputClass = 'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 focus:border-emerald-500 focus:outline-none';

    return (
        <AdminLayout title="Neraca Saldo" pageTitle="Akuntansi — Laporan Keuangan">
            <div className="mb-6">
                <h2 className="text-xl font-bold text-slate-900">Neraca Saldo</h2>
                <p className="mt-1 text-xs sm:text-sm text-slate-500">
                    Hanya jurnal POSTED yang dihitung. Jurnal REVERSED tetap terhitung bersama jurnal pembaliknya.
                </p>
            </div>

            <form method="get" action={route('akuntansi.laporan-keuangan.neraca-saldo')} className="mb-6 grid grid-cols-1 gap-4 rounded-xl border border-slate-200 bg-white p-6 shadow-xs sm:grid-cols-4">
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
                <strong>Batasan:</strong> {meta.limitations.join(' ')}
            </div>

            <div className={`mb-4 inline-flex items-center gap-2 rounded-lg border px-4 py-2 text-xs font-semibold ${balanced ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-rose-200 bg-rose-50 text-rose-700'}`}>
                {balanced ? <CheckCircle2 className="h-4 w-4" /> : <AlertCircle className="h-4 w-4" />}
                {balanced
                    ? 'Neraca saldo SEIMBANG (total debit = total kredit).'
                    : 'Neraca saldo TIDAK SEIMBANG — total debit ≠ total kredit. Laporan tidak valid.'}
            </div>

            {rows.length === 0 ? (
                <EmptyState
                    icon={Scale}
                    title="Belum Ada Data Jurnal"
                    description="Belum ada jurnal POSTED pada periode yang dipilih. Posting jurnal terlebih dahulu dari halaman Jurnal Umum."
                />
            ) : (
                <ScrollArea className="rounded-xl border border-slate-200 bg-white shadow-xs">
<ScrollBar orientation="horizontal" />
                    <table className="min-w-full divide-y divide-slate-200 text-xs sm:text-sm">
                        <thead className="bg-slate-50">
                            <tr>
                                <th className="px-4 py-3 text-left font-semibold text-slate-500">Kode</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-500">Nama Akun</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-500">Kategori</th>
                                <th className="px-4 py-3 text-right font-semibold text-slate-500">Mutasi Debit</th>
                                <th className="px-4 py-3 text-right font-semibold text-slate-500">Mutasi Kredit</th>
                                <th className="px-4 py-3 text-right font-semibold text-slate-500">Saldo Debit</th>
                                <th className="px-4 py-3 text-right font-semibold text-slate-500">Saldo Kredit</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {rows.map((row) => (
                                <tr key={row.id} className="hover:bg-slate-50">
                                    <td className="px-4 py-3 font-mono text-slate-900">{row.code}</td>
                                    <td className="px-4 py-3 text-slate-900">{row.name}</td>
                                    <td className="px-4 py-3 text-slate-600">{row.category_label}</td>
                                    <td className="px-4 py-3 text-right text-slate-900">{row.total_debit > 0 ? formatRupiah(row.total_debit) : '-'}</td>
                                    <td className="px-4 py-3 text-right text-slate-900">{row.total_credit > 0 ? formatRupiah(row.total_credit) : '-'}</td>
                                    <td className="px-4 py-3 text-right font-semibold text-slate-900">{row.balance_debit > 0 ? formatRupiah(row.balance_debit) : '-'}</td>
                                    <td className="px-4 py-3 text-right font-semibold text-slate-900">{row.balance_credit > 0 ? formatRupiah(row.balance_credit) : '-'}</td>
                                </tr>
                            ))}
                            <tr className="bg-slate-50 font-semibold">
                                <td colSpan={3} className="px-4 py-3 text-right text-slate-700">Total</td>
                                <td className="px-4 py-3 text-right text-slate-900">{formatRupiah(totals.debit)}</td>
                                <td className="px-4 py-3 text-right text-slate-900">{formatRupiah(totals.credit)}</td>
                                <td className="px-4 py-3 text-right text-slate-900">{formatRupiah(totals.balance_debit)}</td>
                                <td className="px-4 py-3 text-right text-slate-900">{formatRupiah(totals.balance_credit)}</td>
                            </tr>
                        </tbody>
                    </table>
                </ScrollArea>
            )}
        </AdminLayout>
    );
}
