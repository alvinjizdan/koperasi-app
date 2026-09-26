import React, { useState } from 'react';
import { Link, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import ConfirmModal from '@/Components/Admin/ConfirmModal';
import { ChevronLeft, CheckCircle2, AlertCircle, RotateCcw, FileText, Info } from 'lucide-react';
import { formatRupiah, formatDate, formatDateTime } from '@/utils/format';

export default function Show({ transaction, active_session, flash }) {
    const [showConfirm, setShowConfirm] = useState(false);

    const { data, setData, post, processing, errors, reset } = useForm({
        reason: '',
    });

    const isPosted = transaction.status === 'POSTED';
    // Koreksi hanya untuk transaksi yang dicatat langsung melalui modul kasir.
    const isCorrectionAllowed = transaction.can_be_corrected === true;

    const handleSubmit = (e) => {
        e.preventDefault();
        if (!data.reason.trim()) return;
        setShowConfirm(true);
    };

    const handleConfirm = () => {
        post(route('kasir.transaksi.reverse', transaction.id), {
            onError: () => setShowConfirm(false),
            onSuccess: () => reset(),
        });
    };

    return (
        <AdminLayout title={`Transaksi ${transaction.transaction_number}`} pageTitle="Detail Transaksi Kas">
            <div className="max-w-3xl space-y-6">
                {flash?.success && (
                    <div className="flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                        <CheckCircle2 className="h-4 w-4 flex-shrink-0" />
                        {flash.success}
                    </div>
                )}
                {flash?.error && (
                    <div className="flex items-center gap-2 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                        <AlertCircle className="h-4 w-4 flex-shrink-0" />
                        {flash.error}
                    </div>
                )}

                <Link
                    href={route('kasir.transaksi.index')}
                    className="inline-flex items-center gap-1.5 text-sm text-slate-500 transition-colors hover:text-slate-800"
                >
                    <ChevronLeft className="h-4 w-4" />
                    Kembali ke daftar transaksi
                </Link>

                <div className="rounded-xl border border-slate-200 bg-white shadow-xs">
                    <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-4">
                        <div>
                            <p className="text-xs text-slate-500">
                                {transaction.type_label}
                                {transaction.source_label ? ` • ${transaction.source_label}` : ''}
                            </p>
                            <p className="font-mono text-base font-semibold text-slate-900">
                                {transaction.transaction_number}
                            </p>
                        </div>
                        <div className="text-right">
                            <p
                                className={`text-xl font-bold tabular-nums ${
                                    transaction.increases_cash ? 'text-emerald-700' : 'text-rose-700'
                                }`}
                            >
                                {transaction.increases_cash ? '+' : '-'}
                                {formatRupiah(transaction.amount)}
                            </p>
                            <span
                                className={`mt-1 inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold ${
                                    isPosted ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-600'
                                }`}
                            >
                                {isPosted ? 'BERLAKU' : 'DIBATALKAN'}
                            </span>
                        </div>
                    </div>

                    <dl className="grid grid-cols-1 gap-4 px-5 py-4 sm:grid-cols-2">
                        <div>
                            <dt className="text-xs text-slate-500">Kategori</dt>
                            <dd className="text-sm font-medium text-slate-900">{transaction.category || '-'}</dd>
                        </div>
                        <div>
                            <dt className="text-xs text-slate-500">Tanggal Transaksi</dt>
                            <dd className="text-sm text-slate-900">{formatDate(transaction.transaction_date)}</dd>
                        </div>
                        <div>
                            <dt className="text-xs text-slate-500">Waktu Pencatatan</dt>
                            <dd className="text-sm text-slate-900">{formatDateTime(transaction.transacted_at)}</dd>
                        </div>
                        <div>
                            <dt className="text-xs text-slate-500">Operator</dt>
                            <dd className="text-sm text-slate-900">{transaction.operator?.name || '-'}</dd>
                        </div>
                        <div>
                            <dt className="text-xs text-slate-500">Sesi Kasir</dt>
                            <dd className="text-sm text-slate-900">
                                {transaction.session_number ? (
                                    <Link
                                        href={route('kasir.sesi.show', transaction.cashier_session_id)}
                                        className="font-mono text-xs text-emerald-700 hover:text-emerald-900"
                                    >
                                        {transaction.session_number}
                                    </Link>
                                ) : (
                                    <span className="text-slate-400">Tidak terkait sesi</span>
                                )}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs text-slate-500">Referensi Dokumen</dt>
                            <dd className="text-sm text-slate-900">{transaction.document_reference || '-'}</dd>
                        </div>
                        <div className="sm:col-span-2">
                            <dt className="text-xs text-slate-500">Keterangan</dt>
                            <dd className="text-sm text-slate-900">{transaction.description || '-'}</dd>
                        </div>
                    </dl>
                </div>

                {!isPosted && (
                    <div className="rounded-xl border border-amber-200 bg-amber-50 p-4 shadow-xs">
                        <p className="text-xs font-semibold uppercase tracking-wider text-amber-800">Jejak Koreksi</p>
                        <div className="mt-2 space-y-1 text-sm text-amber-900">
                            <p>
                                Transaksi ini dibatalkan melalui pencatatan koreksi, bukan penghapusan fisik, dan tidak
                                lagi dihitung pada saldo kas.
                            </p>
                            <p className="text-xs">
                                {transaction.reversal_reason || 'Tanpa alasan tercatat.'}
                                {transaction.reversed_at ? ` • ${formatDateTime(transaction.reversed_at)}` : ''}
                            </p>
                        </div>
                    </div>
                )}

                {transaction.source_type && (
                    <div className="flex items-start gap-2 rounded-xl border border-blue-200 bg-blue-50 p-4 text-xs leading-relaxed text-blue-900">
                        <Info className="mt-0.5 h-4 w-4 flex-shrink-0" />
                        <p>
                            Transaksi ini berasal dari modul {transaction.source_label || 'lain'}. Koreksi harus
                            dilakukan pada modul sumbernya agar data antar modul tetap konsisten; koreksi langsung dari
                            modul kasir tidak diizinkan.
                        </p>
                    </div>
                )}

                {isCorrectionAllowed && (
                    <div className="rounded-xl border border-slate-200 bg-white shadow-xs">
                        <div className="border-b border-slate-100 px-6 py-4">
                            <div className="flex items-center gap-3">
                                <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-amber-100 text-amber-700">
                                    <RotateCcw className="h-5 w-5" />
                                </div>
                                <div>
                                    <h2 className="font-semibold text-slate-900">Koreksi Transaksi</h2>
                                    <p className="text-sm text-slate-500">
                                        Transaksi tidak dihapus. Sistem menandai transaksi dibatalkan beserta jejak audit
                                        alasan koreksi.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <form onSubmit={handleSubmit} className="divide-y divide-slate-100">
                            <div className="px-6 py-5">
                                <label htmlFor="reason" className="block text-sm font-medium text-slate-700">
                                    Alasan Koreksi <span className="text-rose-500">*</span>
                                </label>
                                <textarea
                                    id="reason"
                                    rows={3}
                                    value={data.reason}
                                    onChange={(e) => setData('reason', e.target.value)}
                                    placeholder="Contoh: Nominal salah input, transaksi dibatalkan oleh anggota..."
                                    className={`mt-1.5 block w-full rounded-lg border px-3 py-2 text-sm transition-colors focus:outline-none focus:ring-1 ${
                                        errors.reason
                                            ? 'border-rose-400 focus:border-rose-500 focus:ring-rose-500'
                                            : 'border-slate-300 focus:border-slate-500 focus:ring-slate-500'
                                    }`}
                                />
                                {errors.reason && (
                                    <p className="mt-1 flex items-center gap-1 text-xs text-rose-600">
                                        <AlertCircle className="h-3 w-3" />
                                        {errors.reason}
                                    </p>
                                )}
                                {errors.status && (
                                    <p className="mt-1 flex items-center gap-1 text-xs text-rose-600">
                                        <AlertCircle className="h-3 w-3" />
                                        {errors.status}
                                    </p>
                                )}
                            </div>
                            <div className="flex justify-end px-6 py-4">
                                <button
                                    type="submit"
                                    disabled={processing || !data.reason.trim()}
                                    className="inline-flex items-center gap-1.5 rounded-lg bg-amber-600 px-4 py-2 text-sm font-medium text-white transition-all hover:bg-amber-700 active:scale-95 disabled:opacity-50"
                                >
                                    <RotateCcw className="h-4 w-4" />
                                    Koreksi Transaksi
                                </button>
                            </div>
                        </form>
                    </div>
                )}

                {isPosted && transaction.source_type && (
                    <div className="flex items-start gap-2 rounded-xl border border-slate-200 bg-slate-50 p-4 text-xs text-slate-600">
                        <FileText className="mt-0.5 h-4 w-4 flex-shrink-0" />
                        <p>Transaksi ini tidak dapat dikoreksi dari modul kasir.</p>
                    </div>
                )}
            </div>

            <ConfirmModal
                isOpen={showConfirm}
                onClose={() => setShowConfirm(false)}
                onConfirm={handleConfirm}
                isProcessing={processing}
                title="Konfirmasi Koreksi Transaksi"
                message={`Transaksi ${transaction.transaction_number} sebesar ${formatRupiah(transaction.amount)} akan ditandai dibatalkan beserta alasan koreksi, dan saldo kas sesi akan disesuaikan. Periksa kembali sebelum melanjutkan.`}
                confirmText="Koreksi Transaksi"
            />
        </AdminLayout>
    );
}
