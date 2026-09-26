import React from 'react';
import { Link } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { TrendingUp, TrendingDown, ChevronLeft } from 'lucide-react';
import { formatRupiah, formatDate, formatDateTime } from '@/utils/format';

export default function Show({ transaction }) {
    const isDeposit = transaction.type === 'deposit';

    return (
        <AdminLayout title={`Transaksi ${transaction.transaction_number}`} pageTitle="Detail Transaksi">
            <div className="max-w-2xl space-y-6">
                <Link
                    href={route('simpanan-rekening.show', transaction.savings_account?.id)}
                    className="inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-slate-800 transition-colors"
                >
                    <ChevronLeft className="h-4 w-4" />
                    Kembali ke rekening
                </Link>

                <div className="rounded-xl border border-slate-200 bg-white shadow-xs overflow-hidden">
                    {/* Header */}
                    <div className={`border-b border-slate-100 px-6 py-4 ${isDeposit ? 'bg-emerald-50' : 'bg-rose-50'}`}>
                        <div className="flex items-center gap-3">
                            <div className={`flex h-9 w-9 items-center justify-center rounded-lg ${
                                isDeposit ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700'
                            }`}>
                                {isDeposit ? <TrendingUp className="h-5 w-5" /> : <TrendingDown className="h-5 w-5" />}
                            </div>
                            <div>
                                <h2 className="font-semibold text-slate-900">
                                    {isDeposit ? 'Setoran' : 'Penarikan'}
                                </h2>
                                <p className="font-mono text-xs text-slate-500">{transaction.transaction_number}</p>
                            </div>
                            <div className="ml-auto">
                                <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${
                                    transaction.status === 'completed'
                                        ? 'bg-emerald-100 text-emerald-800'
                                        : 'bg-amber-100 text-amber-800'
                                }`}>
                                    {transaction.status === 'completed' ? 'Selesai' : 'Dibatalkan'}
                                </span>
                            </div>
                        </div>
                    </div>

                    {/* Nominal */}
                    <div className="px-6 py-5 border-b border-slate-100">
                        <p className="text-xs text-slate-500 mb-1">Nominal</p>
                        <p className={`text-3xl font-bold tabular-nums ${isDeposit ? 'text-emerald-700' : 'text-rose-700'}`}>
                            {isDeposit ? '+' : '-'}{formatRupiah(transaction.amount)}
                        </p>
                    </div>

                    {/* Details grid */}
                    <dl className="grid grid-cols-1 divide-y divide-slate-100 sm:grid-cols-2 sm:divide-x sm:divide-y-0">
                        <div className="px-6 py-4">
                            <dt className="text-xs text-slate-500">Tanggal Transaksi</dt>
                            <dd className="mt-0.5 text-sm font-medium text-slate-900">{formatDate(transaction.transaction_date)}</dd>
                        </div>
                        <div className="px-6 py-4">
                            <dt className="text-xs text-slate-500">Dicatat Pada</dt>
                            <dd className="mt-0.5 text-sm font-medium text-slate-900">{formatDateTime(transaction.created_at)}</dd>
                        </div>
                        <div className="px-6 py-4 sm:col-span-2 border-t border-slate-100">
                            <dt className="text-xs text-slate-500">Rekening</dt>
                            <dd className="mt-0.5">
                                <Link
                                    href={route('simpanan-rekening.show', transaction.savings_account?.id)}
                                    className="font-mono text-sm font-medium text-slate-900 underline"
                                >
                                    {transaction.savings_account?.account_number}
                                </Link>
                                <p className="text-xs text-slate-500">{transaction.savings_account?.member?.full_name}</p>
                            </dd>
                        </div>
                        <div className="px-6 py-4">
                            <dt className="text-xs text-slate-500">Produk Simpanan</dt>
                            <dd className="mt-0.5 text-sm text-slate-900">{transaction.savings_account?.saving_product?.name}</dd>
                        </div>
                        <div className="px-6 py-4">
                            <dt className="text-xs text-slate-500">Operator</dt>
                            <dd className="mt-0.5 text-sm text-slate-900">{transaction.operator?.name || '-'}</dd>
                        </div>
                        {transaction.description && (
                            <div className="px-6 py-4 sm:col-span-2 border-t border-slate-100">
                                <dt className="text-xs text-slate-500">Keterangan</dt>
                                <dd className="mt-0.5 text-sm text-slate-900">{transaction.description}</dd>
                            </div>
                        )}
                    </dl>
                </div>
            </div>
        </AdminLayout>
    );
}
