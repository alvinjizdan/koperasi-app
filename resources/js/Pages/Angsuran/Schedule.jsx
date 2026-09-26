import React from 'react';
import { Link } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    Calendar,
    ChevronLeft,
    Receipt,
    CheckCircle2,
    AlertCircle,
    TrendingUp,
} from 'lucide-react';
import { formatRupiah, formatDate, formatDateTime } from '@/utils/format';

export default function Schedule({ loan, summary, flash }) {
    const getScheduleStatusBadge = (st) => {
        switch (st) {
            case 'PAID':
                return <span className="inline-flex items-center rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-semibold text-emerald-800">Lunas</span>;
            case 'PARTIALLY_PAID':
                return <span className="inline-flex items-center rounded-full bg-blue-100 px-2.5 py-0.5 text-xs font-semibold text-blue-800">Sebagian</span>;
            case 'DUE':
                return <span className="inline-flex items-center rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-semibold text-amber-800">Jatuh Tempo Hari Ini</span>;
            case 'OVERDUE':
                return <span className="inline-flex items-center rounded-full bg-rose-100 px-2.5 py-0.5 text-xs font-semibold text-rose-800">Menunggak</span>;
            case 'UPCOMING':
            default:
                return <span className="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-semibold text-slate-600">Mendatang</span>;
        }
    };

    return (
        <AdminLayout title={`Jadwal Angsuran ${loan.loan_number}`} pageTitle="Jadwal Angsuran">
            <div className="space-y-6">
                <div className="flex items-center justify-between gap-4">
                    <Link
                        href={route('angsuran.index')}
                        className="inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-slate-800 transition-colors"
                    >
                        <ChevronLeft className="h-4 w-4" />
                        Daftar angsuran
                    </Link>

                    {in_array(loan.status, ['ACTIVE', 'DISBURSED']) && (
                        <Link
                            href={route('angsuran.pay.create', loan.id)}
                            className="inline-flex items-center gap-2 rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800 active:scale-95 transition-all shadow-xs"
                        >
                            <Receipt className="h-4 w-4" />
                            Bayar Angsuran
                        </Link>
                    )}
                </div>

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

                {/* Summary Card */}
                <div className="rounded-xl border border-slate-200 bg-white shadow-xs overflow-hidden">
                    <div className="border-b border-slate-100 px-6 py-4 bg-slate-50 flex items-center justify-between">
                        <div>
                            <p className="font-mono text-sm font-semibold text-slate-900">{loan.loan_number}</p>
                            <p className="text-xs text-slate-500">Anggota: {loan.member?.full_name} ({loan.member?.member_number})</p>
                        </div>
                        <div className="text-right">
                            <span className="inline-flex items-center rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-semibold text-emerald-800">
                                {loan.status === 'PAID_OFF' ? 'Lunas' : 'Aktif'}
                            </span>
                        </div>
                    </div>

                    <div className="grid grid-cols-2 divide-x divide-slate-100 sm:grid-cols-4">
                        <div className="px-5 py-4">
                            <p className="text-xs text-slate-500">Pokok Pinjaman</p>
                            <p className="mt-0.5 text-sm font-semibold text-slate-900">{formatRupiah(loan.amount)}</p>
                        </div>
                        <div className="px-5 py-4">
                            <p className="text-xs text-slate-500">Total Dibayar</p>
                            <p className="mt-0.5 text-sm font-semibold text-emerald-700">{formatRupiah(summary.total_paid)}</p>
                        </div>
                        <div className="px-5 py-4">
                            <p className="text-xs text-slate-500">Sisa Kewajiban Pokok</p>
                            <p className="mt-0.5 text-base font-bold tabular-nums text-slate-900">{formatRupiah(summary.remaining_balance)}</p>
                        </div>
                        <div className="px-5 py-4">
                            <p className="text-xs text-slate-500">Jumlah Menunggak</p>
                            <p className={`mt-0.5 text-sm font-semibold ${summary.overdue_schedules_count > 0 ? 'text-rose-600' : 'text-slate-900'}`}>
                                {summary.overdue_schedules_count} Angsuran
                            </p>
                        </div>
                    </div>
                </div>

                {/* Schedule Table */}
                <div className="space-y-3">
                    <h3 className="text-sm font-semibold text-slate-900">Jadwal Angsuran Pokok</h3>
                    <div className="rounded-xl border border-slate-200 bg-white shadow-xs overflow-hidden">
                        <table className="min-w-full divide-y divide-slate-200">
                            <thead className="bg-slate-50">
                                <tr>
                                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">No</th>
                                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Jatuh Tempo</th>
                                    <th className="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-slate-500">Tagihan Pokok</th>
                                    <th className="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-slate-500">Dibayar</th>
                                    <th className="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-slate-500">Sisa</th>
                                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Status</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {loan.schedules.map((sch) => (
                                    <tr key={sch.id} className="hover:bg-slate-50 transition-colors">
                                        <td className="px-4 py-3 text-sm font-medium text-slate-900">
                                            #{sch.installment_number}
                                        </td>
                                        <td className="px-4 py-3 text-sm text-slate-600">
                                            {formatDate(sch.due_date)}
                                        </td>
                                        <td className="px-4 py-3 text-right text-sm font-semibold tabular-nums text-slate-900">
                                            {formatRupiah(sch.total_amount)}
                                        </td>
                                        <td className="px-4 py-3 text-right text-sm font-semibold tabular-nums text-emerald-700">
                                            {formatRupiah(sch.paid_amount)}
                                        </td>
                                        <td className="px-4 py-3 text-right text-sm font-semibold tabular-nums text-slate-700">
                                            {formatRupiah(sch.remaining_amount)}
                                        </td>
                                        <td className="px-4 py-3">
                                            {getScheduleStatusBadge(sch.status)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>

                {/* Payments history */}
                {loan.payments && loan.payments.length > 0 && (
                    <div className="space-y-3">
                        <h3 className="text-sm font-semibold text-slate-900">Riwayat Transaksi Pembayaran</h3>
                        <div className="rounded-xl border border-slate-200 bg-white shadow-xs overflow-hidden">
                            <table className="min-w-full divide-y divide-slate-200">
                                <thead className="bg-slate-50">
                                    <tr>
                                        <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">No. Transaksi</th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Tanggal</th>
                                        <th className="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-slate-500">Nominal</th>
                                        <th className="hidden md:table-cell px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Operator</th>
                                        <th className="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-slate-500">Detail</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {loan.payments.map((pmt) => (
                                        <tr key={pmt.id} className="hover:bg-slate-50 transition-colors">
                                            <td className="px-4 py-3 text-xs font-mono text-slate-900">
                                                {pmt.payment_number}
                                            </td>
                                            <td className="px-4 py-3 text-sm text-slate-600">
                                                {formatDate(pmt.payment_date)}
                                            </td>
                                            <td className="px-4 py-3 text-right text-sm font-semibold tabular-nums text-emerald-700">
                                                {formatRupiah(pmt.amount)}
                                            </td>
                                            <td className="hidden md:table-cell px-4 py-3 text-sm text-slate-600">
                                                {pmt.operator?.name || '-'}
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                <Link
                                                    href={route('angsuran.payment.show', pmt.id)}
                                                    className="text-xs text-slate-400 hover:text-slate-700 underline"
                                                >
                                                    Lihat
                                                </Link>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}
            </div>
        </AdminLayout>
    );
}

function in_array(needle, haystack) {
    return haystack.includes(needle);
}
