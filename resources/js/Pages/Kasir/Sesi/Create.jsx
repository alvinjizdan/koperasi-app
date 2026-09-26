import React, { useState } from 'react';
import { Link, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import ConfirmModal from '@/Components/Admin/ConfirmModal';
import { ChevronLeft, KeyRound, AlertCircle, Info } from 'lucide-react';
import { formatRupiah, formatDate, formatDateTime } from '@/utils/format';

export default function Create({ today_date, previous_session }) {
    const [showConfirm, setShowConfirm] = useState(false);

    const { data, setData, post, processing, errors } = useForm({
        operational_date: today_date,
        opening_balance: '',
        opening_notes: '',
    });

    const parsedBalance = parseFloat(data.opening_balance) || 0;
    const isValidBalance = data.opening_balance !== '' && parsedBalance >= 0;

    const handleSubmit = (e) => {
        e.preventDefault();
        if (!isValidBalance) return;
        setShowConfirm(true);
    };

    const handleConfirm = () => {
        post(route('kasir.sesi.store'), {
            onError: () => setShowConfirm(false),
        });
    };

    return (
        <AdminLayout title="Buka Sesi Kasir" pageTitle="Buka Sesi Kasir">
            <div className="max-w-2xl space-y-6">
                <Link
                    href={route('kasir.index')}
                    className="inline-flex items-center gap-1.5 text-sm text-slate-500 transition-colors hover:text-slate-800"
                >
                    <ChevronLeft className="h-4 w-4" />
                    Kembali ke dashboard kasir
                </Link>

                {previous_session && (
                    <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-xs">
                        <div className="flex items-center gap-2">
                            <Info className="h-4 w-4 text-slate-400" />
                            <p className="text-xs font-semibold uppercase tracking-wider text-slate-500">
                                Referensi Sesi Sebelumnya
                            </p>
                        </div>
                        <dl className="mt-3 grid grid-cols-2 gap-3 text-sm">
                            <div>
                                <dt className="text-xs text-slate-500">Nomor Sesi</dt>
                                <dd className="font-mono text-xs font-medium text-slate-900">
                                    {previous_session.session_number}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-xs text-slate-500">Tanggal Operasional</dt>
                                <dd className="text-slate-900">{formatDate(previous_session.operational_date)}</dd>
                            </div>
                            <div>
                                <dt className="text-xs text-slate-500">Saldo Fisik Akhir</dt>
                                <dd className="tabular-nums font-semibold text-slate-900">
                                    {formatRupiah(previous_session.physical_closing_balance)}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-xs text-slate-500">Selisih Kas</dt>
                                <dd
                                    className={`tabular-nums ${
                                        previous_session.difference === 0 ? 'text-slate-900' : 'text-rose-700'
                                    }`}
                                >
                                    {formatRupiah(previous_session.difference)}
                                </dd>
                            </div>
                        </dl>
                        <p className="mt-3 border-t border-slate-100 pt-3 text-[11px] leading-relaxed text-slate-400">
                            Ditutup {formatDateTime(previous_session.closed_at)}. Nilai ini hanya referensi;
                            mekanisme pergantian hari belum dikonfirmasi sehingga saldo awal tidak diisi otomatis.
                        </p>
                    </div>
                )}

                <div className="rounded-xl border border-slate-200 bg-white shadow-xs">
                    <div className="border-b border-slate-100 px-6 py-4">
                        <div className="flex items-center gap-3">
                            <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-emerald-100 text-emerald-700">
                                <KeyRound className="h-5 w-5" />
                            </div>
                            <div>
                                <h2 className="font-semibold text-slate-900">Pembukaan Sesi Kasir</h2>
                                <p className="text-sm text-slate-500">
                                    Tentukan tanggal operasional dan saldo awal kas fisik.
                                </p>
                            </div>
                        </div>
                    </div>

                    <form onSubmit={handleSubmit} className="divide-y divide-slate-100">
                        <div className="space-y-5 px-6 py-5">
                            <div>
                                <label htmlFor="operational_date" className="block text-sm font-medium text-slate-700">
                                    Tanggal Operasional <span className="text-rose-500">*</span>
                                </label>
                                <input
                                    id="operational_date"
                                    type="date"
                                    value={data.operational_date}
                                    onChange={(e) => setData('operational_date', e.target.value)}
                                    className={`mt-1.5 block w-full rounded-lg border px-3 py-2 text-sm transition-colors focus:outline-none focus:ring-1 ${
                                        errors.operational_date
                                            ? 'border-rose-400 focus:border-rose-500 focus:ring-rose-500'
                                            : 'border-slate-300 focus:border-slate-500 focus:ring-slate-500'
                                    }`}
                                />
                                {errors.operational_date && (
                                    <p className="mt-1 flex items-center gap-1 text-xs text-rose-600">
                                        <AlertCircle className="h-3 w-3" />
                                        {errors.operational_date}
                                    </p>
                                )}
                            </div>

                            <div>
                                <label htmlFor="opening_balance" className="block text-sm font-medium text-slate-700">
                                    Saldo Awal Kas <span className="text-rose-500">*</span>
                                </label>
                                <div className="mt-1.5 flex items-center">
                                    <span className="inline-flex items-center rounded-l-lg border border-r-0 border-slate-300 bg-slate-50 px-3 py-2 text-sm text-slate-500">
                                        Rp
                                    </span>
                                    <input
                                        id="opening_balance"
                                        type="number"
                                        min="0"
                                        step="1"
                                        value={data.opening_balance}
                                        onChange={(e) => setData('opening_balance', e.target.value)}
                                        placeholder="0"
                                        className={`block w-full rounded-r-lg border px-3 py-2 text-sm tabular-nums transition-colors focus:outline-none focus:ring-1 ${
                                            errors.opening_balance
                                                ? 'border-rose-400 focus:border-rose-500 focus:ring-rose-500'
                                                : 'border-slate-300 focus:border-slate-500 focus:ring-slate-500'
                                        }`}
                                    />
                                </div>
                                {isValidBalance && (
                                    <p className="mt-1 text-xs text-slate-500">{formatRupiah(parsedBalance)}</p>
                                )}
                                {errors.opening_balance && (
                                    <p className="mt-1 flex items-center gap-1 text-xs text-rose-600">
                                        <AlertCircle className="h-3 w-3" />
                                        {errors.opening_balance}
                                    </p>
                                )}
                            </div>

                            <div>
                                <label htmlFor="opening_notes" className="block text-sm font-medium text-slate-700">
                                    Catatan
                                    <span className="ml-1 text-xs font-normal text-slate-400">(opsional)</span>
                                </label>
                                <input
                                    id="opening_notes"
                                    type="text"
                                    value={data.opening_notes}
                                    onChange={(e) => setData('opening_notes', e.target.value)}
                                    placeholder="Keterangan pembukaan kas..."
                                    className="mt-1.5 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"
                                />
                            </div>

                            <p className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-[11px] leading-relaxed text-slate-500">
                                Satu operator hanya dapat memiliki satu sesi kasir terbuka. Pastikan tidak ada sesi
                                terbuka lain sebelum melanjutkan.
                            </p>
                        </div>

                        <div className="flex justify-end gap-3 px-6 py-4">
                            <Link
                                href={route('kasir.index')}
                                className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition-colors hover:bg-slate-50"
                            >
                                Batal
                            </Link>
                            <button
                                type="submit"
                                disabled={processing || !isValidBalance}
                                className="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-medium text-white transition-all hover:bg-emerald-800 active:scale-95 disabled:opacity-50"
                            >
                                Buka Sesi Kasir
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <ConfirmModal
                isOpen={showConfirm}
                onClose={() => setShowConfirm(false)}
                onConfirm={handleConfirm}
                isProcessing={processing}
                title="Konfirmasi Pembukaan Sesi Kasir"
                message={`Sesi kasir untuk tanggal ${formatDate(data.operational_date)} akan dibuka dengan saldo awal ${formatRupiah(parsedBalance)}. Periksa kembali sebelum melanjutkan.`}
                confirmText="Buka Sesi"
            />
        </AdminLayout>
    );
}
