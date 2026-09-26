import React from 'react';
import { Link, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Landmark, ChevronLeft, AlertCircle } from 'lucide-react';
import { formatRupiah } from '@/utils/format';

export default function Create({ members, today_date }) {
    const { data, setData, post, processing, errors } = useForm({
        member_id: '',
        amount: '',
        tenor_months: '12',
        submission_date: today_date,
        notes: '',
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        post(route('pinjaman.store'));
    };

    const parsedAmount = parseFloat(data.amount) || 0;

    return (
        <AdminLayout title="Pengajuan Pinjaman Baru" pageTitle="Pengajuan Pinjaman">
            <div className="max-w-2xl space-y-6">
                <Link
                    href={route('pinjaman.index')}
                    className="inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-slate-800 transition-colors"
                >
                    <ChevronLeft className="h-4 w-4" />
                    Kembali ke daftar pinjaman
                </Link>

                <div className="rounded-xl border border-slate-200 bg-white shadow-xs">
                    <div className="border-b border-slate-100 px-6 py-4">
                        <div className="flex items-center gap-3">
                            <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-emerald-100 text-emerald-700">
                                <Landmark className="h-5 w-5" />
                            </div>
                            <div>
                                <h2 className="font-semibold text-slate-900">Form Pengajuan Pinjaman</h2>
                                <p className="text-sm text-slate-500">Nomor pinjaman akan dibuat secara otomatis.</p>
                            </div>
                        </div>
                    </div>

                    <form onSubmit={handleSubmit} className="divide-y divide-slate-100">
                        <div className="space-y-5 px-6 py-5">
                            {/* Anggota */}
                            <div>
                                <label htmlFor="member_id" className="block text-sm font-medium text-slate-700">
                                    Anggota Pemohon <span className="text-rose-500">*</span>
                                </label>
                                {members.length === 0 ? (
                                    <div className="mt-1.5 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                                        Tidak ada anggota aktif. Daftarkan anggota terlebih dahulu.
                                    </div>
                                ) : (
                                    <select
                                        id="member_id"
                                        value={data.member_id}
                                        onChange={(e) => setData('member_id', e.target.value)}
                                        className={`mt-1.5 block w-full rounded-lg border px-3 py-2 text-sm transition-colors focus:outline-none focus:ring-1 ${
                                            errors.member_id
                                                ? 'border-rose-400 focus:border-rose-500 focus:ring-rose-500'
                                                : 'border-slate-300 focus:border-slate-500 focus:ring-slate-500'
                                        }`}
                                    >
                                        <option value="">Pilih anggota...</option>
                                        {members.map((m) => (
                                            <option key={m.id} value={m.id}>
                                                {m.full_name} ({m.member_number})
                                            </option>
                                        ))}
                                    </select>
                                )}
                                {errors.member_id && (
                                    <p className="mt-1 flex items-center gap-1 text-xs text-rose-600">
                                        <AlertCircle className="h-3 w-3" />
                                        {errors.member_id}
                                    </p>
                                )}
                            </div>

                            {/* Nominal Pokok */}
                            <div>
                                <label htmlFor="amount" className="block text-sm font-medium text-slate-700">
                                    Nominal Pokok Pinjaman <span className="text-rose-500">*</span>
                                </label>
                                <div className="mt-1.5 flex items-center">
                                    <span className="inline-flex items-center rounded-l-lg border border-r-0 border-slate-300 bg-slate-50 px-3 py-2 text-sm text-slate-500">
                                        Rp
                                    </span>
                                    <input
                                        id="amount"
                                        type="number"
                                        min="10000"
                                        step="10000"
                                        value={data.amount}
                                        onChange={(e) => setData('amount', e.target.value)}
                                        placeholder="0"
                                        className={`block w-full rounded-r-lg border px-3 py-2 text-sm tabular-nums transition-colors focus:outline-none focus:ring-1 ${
                                            errors.amount
                                                ? 'border-rose-400 focus:border-rose-500 focus:ring-rose-500'
                                                : 'border-slate-300 focus:border-slate-500 focus:ring-slate-500'
                                        }`}
                                    />
                                </div>
                                {parsedAmount > 0 && (
                                    <p className="mt-1 text-xs text-slate-500">
                                        {formatRupiah(parsedAmount)}
                                    </p>
                                )}
                                {errors.amount && (
                                    <p className="mt-1 flex items-center gap-1 text-xs text-rose-600">
                                        <AlertCircle className="h-3 w-3" />
                                        {errors.amount}
                                    </p>
                                )}
                            </div>

                            {/* Tenor */}
                            <div>
                                <label htmlFor="tenor_months" className="block text-sm font-medium text-slate-700">
                                    Tenor (Bulan) <span className="text-rose-500">*</span>
                                </label>
                                <select
                                    id="tenor_months"
                                    value={data.tenor_months}
                                    onChange={(e) => setData('tenor_months', e.target.value)}
                                    className={`mt-1.5 block w-full rounded-lg border px-3 py-2 text-sm transition-colors focus:outline-none focus:ring-1 ${
                                        errors.tenor_months
                                            ? 'border-rose-400 focus:border-rose-500 focus:ring-rose-500'
                                            : 'border-slate-300 focus:border-slate-500 focus:ring-slate-500'
                                    }`}
                                >
                                    <option value="3">3 Bulan</option>
                                    <option value="6">6 Bulan</option>
                                    <option value="12">12 Bulan (1 Tahun)</option>
                                    <option value="18">18 Bulan</option>
                                    <option value="24">24 Bulan (2 Tahun)</option>
                                    <option value="36">36 Bulan (3 Tahun)</option>
                                </select>
                                {errors.tenor_months && (
                                    <p className="mt-1 flex items-center gap-1 text-xs text-rose-600">
                                        <AlertCircle className="h-3 w-3" />
                                        {errors.tenor_months}
                                    </p>
                                )}
                            </div>

                            {/* Tanggal Pengajuan */}
                            <div>
                                <label htmlFor="submission_date" className="block text-sm font-medium text-slate-700">
                                    Tanggal Pengajuan <span className="text-rose-500">*</span>
                                </label>
                                <input
                                    id="submission_date"
                                    type="date"
                                    value={data.submission_date}
                                    onChange={(e) => setData('submission_date', e.target.value)}
                                    className={`mt-1.5 block w-full rounded-lg border px-3 py-2 text-sm transition-colors focus:outline-none focus:ring-1 ${
                                        errors.submission_date
                                            ? 'border-rose-400 focus:border-rose-500 focus:ring-rose-500'
                                            : 'border-slate-300 focus:border-slate-500 focus:ring-slate-500'
                                    }`}
                                />
                                {errors.submission_date && (
                                    <p className="mt-1 flex items-center gap-1 text-xs text-rose-600">
                                        <AlertCircle className="h-3 w-3" />
                                        {errors.submission_date}
                                    </p>
                                )}
                            </div>

                            {/* Catatan */}
                            <div>
                                <label htmlFor="notes" className="block text-sm font-medium text-slate-700">
                                    Catatan / Keterangan
                                    <span className="ml-1 text-xs font-normal text-slate-400">(opsional)</span>
                                </label>
                                <textarea
                                    id="notes"
                                    rows={3}
                                    value={data.notes}
                                    onChange={(e) => setData('notes', e.target.value)}
                                    placeholder="Tujuan penggunaan pinjaman atau catatan pendukung..."
                                    className="mt-1.5 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"
                                />
                            </div>
                        </div>

                        <div className="flex justify-end gap-3 px-6 py-4">
                            <Link
                                href={route('pinjaman.index')}
                                className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 transition-colors"
                            >
                                Batal
                            </Link>
                            <button
                                type="submit"
                                disabled={processing || members.length === 0}
                                className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50 active:scale-95 transition-all"
                            >
                                {processing ? 'Memproses...' : 'Simpan Pengajuan'}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </AdminLayout>
    );
}
