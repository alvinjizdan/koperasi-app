import React from 'react';
import { Link, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { PiggyBank, ChevronLeft, AlertCircle } from 'lucide-react';

export default function Create({ members, products, today_date }) {
    const { data, setData, post, processing, errors } = useForm({
        member_id: '',
        saving_product_id: '',
        opened_at: today_date,
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        post(route('simpanan-rekening.store'));
    };

    return (
        <AdminLayout title="Buka Rekening Simpanan" pageTitle="Buka Rekening Simpanan">
            <div className="max-w-2xl space-y-6">
                <Link
                    href={route('simpanan-rekening.index')}
                    className="inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-slate-800 transition-colors"
                >
                    <ChevronLeft className="h-4 w-4" />
                    Kembali ke daftar rekening
                </Link>

                <div className="rounded-xl border border-slate-200 bg-white shadow-xs">
                    <div className="border-b border-slate-100 px-6 py-4">
                        <div className="flex items-center gap-3">
                            <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-emerald-100 text-emerald-700">
                                <PiggyBank className="h-5 w-5" />
                            </div>
                            <div>
                                <h2 className="font-semibold text-slate-900">Pembukaan Rekening Simpanan</h2>
                                <p className="text-sm text-slate-500">Nomor rekening akan dibuat secara otomatis setelah disimpan.</p>
                            </div>
                        </div>
                    </div>

                    <form onSubmit={handleSubmit} className="divide-y divide-slate-100">
                        <div className="space-y-5 px-6 py-5">
                            {/* Anggota */}
                            <div>
                                <label htmlFor="member_id" className="block text-sm font-medium text-slate-700">
                                    Anggota <span className="text-rose-500">*</span>
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

                            {/* Produk Simpanan */}
                            <div>
                                <label htmlFor="saving_product_id" className="block text-sm font-medium text-slate-700">
                                    Produk Simpanan <span className="text-rose-500">*</span>
                                </label>
                                {products.length === 0 ? (
                                    <div className="mt-1.5 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                                        Tidak ada produk simpanan aktif.{' '}
                                        <Link href={route('simpanan-produk.create')} className="underline">
                                            Tambah produk
                                        </Link>
                                        {' '}terlebih dahulu.
                                    </div>
                                ) : (
                                    <select
                                        id="saving_product_id"
                                        value={data.saving_product_id}
                                        onChange={(e) => setData('saving_product_id', e.target.value)}
                                        className={`mt-1.5 block w-full rounded-lg border px-3 py-2 text-sm transition-colors focus:outline-none focus:ring-1 ${
                                            errors.saving_product_id
                                                ? 'border-rose-400 focus:border-rose-500 focus:ring-rose-500'
                                                : 'border-slate-300 focus:border-slate-500 focus:ring-slate-500'
                                        }`}
                                    >
                                        <option value="">Pilih produk simpanan...</option>
                                        {products.map((p) => (
                                            <option key={p.id} value={p.id}>
                                                {p.name} ({p.code})
                                            </option>
                                        ))}
                                    </select>
                                )}
                                {errors.saving_product_id && (
                                    <p className="mt-1 flex items-center gap-1 text-xs text-rose-600">
                                        <AlertCircle className="h-3 w-3" />
                                        {errors.saving_product_id}
                                    </p>
                                )}
                            </div>

                            {/* Tanggal Pembukaan */}
                            <div>
                                <label htmlFor="opened_at" className="block text-sm font-medium text-slate-700">
                                    Tanggal Pembukaan <span className="text-rose-500">*</span>
                                </label>
                                <input
                                    id="opened_at"
                                    type="date"
                                    value={data.opened_at}
                                    onChange={(e) => setData('opened_at', e.target.value)}
                                    className={`mt-1.5 block w-full rounded-lg border px-3 py-2 text-sm transition-colors focus:outline-none focus:ring-1 ${
                                        errors.opened_at
                                            ? 'border-rose-400 focus:border-rose-500 focus:ring-rose-500'
                                            : 'border-slate-300 focus:border-slate-500 focus:ring-slate-500'
                                    }`}
                                />
                                {errors.opened_at && (
                                    <p className="mt-1 flex items-center gap-1 text-xs text-rose-600">
                                        <AlertCircle className="h-3 w-3" />
                                        {errors.opened_at}
                                    </p>
                                )}
                            </div>
                        </div>

                        <div className="flex justify-end gap-3 px-6 py-4">
                            <Link
                                href={route('simpanan-rekening.index')}
                                className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 transition-colors"
                            >
                                Batal
                            </Link>
                            <button
                                type="submit"
                                disabled={processing || members.length === 0 || products.length === 0}
                                className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50 active:scale-95 transition-all"
                            >
                                {processing ? 'Memproses...' : 'Buka Rekening'}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </AdminLayout>
    );
}
