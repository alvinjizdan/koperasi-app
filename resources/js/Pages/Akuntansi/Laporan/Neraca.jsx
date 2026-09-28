import React from 'react';
import { Link } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { formatRupiah, formatDate } from '@/utils/format';
import { CheckCircle2, AlertCircle } from 'lucide-react';

export default function Neraca({ report, filters }) {
    const { sections, summary, balanced, meta } = report;
    const inputClass = 'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 focus:border-emerald-500 focus:outline-none';

    return (
        <AdminLayout title="Neraca" pageTitle="Akuntansi — Laporan Keuangan">
            <div className="mb-6">
                <h2 className="text-xl font-bold text-slate-900">Neraca</h2>
                <p className="mt-1 text-xs sm:text-sm text-slate-500">
                    Posisi per <strong>{formatDate(filters.as_of_date)}</strong> — berdasarkan jurnal POSTED s.d. tanggal tersebut.
                </p>
            </div>

            <form method="get" action={route('akuntansi.laporan-keuangan.neraca')} className="mb-6 grid grid-cols-1 gap-4 rounded-xl border border-slate-200 bg-white p-6 shadow-xs sm:grid-cols-4">
                <div>
                    <label className="text-xs font-semibold text-slate-600" htmlFor="as_of_date">Posisi Per Tanggal</label>
                    <input id="as_of_date" name="as_of_date" type="date" defaultValue={filters.as_of_date ?? ''} className={inputClass} />
                </div>
                <div className="sm:col-span-3 flex items-end">
                    <button type="submit" className="w-full rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                        Terapkan
                    </button>
                </div>
            </form>

            <div className="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-800">
                <strong>Batasan:</strong> {meta.limitations.join(' ')}
            </div>

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
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

            <div className="mt-6 rounded-xl border border-slate-200 bg-white p-6 shadow-xs">
                <h3 className="text-sm font-semibold uppercase tracking-wider text-slate-500">Ringkasan</h3>
                <dl className="mt-3 grid grid-cols-1 gap-4 text-xs sm:grid-cols-4">
                    <div><dt className="text-slate-400">Total Aset</dt><dd className="text-lg font-bold text-slate-900">{formatRupiah(summary.total_assets)}</dd></div>
                    <div><dt className="text-slate-400">Total Liabilitas</dt><dd className="text-lg font-bold text-slate-900">{formatRupiah(summary.total_liabilities)}</dd></div>
                    <div><dt className="text-slate-400">Total Ekuitas</dt><dd className="text-lg font-bold text-slate-900">{formatRupiah(summary.total_equity)}</dd></div>
                    <div><dt className="text-slate-400">Selisih Neraca</dt><dd className={`text-lg font-bold ${balanced ? 'text-emerald-700' : 'text-rose-600'}`}>{formatRupiah(summary.difference)}</dd></div>
                </dl>

                <div className={`mt-4 inline-flex items-start gap-2 rounded-lg border px-4 py-2 text-xs font-semibold ${balanced ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-rose-200 bg-rose-50 text-rose-700'}`}>
                    {balanced ? <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0" /> : <AlertCircle className="mt-0.5 h-4 w-4 shrink-0" />}
                    <span>
                        {balanced
                            ? 'Neraca seimbang secara aritmetika (Aset = Liabilitas + Ekuitas).'
                            : `Neraca TIDAK seimbang — selisih ${formatRupiah(summary.difference)}.`}
                        {' '}Surplus/defisit periode berjalan ({formatRupiah(summary.current_period_result)}) belum diposting ke ekuitas
                        (penutupan buku belum diimplementasikan) — ikut memengaruhi keseimbangan aritmetika.
                    </span>
                </div>

                <p className="mt-3 text-xs text-slate-500">
                    Neraca yang seimbang belum tentu mencerminkan posisi keuangan lengkap koperasi:
                    transaksi bisnis belum dipetakan ke jurnal (auto-posting belum aktif) dan saldo awal belum tersedia.
                    Lihat <Link href={route('akuntansi.laporan-keuangan.laba-rugi')} className="font-semibold text-emerald-700 hover:underline">Laba Rugi</Link> dan{' '}
                    <Link href={route('akuntansi.buku-besar.index')} className="font-semibold text-emerald-700 hover:underline">Buku Besar</Link> untuk penelusuran.
                </p>
            </div>
        </AdminLayout>
    );
}
