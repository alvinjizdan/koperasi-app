import React, { useState } from 'react';
import { Link, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import ConfirmModal from '@/Components/Admin/ConfirmModal';
import { Receipt, ChevronLeft, AlertCircle } from 'lucide-react';
import { formatRupiah } from '@/utils/format';

export default function Pay({ loan, next_schedule, suggested_amount, today_date }) {
    const [showConfirm, setShowConfirm] = useState(false);

    const { data, setData, post, processing, errors } = useForm({
        amount: suggested_amount ? String(suggested_amount) : '',
        payment_date: today_date,
        notes: '',
        idempotency_key: crypto.randomUUID(),
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        if (!data.amount || parseFloat(data.amount) <= 0) return;
        setShowConfirm(true);
    };

    const handleConfirm = () => {
        post(route('angsuran.pay.store', loan.id), {
            onError: () => setShowConfirm(false),
        });
    };

    const parsedAmount = parseFloat(data.amount) || 0;
    // Batas overpayment memakai `total_owed` dari server — nilai yang sama yang
    // divalidasi backend saat POST pembayaran (InstallmentPaymentService).
    const remainingObligation = parseFloat(loan.total_owed) || 0;
    const isOverpayment = parsedAmount > 0 && parsedAmount > remainingObligation;

    return (
        <AdminLayout title="Bayar Angsuran" pageTitle="Bayar Angsuran">
            <div className="max-w-2xl space-y-6">
                <Link
                    href={route('angsuran.schedule', loan.id)}
                    className="inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-slate-800 transition-colors"
                >
                    <ChevronLeft className="h-4 w-4" />
                    Kembali ke jadwal angsuran
                </Link>

                {/* Loan Info Summary */}
                <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-xs">
                    <div className="flex items-center justify-between gap-4">
                        <div>
                            <p className="text-xs text-slate-500">Pinjaman</p>
                            <p className="font-mono text-sm font-semibold text-slate-900">{loan.loan_number}</p>
                            <p className="text-xs text-slate-500">{loan.member?.full_name}</p>
                        </div>
                        <div className="text-right">
                            <p className="text-xs text-slate-500">Sisa Kewajiban Pokok</p>
                            <p className="text-lg font-bold tabular-nums text-emerald-700">{formatRupiah(loan.remaining_balance)}</p>
                        </div>
                    </div>
                </div>

                <div className="rounded-xl border border-slate-200 bg-white shadow-xs">
                    <div className="border-b border-slate-100 px-6 py-4">
                        <div className="flex items-center gap-3">
                            <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-emerald-100 text-emerald-700">
                                <Receipt className="h-5 w-5" />
                            </div>
                            <div>
                                <h2 className="font-semibold text-slate-900">Form Pembayaran Angsuran</h2>
                                <p className="text-sm text-slate-500">
                                    Catat penerimaan pembayaran angsuran pinjaman.
                                </p>
                            </div>
                        </div>
                    </div>

                    <form onSubmit={handleSubmit} className="divide-y divide-slate-100">
                        <div className="space-y-5 px-6 py-5">
                            {/* Rekomendasi angsuran */}
                            {next_schedule && (
                                <div className="rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs text-slate-600 flex justify-between items-center">
                                    <span>Tagihan berikutnya: <strong className="text-slate-900">Angsuran #{next_schedule.installment_number}</strong></span>
                                    <span className="font-bold text-slate-900">{formatRupiah(next_schedule.remaining_amount)}</span>
                                </div>
                            )}

                            {/* Nominal Pembayaran */}
                            <div>
                                <label htmlFor="amount" className="block text-sm font-medium text-slate-700">
                                    Nominal Pembayaran <span className="text-rose-500">*</span>
                                </label>
                                <div className="mt-1.5 flex items-center">
                                    <span className="inline-flex items-center rounded-l-lg border border-r-0 border-slate-300 bg-slate-50 px-3 py-2 text-sm text-slate-500">
                                        Rp
                                    </span>
                                    <input
                                        id="amount"
                                        type="number"
                                        min="1"
                                        step="1"
                                        value={data.amount}
                                        onChange={(e) => setData('amount', e.target.value)}
                                        placeholder="0"
                                        className={`block w-full rounded-r-lg border px-3 py-2 text-sm tabular-nums transition-colors focus:outline-none focus:ring-1 ${
                                            errors.amount || isOverpayment
                                                ? 'border-rose-400 focus:border-rose-500 focus:ring-rose-500'
                                                : 'border-slate-300 focus:border-slate-500 focus:ring-slate-500'
                                        }`}
                                    />
                                </div>
                                {parsedAmount > 0 && !isOverpayment && (
                                    <p className="mt-1 text-xs text-slate-500">
                                        {formatRupiah(parsedAmount)}
                                    </p>
                                )}
                                {isOverpayment && (
                                    <p className="mt-1 flex items-center gap-1 text-xs text-rose-600">
                                        <AlertCircle className="h-3 w-3" />
                                        Nominal melebihi sisa kewajiban pinjaman ({formatRupiah(remainingObligation)}).
                                    </p>
                                )}
                                {errors.amount && !isOverpayment && (
                                    <p className="mt-1 flex items-center gap-1 text-xs text-rose-600">
                                        <AlertCircle className="h-3 w-3" />
                                        {errors.amount}
                                    </p>
                                )}
                            </div>

                            {/* Tanggal Pembayaran */}
                            <div>
                                <label htmlFor="payment_date" className="block text-sm font-medium text-slate-700">
                                    Tanggal Pembayaran <span className="text-rose-500">*</span>
                                </label>
                                <input
                                    id="payment_date"
                                    type="date"
                                    value={data.payment_date}
                                    onChange={(e) => setData('payment_date', e.target.value)}
                                    className={`mt-1.5 block w-full rounded-lg border px-3 py-2 text-sm transition-colors focus:outline-none focus:ring-1 ${
                                        errors.payment_date
                                            ? 'border-rose-400 focus:border-rose-500 focus:ring-rose-500'
                                            : 'border-slate-300 focus:border-slate-500 focus:ring-slate-500'
                                    }`}
                                />
                                {errors.payment_date && (
                                    <p className="mt-1 flex items-center gap-1 text-xs text-rose-600">
                                        <AlertCircle className="h-3 w-3" />
                                        {errors.payment_date}
                                    </p>
                                )}
                            </div>

                            {/* Catatan */}
                            <div>
                                <label htmlFor="notes" className="block text-sm font-medium text-slate-700">
                                    Catatan
                                    <span className="ml-1 text-xs font-normal text-slate-400">(opsional)</span>
                                </label>
                                <input
                                    id="notes"
                                    type="text"
                                    value={data.notes}
                                    onChange={(e) => setData('notes', e.target.value)}
                                    placeholder="Keterangan pembayaran angsuran..."
                                    className="mt-1.5 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"
                                />
                            </div>
                        </div>

                        <div className="flex justify-end gap-3 px-6 py-4">
                            <Link
                                href={route('angsuran.schedule', loan.id)}
                                className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 transition-colors"
                            >
                                Batal
                            </Link>
                            <button
                                type="submit"
                                disabled={processing || !data.amount || isOverpayment}
                                className="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-800 disabled:opacity-50 active:scale-95 transition-all"
                            >
                                Lanjutkan
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
                title="Konfirmasi Pembayaran Angsuran"
                message={`Pembayaran sebesar ${formatRupiah(parsedAmount)} akan dicatat untuk pinjaman ${loan.loan_number} atas nama ${loan.member?.full_name}. Periksa kembali sebelum melanjutkan.`}
                confirmText="Catat Pembayaran"
            />
        </AdminLayout>
    );
}
