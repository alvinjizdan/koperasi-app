import React, { useState } from 'react';
import { Link, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import ConfirmModal from '@/Components/Admin/ConfirmModal';
import { ChevronLeft, Lock, AlertCircle, CheckCircle2, Scale } from 'lucide-react';
import { formatRupiah, formatDate } from '@/utils/format';

export default function Close({ session, totals, transactions_count }) {
    const [showConfirm, setShowConfirm] = useState(false);

    const { data, setData, post, processing, errors } = useForm({
        physical_closing_balance: '',
        closing_notes: '',
    });

    const systemBalance = parseFloat(totals.closing_balance) || 0;
    const hasPhysicalInput = data.physical_closing_balance !== '';
    const physicalBalance = parseFloat(data.physical_closing_balance) || 0;
    const difference = hasPhysicalInput ? Math.round((physicalBalance - systemBalance) * 100) / 100 : 0;
    const hasDifference = hasPhysicalInput && difference !== 0;
    const requiresNotes = hasDifference && !data.closing_notes.trim();
    const canSubmit = hasPhysicalInput && physicalBalance >= 0 && !requiresNotes;

    const handleSubmit = (e) => {
        e.preventDefault();
        if (!canSubmit) return;
        setShowConfirm(true);
    };

    const handleConfirm = () => {
        post(route('kasir.sesi.close.store', session.id), {
            onError: () => setShowConfirm(false),
        });
    };

    return (
        <AdminLayout title="Penutupan Kasir" pageTitle="Penutupan Kasir">
            <div className="max-w-2xl space-y-6">
                <Link
                    href={route('kasir.sesi.show', session.id)}
                    className="inline-flex items-center gap-1.5 text-sm text-slate-500 transition-colors hover:text-slate-800"
                >
                    <ChevronLeft className="h-4 w-4" />
                    Kembali ke detail sesi
                </Link>

                <div className="rounded-xl border border-slate-200 bg-white shadow-xs">
                    <div className="border-b border-slate-100 px-5 py-4">
                        <p className="text-xs text-slate-500">Sesi Kasir</p>
                        <p className="font-mono text-sm font-semibold text-slate-900">{session.session_number}</p>
                        <p className="text-xs text-slate-500">
                            {formatDate(session.operational_date)} • {session.operator?.name || '-'} •{' '}
                            {transactions_count} transaksi tercatat
                        </p>
                    </div>

                    <dl className="divide-y divide-slate-100">
                        <div className="flex items-center justify-between px-5 py-3">
                            <dt className="text-sm text-slate-600">Saldo Awal</dt>
                            <dd className="text-sm tabular-nums text-slate-900">
                                {formatRupiah(totals.opening_balance)}
                            </dd>
                        </div>
                        <div className="flex items-center justify-between px-5 py-3">
                            <dt className="text-sm text-slate-600">Total Penerimaan</dt>
                            <dd className="text-sm tabular-nums text-emerald-700">
                                +{formatRupiah(totals.income)}
                            </dd>
                        </div>
                        <div className="flex items-center justify-between px-5 py-3">
                            <dt className="text-sm text-slate-600">Total Pengeluaran</dt>
                            <dd className="text-sm tabular-nums text-rose-700">-{formatRupiah(totals.expense)}</dd>
                        </div>
                        <div className="flex items-center justify-between px-5 py-3">
                            <dt className="text-sm text-slate-600">Total Setoran Kasir</dt>
                            <dd className="text-sm tabular-nums text-blue-700">-{formatRupiah(totals.deposit)}</dd>
                        </div>
                        <div className="flex items-center justify-between bg-slate-50 px-5 py-3">
                            <dt className="inline-flex items-center gap-1.5 text-sm font-semibold text-slate-800">
                                <Scale className="h-4 w-4" />
                                Saldo Akhir Sistem
                            </dt>
                            <dd className="text-base font-bold tabular-nums text-slate-900">
                                {formatRupiah(systemBalance)}
                            </dd>
                        </div>
                    </dl>
                </div>

                <div className="rounded-xl border border-slate-200 bg-white shadow-xs">
                    <div className="border-b border-slate-100 px-6 py-4">
                        <div className="flex items-center gap-3">
                            <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-slate-200 text-slate-700">
                                <Lock className="h-5 w-5" />
                            </div>
                            <div>
                                <h2 className="font-semibold text-slate-900">Penghitungan Saldo Fisik</h2>
                                <p className="text-sm text-slate-500">
                                    Masukkan jumlah uang tunai hasil penghitungan fisik di kasir.
                                </p>
                            </div>
                        </div>
                    </div>

                    <form onSubmit={handleSubmit} className="divide-y divide-slate-100">
                        <div className="space-y-5 px-6 py-5">
                            <div>
                                <label
                                    htmlFor="physical_closing_balance"
                                    className="block text-sm font-medium text-slate-700"
                                >
                                    Saldo Fisik Hasil Penghitungan <span className="text-rose-500">*</span>
                                </label>
                                <div className="mt-1.5 flex items-center">
                                    <span className="inline-flex items-center rounded-l-lg border border-r-0 border-slate-300 bg-slate-50 px-3 py-2 text-sm text-slate-500">
                                        Rp
                                    </span>
                                    <input
                                        id="physical_closing_balance"
                                        type="number"
                                        min="0"
                                        step="1"
                                        value={data.physical_closing_balance}
                                        onChange={(e) => setData('physical_closing_balance', e.target.value)}
                                        placeholder="0"
                                        className={`block w-full rounded-r-lg border px-3 py-2 text-sm tabular-nums transition-colors focus:outline-none focus:ring-1 ${
                                            errors.physical_closing_balance || requiresNotes
                                                ? 'border-rose-400 focus:border-rose-500 focus:ring-rose-500'
                                                : 'border-slate-300 focus:border-slate-500 focus:ring-slate-500'
                                        }`}
                                    />
                                </div>
                                {errors.physical_closing_balance && (
                                    <p className="mt-1 flex items-center gap-1 text-xs text-rose-600">
                                        <AlertCircle className="h-3 w-3" />
                                        {errors.physical_closing_balance}
                                    </p>
                                )}
                            </div>

                            {hasPhysicalInput && (
                                <div
                                    className={`rounded-lg border px-4 py-3 ${
                                        hasDifference
                                            ? 'border-rose-200 bg-rose-50'
                                            : 'border-emerald-200 bg-emerald-50'
                                    }`}
                                >
                                    <div className="flex items-center justify-between text-sm">
                                        <span className="inline-flex items-center gap-1.5 font-medium text-slate-700">
                                            {hasDifference ? (
                                                <AlertCircle className="h-4 w-4 text-rose-600" />
                                            ) : (
                                                <CheckCircle2 className="h-4 w-4 text-emerald-600" />
                                            )}
                                            Selisih Kas (Fisik — Sistem)
                                        </span>
                                        <span
                                            className={`font-bold tabular-nums ${
                                                hasDifference ? 'text-rose-700' : 'text-emerald-700'
                                            }`}
                                        >
                                            {formatRupiah(difference)}
                                        </span>
                                    </div>
                                    <p className="mt-1 text-[11px] text-slate-500">
                                        Saldo fisik {formatRupiah(physicalBalance)} dibandingkan saldo sistem{' '}
                                        {formatRupiah(systemBalance)}.
                                    </p>
                                </div>
                            )}

                            <div>
                                <label htmlFor="closing_notes" className="block text-sm font-medium text-slate-700">
                                    Catatan Penutupan
                                    {hasDifference ? (
                                        <span className="ml-1 text-rose-500">*</span>
                                    ) : (
                                        <span className="ml-1 text-xs font-normal text-slate-400">(opsional)</span>
                                    )}
                                </label>
                                <textarea
                                    id="closing_notes"
                                    rows={3}
                                    value={data.closing_notes}
                                    onChange={(e) => setData('closing_notes', e.target.value)}
                                    placeholder={
                                        hasDifference
                                            ? 'Jelaskan penyebab selisih kas...'
                                            : 'Catatan penutupan kas...'
                                    }
                                    className={`mt-1.5 block w-full rounded-lg border px-3 py-2 text-sm transition-colors focus:outline-none focus:ring-1 ${
                                        errors.closing_notes || requiresNotes
                                            ? 'border-rose-400 focus:border-rose-500 focus:ring-rose-500'
                                            : 'border-slate-300 focus:border-slate-500 focus:ring-slate-500'
                                    }`}
                                />
                                {requiresNotes && (
                                    <p className="mt-1 flex items-center gap-1 text-xs text-rose-600">
                                        <AlertCircle className="h-3 w-3" />
                                        Catatan penjelasan wajib diisi karena terdapat selisih kas.
                                    </p>
                                )}
                                {errors.closing_notes && (
                                    <p className="mt-1 flex items-center gap-1 text-xs text-rose-600">
                                        <AlertCircle className="h-3 w-3" />
                                        {errors.closing_notes}
                                    </p>
                                )}
                            </div>

                            <p className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-[11px] leading-relaxed text-slate-500">
                                Setelah ditutup, sesi tidak dapat menerima transaksi baru. Saldo transaksi sebelumnya
                                tidak akan diubah untuk menghilangkan selisih.
                            </p>
                        </div>

                        <div className="flex justify-end gap-3 px-6 py-4">
                            <Link
                                href={route('kasir.sesi.show', session.id)}
                                className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition-colors hover:bg-slate-50"
                            >
                                Batal
                            </Link>
                            <button
                                type="submit"
                                disabled={processing || !canSubmit}
                                className="rounded-lg bg-slate-800 px-4 py-2 text-sm font-medium text-white transition-all hover:bg-slate-900 active:scale-95 disabled:opacity-50"
                            >
                                Tutup Sesi Kasir
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
                title="Konfirmasi Penutupan Kasir"
                message={`Sesi ${session.session_number} akan ditutup dengan saldo fisik ${formatRupiah(physicalBalance)} dan selisih kas ${formatRupiah(difference)}. Tindakan ini tidak dapat dibatalkan.`}
                confirmText="Tutup Sesi"
            />
        </AdminLayout>
    );
}
