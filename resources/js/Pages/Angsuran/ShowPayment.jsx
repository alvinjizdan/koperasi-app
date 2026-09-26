import React from 'react';
import { Link } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Receipt, ChevronLeft } from 'lucide-react';
import { formatRupiah, formatDate, formatDateTime } from '@/utils/format';

export default function ShowPayment({ payment }) {
    return (
        <AdminLayout title={`Transaksi ${payment.payment_number}`} pageTitle="Detail Pembayaran Angsuran">
            <div className="max-w-2xl space-y-6">
                <Link
                    href={route('angsuran.schedule', payment.loan?.id)}
                    className="inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-slate-800 transition-colors"
                >
                    <ChevronLeft className="h-4 w-4" />
                    Kembali ke jadwal angsuran
                </Link>

                <div className="rounded-xl border border-slate-200 bg-white shadow-xs overflow-hidden">
                    {/* Header */}
                    <div className="border-b border-slate-100 px-6 py-4 bg-emerald-50">
                        <div className="flex items-center gap-3">
                            <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-emerald-100 text-emerald-700">
                                <Receipt className="h-5 w-5" />
                            </div>
                            <div>
                                <h2 className="font-semibold text-slate-900">Pembayaran Angsuran</h2>
                                <p className="font-mono text-xs text-slate-500">{payment.payment_number}</p>
                            </div>
                            <div className="ml-auto">
                                <span className="inline-flex items-center rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-semibold text-emerald-800">
                                    Selesai
                                </span>
                            </div>
                        </div>
                    </div>

                    {/* Nominal */}
                    <div className="px-6 py-5 border-b border-slate-100">
                        <p className="text-xs text-slate-500 mb-1">Nominal Pembayaran</p>
                        <p className="text-3xl font-bold tabular-nums text-emerald-700">
                            {formatRupiah(payment.amount)}
                        </p>
                    </div>

                    {/* Details grid */}
                    <dl className="grid grid-cols-1 divide-y divide-slate-100 sm:grid-cols-2 sm:divide-x sm:divide-y-0">
                        <div className="px-6 py-4">
                            <dt className="text-xs text-slate-500">Tanggal Pembayaran</dt>
                            <dd className="mt-0.5 text-sm font-medium text-slate-900">{formatDate(payment.payment_date)}</dd>
                        </div>
                        <div className="px-6 py-4">
                            <dt className="text-xs text-slate-500">Dicatat Pada</dt>
                            <dd className="mt-0.5 text-sm font-medium text-slate-900">{formatDateTime(payment.created_at)}</dd>
                        </div>
                        <div className="px-6 py-4 sm:col-span-2 border-t border-slate-100">
                            <dt className="text-xs text-slate-500">Pinjaman</dt>
                            <dd className="mt-0.5">
                                <Link
                                    href={route('pinjaman.show', payment.loan?.id)}
                                    className="font-mono text-sm font-medium text-slate-900 underline"
                                >
                                    {payment.loan?.loan_number}
                                </Link>
                                <p className="text-xs text-slate-500">{payment.loan?.member?.full_name}</p>
                            </dd>
                        </div>
                        <div className="px-6 py-4">
                            <dt className="text-xs text-slate-500">Operator</dt>
                            <dd className="mt-0.5 text-sm text-slate-900">{payment.operator?.name || '-'}</dd>
                        </div>
                        {payment.schedule && (
                            <div className="px-6 py-4">
                                <dt className="text-xs text-slate-500">Dialokasikan Ke</dt>
                                <dd className="mt-0.5 text-sm font-medium text-slate-900">Angsuran #{payment.schedule.installment_number}</dd>
                            </div>
                        )}
                        {payment.notes && (
                            <div className="px-6 py-4 sm:col-span-2 border-t border-slate-100">
                                <dt className="text-xs text-slate-500">Catatan</dt>
                                <dd className="mt-0.5 text-sm text-slate-900">{payment.notes}</dd>
                            </div>
                        )}
                    </dl>
                </div>
            </div>
        </AdminLayout>
    );
}
