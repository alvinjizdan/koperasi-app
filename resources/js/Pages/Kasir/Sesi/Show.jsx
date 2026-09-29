import React from 'react';
import { Link } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { ScrollArea, ScrollBar } from '@/Components/ui/scroll-area';
import EmptyState from '@/Components/Admin/EmptyState';
import StatCard from '@/Components/Admin/StatCard';
import {
    ArrowDownCircle,
    ArrowUpCircle,
    Banknote,
    ChevronLeft,
    ChevronRight,
    CheckCircle2,
    AlertCircle,
    Lock,
    Receipt,
    Scale,
} from 'lucide-react';
import { formatRupiah, formatDate, formatDateTime } from '@/utils/format';

function StatusBadge({ status }) {
    const isOpen = status === 'OPEN';

    return (
        <span
            className={`inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold ${
                isOpen ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600'
            }`}
        >
            {isOpen ? 'TERBUKA' : 'DITUTUP'}
        </span>
    );
}

export default function Show({ session, totals, transactions, active_session, flash }) {
    const isOpen = session.status === 'OPEN';

    return (
        <AdminLayout title={`Sesi ${session.session_number}`} pageTitle="Detail Sesi Kasir">
            <div className="space-y-6">
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
                    href={route('kasir.sesi.index')}
                    className="inline-flex items-center gap-1.5 text-sm text-slate-500 transition-colors hover:text-slate-800"
                >
                    <ChevronLeft className="h-4 w-4" />
                    Kembali ke riwayat sesi
                </Link>

                <div className="rounded-xl border border-slate-200 bg-white shadow-xs">
                    <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-4">
                        <div>
                            <p className="text-xs text-slate-500">Nomor Sesi</p>
                            <p className="font-mono text-base font-semibold text-slate-900">{session.session_number}</p>
                        </div>
                        <div className="flex items-center gap-3">
                            <StatusBadge status={session.status} />
                            {isOpen && (
                                <Link
                                    href={route('kasir.sesi.close.create', session.id)}
                                    className="inline-flex items-center gap-1.5 rounded-lg bg-slate-800 px-3 py-1.5 text-xs font-semibold text-white transition-all hover:bg-slate-900 active:scale-95"
                                >
                                    <Lock className="h-3.5 w-3.5" />
                                    Tutup Kasir
                                </Link>
                            )}
                        </div>
                    </div>

                    <div className="grid grid-cols-1 gap-4 px-5 py-4 sm:grid-cols-2 lg:grid-cols-4">
                        <div>
                            <p className="text-xs text-slate-500">Tanggal Operasional</p>
                            <p className="text-sm font-semibold text-slate-900">
                                {formatDate(session.operational_date)}
                            </p>
                        </div>
                        <div>
                            <p className="text-xs text-slate-500">Operator</p>
                            <p className="text-sm font-semibold text-slate-900">{session.operator?.name || '-'}</p>
                        </div>
                        <div>
                            <p className="text-xs text-slate-500">Waktu Buka</p>
                            <p className="text-sm text-slate-900">{formatDateTime(session.opened_at)}</p>
                        </div>
                        <div>
                            <p className="text-xs text-slate-500">Waktu Tutup</p>
                            <p className="text-sm text-slate-900">
                                {session.closed_at ? formatDateTime(session.closed_at) : '—'}
                            </p>
                        </div>
                    </div>

                    {session.opening_notes && (
                        <div className="border-t border-slate-100 px-5 py-3">
                            <p className="text-xs text-slate-500">Catatan Pembukaan</p>
                            <p className="text-sm text-slate-800">{session.opening_notes}</p>
                        </div>
                    )}

                    {session.closing_notes && (
                        <div className="border-t border-slate-100 px-5 py-3">
                            <p className="text-xs text-slate-500">Catatan Penutupan</p>
                            <p className="text-sm text-slate-800">{session.closing_notes}</p>
                        </div>
                    )}
                </div>

                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard
                        title="Saldo Awal"
                        value={formatRupiah(totals.opening_balance)}
                        icon={Banknote}
                        statusNote="Input operator"
                    />
                    <StatCard
                        title="Total Penerimaan"
                        value={formatRupiah(totals.income)}
                        icon={ArrowDownCircle}
                        statusNote="Transaksi sah"
                    />
                    <StatCard
                        title="Total Pengeluaran"
                        value={formatRupiah(totals.expense)}
                        icon={ArrowUpCircle}
                        statusNote={`Setoran: ${formatRupiah(totals.deposit)}`}
                    />
                    <StatCard
                        title="Saldo Akhir Sistem"
                        value={formatRupiah(totals.closing_balance)}
                        icon={Scale}
                        statusNote={isOpen ? 'Dihitung berjalan' : 'Snapshot penutupan'}
                    />
                </div>

                {!isOpen && (
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-xs">
                            <p className="text-xs text-slate-500">Saldo Fisik (Hasil Hitung)</p>
                            <p className="mt-1 text-lg font-bold tabular-nums text-slate-900">
                                {formatRupiah(session.physical_closing_balance ?? 0)}
                            </p>
                        </div>
                        <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-xs">
                            <p className="text-xs text-slate-500">Saldo Akhir Sistem</p>
                            <p className="mt-1 text-lg font-bold tabular-nums text-slate-900">
                                {formatRupiah(session.system_balance)}
                            </p>
                        </div>
                        <div
                            className={`rounded-xl border p-4 shadow-xs ${
                                session.difference === 0 ? 'border-slate-200 bg-white' : 'border-rose-200 bg-rose-50'
                            }`}
                        >
                            <p className="text-xs text-slate-500">Selisih Kas</p>
                            <p
                                className={`mt-1 text-lg font-bold tabular-nums ${
                                    session.difference === 0 ? 'text-slate-900' : 'text-rose-700'
                                }`}
                            >
                                {formatRupiah(session.difference ?? 0)}
                            </p>
                        </div>
                    </div>
                )}

                <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xs">
                    <div className="border-b border-slate-100 px-5 py-4">
                        <h2 className="text-sm font-semibold text-slate-900">Transaksi Sesi</h2>
                    </div>

                    {transactions.data.length === 0 ? (
                        <div className="p-4">
                            <EmptyState
                                icon={Receipt}
                                title="Belum ada transaksi"
                                description="Transaksi kas pada sesi ini akan tampil di sini."
                            />
                        </div>
                    ) : (
                        <>
                            <ScrollArea>
<ScrollBar orientation="horizontal" />
                                <table className="min-w-full divide-y divide-slate-200">
                                    <thead className="bg-slate-50">
                                        <tr>
                                            <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">
                                                No. Transaksi
                                            </th>
                                            <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">
                                                Waktu
                                            </th>
                                            <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">
                                                Jenis
                                            </th>
                                            <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">
                                                Kategori
                                            </th>
                                            <th className="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-slate-500">
                                                Nominal
                                            </th>
                                            <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">
                                                Status
                                            </th>
                                            <th className="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-slate-500">
                                                Aksi
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100">
                                        {transactions.data.map((transaction) => (
                                            <tr key={transaction.id} className="transition-colors hover:bg-slate-50">
                                                <td className="px-4 py-3 font-mono text-xs font-medium text-slate-900">
                                                    {transaction.transaction_number}
                                                    {transaction.source_label && (
                                                        <span className="ml-2 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-medium text-slate-600">
                                                            {transaction.source_label}
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="px-4 py-3 text-xs text-slate-500">
                                                    {formatDateTime(transaction.transacted_at)}
                                                </td>
                                                <td className="px-4 py-3 text-sm text-slate-700">
                                                    {transaction.type_label}
                                                </td>
                                                <td className="px-4 py-3 text-sm text-slate-600">
                                                    {transaction.category || '-'}
                                                </td>
                                                <td
                                                    className={`px-4 py-3 text-right text-sm font-semibold tabular-nums ${
                                                        transaction.increases_cash ? 'text-emerald-700' : 'text-rose-700'
                                                    }`}
                                                >
                                                    {transaction.increases_cash ? '+' : '-'}
                                                    {formatRupiah(transaction.amount)}
                                                </td>
                                                <td className="px-4 py-3">
                                                    <span
                                                        className={`inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold ${
                                                            transaction.status === 'POSTED'
                                                                ? 'bg-emerald-100 text-emerald-800'
                                                                : 'bg-slate-200 text-slate-600'
                                                        }`}
                                                    >
                                                        {transaction.status === 'POSTED' ? 'BERLAKU' : 'DIBATALKAN'}
                                                    </span>
                                                </td>
                                                <td className="px-4 py-3 text-right">
                                                    <Link
                                                        href={route('kasir.transaksi.show', transaction.id)}
                                                        className="rounded border border-slate-300 bg-white px-2.5 py-1 text-xs font-medium text-slate-700 transition-colors hover:bg-slate-50"
                                                    >
                                                        Detail
                                                    </Link>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </ScrollArea>

                            {transactions.last_page > 1 && (
                                <div className="flex items-center justify-between border-t border-slate-200 px-4 py-3">
                                    <p className="text-sm text-slate-500">
                                        Halaman {transactions.current_page} dari {transactions.last_page}
                                    </p>
                                    <div className="flex gap-1">
                                        {transactions.prev_page_url && (
                                            <Link
                                                href={transactions.prev_page_url}
                                                className="rounded p-1.5 text-slate-500 transition-colors hover:bg-slate-100"
                                            >
                                                <ChevronLeft className="h-4 w-4" />
                                            </Link>
                                        )}
                                        {transactions.next_page_url && (
                                            <Link
                                                href={transactions.next_page_url}
                                                className="rounded p-1.5 text-slate-500 transition-colors hover:bg-slate-100"
                                            >
                                                <ChevronRight className="h-4 w-4" />
                                            </Link>
                                        )}
                                    </div>
                                </div>
                            )}
                        </>
                    )}
                </div>
            </div>
        </AdminLayout>
    );
}
