import React, { useState } from 'react';
import { Link, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import ConfirmModal from '@/Components/Admin/ConfirmModal';
import { ArrowUpCircle, ChevronLeft, AlertCircle } from 'lucide-react';
import { formatRupiah } from '@/utils/format';

export default function Disburse({ loan, today_date, default_first_due_date }) {
    const [showConfirm, setShowConfirm] = useState(false);

    const { data, setData, post, processing, errors } = useForm({
        disbursement_date: today_date,
        first_due_date: default_first_due_date,
        method: 'CASH',
        notes: '',
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        setShowConfirm(true);
    };

    const handleConfirm = () => {
        post(route('pinjaman.disburse.store', loan.id), {
            onError: () => setShowConfirm(false),
        });
    };

    return (
        <AdminLayout title="Pencairan Pinjaman" pageTitle="Pencairan Pinjaman">
            <div className="max-w-2xl space-y-6">
                <Link
                    href={route('pinjaman.show', loan.id)}
                    className="inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-slate-800 transition-colors"
                >
                    <ChevronLeft className="h-4 w-4" />
                    Kembali ke detail pinjaman
                </Link>

                {/* Loan Info Card */}
                <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-xs">
                    <div className="flex items-center justify-between gap-4">
                        <div>
                            <p className="text-xs text-slate-500">No. Pinjaman</p>
                            <p className="font-mono text-sm font-semibold text-slate-900">{loan.loan_number}</p>
                            <p className="text-xs text-slate-500">{loan.member?.full_name}</p>
                        </div>
                        <div className="text-right">
                            <p className="text-xs text-slate-500">Nominal Disetujui</p>
                            <p className="text-lg font-bold tabular-nums text-slate-900">{formatRupiah(loan.amount)}</p>
                            <p className="text-xs text-slate-500">Tenor: {loan.tenor_months} Bulan</p>
                        </div>
                    </div>
                </div>

                <div className="rounded-xl border border-slate-200 bg-white shadow-xs">
                    <div className="border-b border-slate-100 px-6 py-4">
                        <div className="flex items-center gap-3">
                            <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-blue-100 text-blue-700">
                                <ArrowUpCircle className="h-5 w-5" />
                            </div>
                            <div>
                                <h2 className="font-semibold text-slate-900">Form Pencairan Dana</h2>
                                <p className="text-sm text-slate-500">
                                    Pencairan akan mengaktifkan pinjaman dan membuat jadwal angsuran.
                                </p>
                            </div>
                        </div>
                    </div>

                    <form onSubmit={handleSubmit} className="divide-y divide-slate-100">
                        <div className="space-y-5 px-6 py-5">
                            {/* Kesalahan lintas modul (misalnya saldo kas kasir tidak mencukupi) */}
                            {Object.keys(errors).length > 0 && (
                                <div className="flex items-start gap-2 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-800">
                                    <AlertCircle className="mt-0.5 h-3.5 w-3.5 flex-shrink-0" />
                                    <div>
                                        {Object.values(errors).map((message) => (
                                            <p key={message}>{message}</p>
                                        ))}
                                    </div>
                                </div>
                            )}

                            {/* Tanggal Pencairan */}
                            <div>
                                <label htmlFor="disbursement_date" className="block text-sm font-medium text-slate-700">
                                    Tanggal Pencairan <span className="text-rose-500">*</span>
                                </label>
                                <input
                                    id="disbursement_date"
                                    type="date"
                                    value={data.disbursement_date}
                                    onChange={(e) => setData('disbursement_date', e.target.value)}
                                    className={`mt-1.5 block w-full rounded-lg border px-3 py-2 text-sm transition-colors focus:outline-none focus:ring-1 ${
                                        errors.disbursement_date
                                            ? 'border-rose-400 focus:border-rose-500 focus:ring-rose-500'
                                            : 'border-slate-300 focus:border-slate-500 focus:ring-slate-500'
                                    }`}
                                />
                                {errors.disbursement_date && (
                                    <p className="mt-1 flex items-center gap-1 text-xs text-rose-600">
                                        <AlertCircle className="h-3 w-3" />
                                        {errors.disbursement_date}
                                    </p>
                                )}
                            </div>

                            {/* Tanggal Jatuh Tempo Pertama */}
                            <div>
                                <label htmlFor="first_due_date" className="block text-sm font-medium text-slate-700">
                                    Tanggal Jatuh Tempo Angsuran Pertama <span className="text-rose-500">*</span>
                                </label>
                                <input
                                    id="first_due_date"
                                    type="date"
                                    value={data.first_due_date}
                                    onChange={(e) => setData('first_due_date', e.target.value)}
                                    className={`mt-1.5 block w-full rounded-lg border px-3 py-2 text-sm transition-colors focus:outline-none focus:ring-1 ${
                                        errors.first_due_date
                                            ? 'border-rose-400 focus:border-rose-500 focus:ring-rose-500'
                                            : 'border-slate-300 focus:border-slate-500 focus:ring-slate-500'
                                    }`}
                                />
                                {errors.first_due_date && (
                                    <p className="mt-1 flex items-center gap-1 text-xs text-rose-600">
                                        <AlertCircle className="h-3 w-3" />
                                        {errors.first_due_date}
                                    </p>
                                )}
                            </div>

                            {/* Metode Pencairan */}
                            <div>
                                <label htmlFor="method" className="block text-sm font-medium text-slate-700">
                                    Metode Pencairan <span className="text-rose-500">*</span>
                                </label>
                                <select
                                    id="method"
                                    value={data.method}
                                    onChange={(e) => setData('method', e.target.value)}
                                    className="mt-1.5 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"
                                >
                                    <option value="CASH">Tunai (Kasir)</option>
                                    <option value="TRANSFER">Transfer Bank</option>
                                </select>
                            </div>

                            {/* Catatan */}
                            <div>
                                <label htmlFor="notes" className="block text-sm font-medium text-slate-700">
                                    Catatan / Keterangan
                                    <span className="ml-1 text-xs font-normal text-slate-400">(opsional)</span>
                                </label>
                                <input
                                    id="notes"
                                    type="text"
                                    value={data.notes}
                                    onChange={(e) => setData('notes', e.target.value)}
                                    placeholder="Catatan pencairan dana..."
                                    className="mt-1.5 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"
                                />
                            </div>
                        </div>

                        <div className="flex justify-end gap-3 px-6 py-4">
                            <Link
                                href={route('pinjaman.show', loan.id)}
                                className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 transition-colors"
                            >
                                Batal
                            </Link>
                            <button
                                type="submit"
                                disabled={processing}
                                className="rounded-lg bg-blue-700 px-4 py-2 text-sm font-medium text-white hover:bg-blue-800 disabled:opacity-50 active:scale-95 transition-all"
                            >
                                Lanjutkan Pencairan
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
                title="Konfirmasi Pencairan Pinjaman"
                message={`Pencairan sebesar ${formatRupiah(loan.amount)} akan diproses untuk ${loan.member?.full_name}. Tindakan ini tidak dapat dibatalkan.`}
                confirmText="Cairkan Dana"
            />
        </AdminLayout>
    );
}
