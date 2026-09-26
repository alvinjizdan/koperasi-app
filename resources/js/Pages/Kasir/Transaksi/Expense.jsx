import React, { useState } from 'react';
import { Link, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import ConfirmModal from '@/Components/Admin/ConfirmModal';
import CashierSessionCard from '@/Components/Admin/CashierSessionCard';
import { ArrowUpCircle, ChevronLeft, AlertCircle } from 'lucide-react';
import { formatRupiah } from '@/utils/format';

export default function Expense({ today_date, categories, active_session }) {
    const [showConfirm, setShowConfirm] = useState(false);

    const { data, setData, post, processing, errors } = useForm({
        amount: '',
        category: categories[0] || '',
        transaction_date: today_date,
        description: '',
        document_reference: '',
        idempotency_key: crypto.randomUUID(),
    });

    const parsedAmount = parseFloat(data.amount) || 0;
    const availableBalance = parseFloat(active_session?.totals?.closing_balance) || 0;
    const insufficientCash = parsedAmount > 0 && parsedAmount > availableBalance;
    const canSubmit = data.amount !== '' && parsedAmount > 0 && data.category !== '' && !insufficientCash;

    const handleSubmit = (e) => {
        e.preventDefault();
        if (!canSubmit) return;
        setShowConfirm(true);
    };

    const handleConfirm = () => {
        post(route('kasir.pengeluaran.store'), {
            onError: () => setShowConfirm(false),
        });
    };

    return (
        <AdminLayout title="Pengeluaran Kas" pageTitle="Pengeluaran Kas">
            <div className="max-w-2xl space-y-6">
                <Link
                    href={route('kasir.index')}
                    className="inline-flex items-center gap-1.5 text-sm text-slate-500 transition-colors hover:text-slate-800"
                >
                    <ChevronLeft className="h-4 w-4" />
                    Kembali ke dashboard kasir
                </Link>

                <CashierSessionCard session={active_session} />

                <div className="rounded-xl border border-slate-200 bg-white shadow-xs">
                    <div className="border-b border-slate-100 px-6 py-4">
                        <div className="flex items-center gap-3">
                            <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-rose-100 text-rose-700">
                                <ArrowUpCircle className="h-5 w-5" />
                            </div>
                            <div>
                                <h2 className="font-semibold text-slate-900">Form Pengeluaran Kas</h2>
                                <p className="text-sm text-slate-500">
                                    Catat uang tunai yang keluar dari kasir pada sesi aktif.
                                </p>
                            </div>
                        </div>
                    </div>

                    <form onSubmit={handleSubmit} className="divide-y divide-slate-100">
                        <div className="space-y-5 px-6 py-5">
                            <div className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600">
                                Saldo kas tersedia pada sesi aktif:{' '}
                                <span className="font-semibold text-slate-900">{formatRupiah(availableBalance)}</span>
                            </div>

                            <div>
                                <label htmlFor="category" className="block text-sm font-medium text-slate-700">
                                    Kategori Pengeluaran <span className="text-rose-500">*</span>
                                </label>
                                <select
                                    id="category"
                                    value={data.category}
                                    onChange={(e) => setData('category', e.target.value)}
                                    className={`mt-1.5 block w-full rounded-lg border px-3 py-2 text-sm transition-colors focus:outline-none focus:ring-1 ${
                                        errors.category
                                            ? 'border-rose-400 focus:border-rose-500 focus:ring-rose-500'
                                            : 'border-slate-300 focus:border-slate-500 focus:ring-slate-500'
                                    }`}
                                >
                                    {categories.map((category) => (
                                        <option key={category} value={category}>
                                            {category}
                                        </option>
                                    ))}
                                </select>
                                {errors.category && (
                                    <p className="mt-1 flex items-center gap-1 text-xs text-rose-600">
                                        <AlertCircle className="h-3 w-3" />
                                        {errors.category}
                                    </p>
                                )}
                            </div>

                            <div>
                                <label htmlFor="amount" className="block text-sm font-medium text-slate-700">
                                    Nominal Pengeluaran <span className="text-rose-500">*</span>
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
                                            errors.amount || insufficientCash
                                                ? 'border-rose-400 focus:border-rose-500 focus:ring-rose-500'
                                                : 'border-slate-300 focus:border-slate-500 focus:ring-slate-500'
                                        }`}
                                    />
                                </div>
                                {parsedAmount > 0 && !insufficientCash && (
                                    <p className="mt-1 text-xs text-slate-500">
                                        {formatRupiah(parsedAmount)} — sisa saldo kas{' '}
                                        {formatRupiah(availableBalance - parsedAmount)}
                                    </p>
                                )}
                                {insufficientCash && (
                                    <p className="mt-1 flex items-center gap-1 text-xs text-rose-600">
                                        <AlertCircle className="h-3 w-3" />
                                        Nominal melebihi saldo kas tersedia ({formatRupiah(availableBalance)}).
                                    </p>
                                )}
                                {errors.amount && !insufficientCash && (
                                    <p className="mt-1 flex items-center gap-1 text-xs text-rose-600">
                                        <AlertCircle className="h-3 w-3" />
                                        {errors.amount}
                                    </p>
                                )}
                            </div>

                            <div>
                                <label htmlFor="transaction_date" className="block text-sm font-medium text-slate-700">
                                    Tanggal Transaksi <span className="text-rose-500">*</span>
                                </label>
                                <input
                                    id="transaction_date"
                                    type="date"
                                    value={data.transaction_date}
                                    onChange={(e) => setData('transaction_date', e.target.value)}
                                    className={`mt-1.5 block w-full rounded-lg border px-3 py-2 text-sm transition-colors focus:outline-none focus:ring-1 ${
                                        errors.transaction_date
                                            ? 'border-rose-400 focus:border-rose-500 focus:ring-rose-500'
                                            : 'border-slate-300 focus:border-slate-500 focus:ring-slate-500'
                                    }`}
                                />
                                {errors.transaction_date && (
                                    <p className="mt-1 flex items-center gap-1 text-xs text-rose-600">
                                        <AlertCircle className="h-3 w-3" />
                                        {errors.transaction_date}
                                    </p>
                                )}
                            </div>

                            <div>
                                <label
                                    htmlFor="document_reference"
                                    className="block text-sm font-medium text-slate-700"
                                >
                                    Referensi Dokumen
                                    <span className="ml-1 text-xs font-normal text-slate-400">(opsional)</span>
                                </label>
                                <input
                                    id="document_reference"
                                    type="text"
                                    value={data.document_reference}
                                    onChange={(e) => setData('document_reference', e.target.value)}
                                    placeholder="Nomor nota / bukti pengeluaran..."
                                    className="mt-1.5 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"
                                />
                            </div>

                            <div>
                                <label htmlFor="description" className="block text-sm font-medium text-slate-700">
                                    Keterangan
                                    <span className="ml-1 text-xs font-normal text-slate-400">(opsional)</span>
                                </label>
                                <input
                                    id="description"
                                    type="text"
                                    value={data.description}
                                    onChange={(e) => setData('description', e.target.value)}
                                    placeholder="Keterangan pengeluaran kas..."
                                    className="mt-1.5 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"
                                />
                            </div>
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
                                disabled={processing || !canSubmit}
                                className="rounded-lg bg-rose-700 px-4 py-2 text-sm font-medium text-white transition-all hover:bg-rose-800 active:scale-95 disabled:opacity-50"
                            >
                                Simpan Pengeluaran
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
                title="Konfirmasi Pengeluaran Kas"
                message={`Pengeluaran sebesar ${formatRupiah(parsedAmount)} dengan kategori "${data.category}" akan mengurangi saldo kas sesi aktif. Periksa kembali sebelum menyimpan.`}
                confirmText="Catat Pengeluaran"
            />
        </AdminLayout>
    );
}
