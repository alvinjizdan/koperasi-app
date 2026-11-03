import React, { useState } from 'react';
import { Link, useForm } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import ConfirmModal from '@/Components/Admin/ConfirmModal';
import { ArrowUpCircle, ChevronLeft } from 'lucide-react';
import { formatRupiah } from '@/utils/format';

export default function Withdraw({ account, today_date }) {
    const [showConfirm, setShowConfirm] = useState(false);

    const { data, setData, post, processing, errors } = useForm({
        type: 'withdrawal',
        amount: '',
        transaction_date: today_date,
        description: '',
        idempotency_key: crypto.randomUUID(),
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        if (!data.amount || parseFloat(data.amount) <= 0) return;
        setShowConfirm(true);
    };

    const handleConfirm = () => {
        post(route('shm.savings.transactions.withdraw.store', account.id), {
            onError: () => setShowConfirm(false),
        });
    };

    const parsedAmount = parseFloat(data.amount) || 0;
    const isExceedingBalance = parsedAmount > parseFloat(account.balance);

    return (
        <ShmLayout title="Penarikan Simpanan BSM Makro" pageTitle="Catat Penarikan Simpanan SHM">
            <div className="max-w-2xl space-y-6">
                <Link
                    href={route('shm.savings.accounts.show', account.id)}
                    className="inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-slate-800 transition-colors"
                >
                    <ChevronLeft className="h-4 w-4" />
                    Kembali ke rekening SHM
                </Link>

                {/* Account summary */}
                <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-xs">
                    <div className="flex items-center justify-between gap-4">
                        <div>
                            <p className="text-xs text-slate-500">Rekening Simpanan SHM</p>
                            <p className="font-mono text-sm font-semibold text-slate-900">{account.account_number}</p>
                            <p className="text-xs text-slate-500">
                                {account.customer?.name} ({account.customer?.customer_number})
                            </p>
                        </div>
                        <div className="text-right">
                            <p className="text-xs text-slate-500">Saldo saat ini</p>
                            <p className="text-lg font-bold tabular-nums text-slate-900">{formatRupiah(account.balance)}</p>
                        </div>
                    </div>
                </div>

                <div className="rounded-xl border border-slate-200 bg-white shadow-xs">
                    <div className="border-b border-slate-100 px-6 py-4">
                        <div className="flex items-center gap-3">
                            <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-rose-100 text-rose-700">
                                <ArrowUpCircle className="h-5 w-5" />
                            </div>
                            <div>
                                <h2 className="font-semibold text-slate-900">Penarikan Simpanan SHM</h2>
                                <p className="text-sm text-slate-500">Tarik dana dari rekening simpanan SHM nasabah.</p>
                            </div>
                        </div>
                    </div>

                    <form onSubmit={handleSubmit} className="divide-y divide-slate-100">
                        <div className="space-y-5 px-6 py-5">
                            {/* Nominal */}
                            <div>
                                <label htmlFor="amount" className="block text-sm font-medium text-slate-700">
                                    Nominal Penarikan <span className="text-rose-500">*</span>
                                </label>
                                <div className="mt-1.5 flex items-center">
                                    <span className="inline-flex items-center rounded-l-lg border border-r-0 border-slate-300 bg-slate-50 px-3 py-2 text-sm text-slate-500">
                                        Rp
                                    </span>
                                    <input
                                        type="number"
                                        id="amount"
                                        min="1"
                                        step="any"
                                        value={data.amount}
                                        onChange={(e) => setData('amount', e.target.value)}
                                        placeholder="0"
                                        className="w-full rounded-r-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 placeholder-slate-400 focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"
                                    />
                                </div>
                                {isExceedingBalance && (
                                    <p className="mt-1 text-xs text-rose-600">
                                        Nominal penarikan melebihi saldo tersedia ({formatRupiah(account.balance)}).
                                    </p>
                                )}
                                {errors.amount && <p className="mt-1 text-xs text-rose-600">{errors.amount}</p>}
                            </div>

                            {/* Tanggal Transaksi */}
                            <div>
                                <label htmlFor="transaction_date" className="block text-sm font-medium text-slate-700">
                                    Tanggal Transaksi <span className="text-rose-500">*</span>
                                </label>
                                <input
                                    type="date"
                                    id="transaction_date"
                                    value={data.transaction_date}
                                    onChange={(e) => setData('transaction_date', e.target.value)}
                                    className="mt-1.5 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"
                                />
                                {errors.transaction_date && (
                                    <p className="mt-1 text-xs text-rose-600">{errors.transaction_date}</p>
                                )}
                            </div>

                            {/* Keterangan */}
                            <div>
                                <label htmlFor="description" className="block text-sm font-medium text-slate-700">
                                    Keterangan
                                </label>
                                <textarea
                                    id="description"
                                    rows={2}
                                    value={data.description}
                                    onChange={(e) => setData('description', e.target.value)}
                                    placeholder="Contoh: Penarikan sukarela nasabah SHM..."
                                    className="mt-1.5 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 placeholder-slate-400 focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500 resize-none"
                                />
                                {errors.description && (
                                    <p className="mt-1 text-xs text-rose-600">{errors.description}</p>
                                )}
                            </div>
                        </div>

                        <div className="flex items-center justify-end gap-3 px-6 py-4">
                            <Link
                                href={route('shm.savings.accounts.show', account.id)}
                                className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 active:scale-95 transition-all"
                            >
                                Batal
                            </Link>
                            <button
                                type="submit"
                                disabled={processing || isExceedingBalance}
                                className="rounded-lg bg-rose-600 px-4 py-2 text-sm font-medium text-white hover:bg-rose-700 active:scale-95 transition-all disabled:opacity-50"
                            >
                                Catat Penarikan
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <ConfirmModal
                isOpen={showConfirm}
                title="Konfirmasi Penarikan Simpanan SHM"
                message={`Anda akan mencatat penarikan sebesar ${formatRupiah(parsedAmount)} dari rekening ${account.account_number} milik nasabah ${account.customer?.name}. Lanjutkan?`}
                confirmLabel="Ya, Catat Penarikan"
                cancelLabel="Batal"
                variant="danger"
                isLoading={processing}
                onConfirm={handleConfirm}
                onClose={() => setShowConfirm(false)}
            />
        </ShmLayout>
    );
}

