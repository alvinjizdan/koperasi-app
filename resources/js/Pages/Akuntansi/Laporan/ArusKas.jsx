import React from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import { formatRupiah, formatDate } from '@/utils/format';
import { AlertCircle, CheckCircle2, CircleDashed } from 'lucide-react';

export default function ArusKas({ report, filters }) {
    const { available, operational_summary: operational, prerequisites, meta } = report;
    const inputClass = 'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 focus:border-emerald-500 focus:outline-none';

    return (
        <AdminLayout title="Arus Kas" pageTitle="Akuntansi — Laporan Keuangan">
            <div className="mb-6">
                <h2 className="text-xl font-bold text-slate-900">Laporan Arus Kas</h2>
                <p className="mt-1 text-xs sm:text-sm text-slate-500">
                    Status ketersediaan dan data kas terverifikasi untuk periode {filters.start_date ? formatDate(filters.start_date) : 'awal'} s.d. {filters.end_date ? formatDate(filters.end_date) : 'sekarang'}.
                </p>
            </div>

            <form method="get" action={route('akuntansi.laporan-keuangan.arus-kas')} className="mb-6 grid grid-cols-1 gap-4 rounded-xl border border-slate-200 bg-white p-6 shadow-xs sm:grid-cols-4">
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

            <div className="mb-6 rounded-xl border border-rose-200 bg-rose-50 p-6">
                <div className="flex items-start gap-3">
                    <AlertCircle className="mt-0.5 h-5 w-5 shrink-0 text-rose-600" />
                    <div>
                        <h3 className="text-sm font-semibold text-rose-800">Laporan Arus Kas Akuntansi Belum Tersedia</h3>
                        <p className="mt-1 text-xs text-rose-700">
                            Klasifikasi aktivitas operasi, investasi, dan pendanaan belum memiliki dasar yang terdokumentasi,
                            dan transaksi kasir belum diposting ke jurnal (auto-posting belum aktif). Laporan arus kas final
                            TIDAK dihasilkan agar tidak menampilkan angka yang menyesatkan.
                        </p>
                    </div>
                </div>
            </div>

            <div className="mb-6 rounded-xl border border-slate-200 bg-white p-6 shadow-xs">
                <h3 className="text-sm font-semibold uppercase tracking-wider text-slate-500">Ringkasan Kas Operasional (bukan arus kas akuntansi)</h3>
                <dl className="mt-3 grid grid-cols-1 gap-4 text-xs sm:grid-cols-4">
                    <div><dt className="text-slate-400">Penerimaan Kas</dt><dd className="text-lg font-bold text-slate-900">{formatRupiah(operational.total_income)}</dd></div>
                    <div><dt className="text-slate-400">Pengeluaran Kas</dt><dd className="text-lg font-bold text-slate-900">{formatRupiah(operational.total_expense)}</dd></div>
                    <div><dt className="text-slate-400">Setoran Kasir (internal)</dt><dd className="text-lg font-bold text-slate-900">{formatRupiah(operational.total_deposit)}</dd></div>
                    <div><dt className="text-slate-400">Kas Bersih</dt><dd className="text-lg font-bold text-slate-900">{formatRupiah(operational.net_cash)}</dd></div>
                </dl>
                <p className="mt-3 text-xs text-slate-500">{operational.note}</p>
            </div>

            <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-xs">
                <h3 className="text-sm font-semibold uppercase tracking-wider text-slate-500">Prasyarat Implementasi Arus Kas Lengkap</h3>
                <ul className="mt-3 space-y-2 text-xs sm:text-sm">
                    {prerequisites.map((prerequisite, index) => (
                        <li key={index} className="flex items-start gap-2 text-slate-700">
                            <CircleDashed className="mt-0.5 h-4 w-4 shrink-0 text-slate-400" />
                            {prerequisite}
                        </li>
                    ))}
                </ul>
                <div className={`mt-4 inline-flex items-center gap-2 rounded-lg border px-4 py-2 text-xs font-semibold ${report.business_journals_posted ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-slate-200 bg-slate-50 text-slate-600'}`}>
                    {report.business_journals_posted ? <CheckCircle2 className="h-4 w-4" /> : <AlertCircle className="h-4 w-4" />}
                    {report.business_journals_posted
                        ? 'Terdapat jurnal POSTED (input manual).'
                        : 'Belum ada jurnal POSTED sama sekali.'}
                </div>
            </div>
        </AdminLayout>
    );
}
