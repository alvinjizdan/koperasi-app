import React, { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import EmptyState from '@/Components/Admin/EmptyState';
import CashierSessionCard from '@/Components/Admin/CashierSessionCard';
import {
    ChevronLeft,
    ChevronRight,
    CheckCircle2,
    AlertCircle,
    Receipt,
    Filter,
    PlusCircle,
} from 'lucide-react';
import { formatRupiah, formatDate, formatDateTime } from '@/utils/format';

const TYPE_OPTIONS = [
    { value: 'all', label: 'Semua Jenis' },
    { value: 'INCOME', label: 'Penerimaan' },
    { value: 'EXPENSE', label: 'Pengeluaran' },
    { value: 'DEPOSIT', label: 'Setoran Kasir' },
];

export default function Index({ transactions, filters, category_options, operators, active_session, flash }) {
    const [filterState, setFilterState] = useState({
        date_from: filters.date_from || '',
        date_to: filters.date_to || '',
        type: filters.type || 'all',
        category: filters.category || 'all',
        status: filters.status || 'all',
        operator_id: filters.operator_id || '',
        session_id: filters.session_id || '',
    });

    const applyFilters = (next) => {
        setFilterState(next);
        router.get(route('kasir.transaksi.index'), next, { preserveState: true, replace: true });
    };

    const handleSubmit = (e) => {
        e.preventDefault();
        applyFilters(filterState);
    };

    const handleReset = () => {
        applyFilters({
            date_from: '',
            date_to: '',
            type: 'all',
            category: 'all',
            status: 'all',
            operator_id: '',
            session_id: '',
        });
    };

    return (
        <AdminLayout title="Transaksi Kas" pageTitle="Transaksi Kas">
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

                <CashierSessionCard session={active_session} />

                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h1 className="text-sm font-semibold text-slate-900">Daftar Transaksi Kas</h1>
                    <div className="flex flex-wrap gap-2">
                        <Link
                            href={route('kasir.penerimaan.create')}
                            className="inline-flex items-center gap-1.5 rounded-lg bg-emerald-700 px-3 py-2 text-xs font-semibold text-white transition-all hover:bg-emerald-800 active:scale-95"
                        >
                            <PlusCircle className="h-3.5 w-3.5" />
                            Penerimaan
                        </Link>
                        <Link
                            href={route('kasir.pengeluaran.create')}
                            className="inline-flex items-center gap-1.5 rounded-lg bg-rose-700 px-3 py-2 text-xs font-semibold text-white transition-all hover:bg-rose-800 active:scale-95"
                        >
                            <PlusCircle className="h-3.5 w-3.5" />
                            Pengeluaran
                        </Link>
                        <Link
                            href={route('kasir.setoran.create')}
                            className="inline-flex items-center gap-1.5 rounded-lg bg-blue-700 px-3 py-2 text-xs font-semibold text-white transition-all hover:bg-blue-800 active:scale-95"
                        >
                            <PlusCircle className="h-3.5 w-3.5" />
                            Setoran
                        </Link>
                    </div>
                </div>

                <form
                    onSubmit={handleSubmit}
                    className="grid grid-cols-1 gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-xs sm:grid-cols-2 lg:grid-cols-4"
                >
                    <div>
                        <label className="block text-xs font-medium text-slate-600">Tanggal Mulai</label>
                        <input
                            type="date"
                            value={filterState.date_from}
                            onChange={(e) => setFilterState({ ...filterState, date_from: e.target.value })}
                            className="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"
                        />
                    </div>
                    <div>
                        <label className="block text-xs font-medium text-slate-600">Tanggal Akhir</label>
                        <input
                            type="date"
                            value={filterState.date_to}
                            onChange={(e) => setFilterState({ ...filterState, date_to: e.target.value })}
                            className="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"
                        />
                    </div>
                    <div>
                        <label className="block text-xs font-medium text-slate-600">Jenis Transaksi</label>
                        <select
                            value={filterState.type}
                            onChange={(e) => setFilterState({ ...filterState, type: e.target.value })}
                            className="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"
                        >
                            {TYPE_OPTIONS.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div>
                        <label className="block text-xs font-medium text-slate-600">Kategori</label>
                        <select
                            value={filterState.category}
                            onChange={(e) => setFilterState({ ...filterState, category: e.target.value })}
                            className="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"
                        >
                            <option value="all">Semua Kategori</option>
                            {category_options.map((category) => (
                                <option key={category} value={category}>
                                    {category}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div>
                        <label className="block text-xs font-medium text-slate-600">Operator</label>
                        <select
                            value={filterState.operator_id}
                            onChange={(e) => setFilterState({ ...filterState, operator_id: e.target.value })}
                            className="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"
                        >
                            <option value="">Semua Operator</option>
                            {operators.map((operator) => (
                                <option key={operator.id} value={operator.id}>
                                    {operator.name}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div>
                        <label className="block text-xs font-medium text-slate-600">Status</label>
                        <select
                            value={filterState.status}
                            onChange={(e) => setFilterState({ ...filterState, status: e.target.value })}
                            className="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"
                        >
                            <option value="all">Semua Status</option>
                            <option value="POSTED">Berlaku</option>
                            <option value="REVERSED">Dibatalkan</option>
                        </select>
                    </div>
                    <div className="flex items-end gap-2 sm:col-span-2">
                        <button
                            type="submit"
                            className="inline-flex items-center gap-1.5 rounded-lg bg-slate-800 px-3 py-2 text-xs font-semibold text-white transition-all hover:bg-slate-900 active:scale-95"
                        >
                            <Filter className="h-3.5 w-3.5" />
                            Terapkan Filter
                        </button>
                        <button
                            type="button"
                            onClick={handleReset}
                            className="rounded-lg border border-slate-300 px-3 py-2 text-xs font-medium text-slate-700 transition-colors hover:bg-slate-50"
                        >
                            Reset
                        </button>
                    </div>
                </form>

                {transactions.data.length === 0 ? (
                    <EmptyState
                        icon={Receipt}
                        title="Belum ada transaksi kas"
                        description="Transaksi penerimaan, pengeluaran, dan setoran akan tercatat di sini."
                    />
                ) : (
                    <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xs">
                        <div className="overflow-x-auto">
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
                                        <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">
                                            Operator
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
                                                <p>{formatDate(transaction.transaction_date)}</p>
                                                <p className="text-[11px] text-slate-400">
                                                    {formatDateTime(transaction.transacted_at)}
                                                </p>
                                            </td>
                                            <td className="px-4 py-3 text-sm text-slate-700">
                                                {transaction.type_label}
                                            </td>
                                            <td className="px-4 py-3 text-sm text-slate-600">
                                                {transaction.category || '-'}
                                            </td>
                                            <td className="px-4 py-3 text-sm text-slate-600">
                                                {transaction.operator?.name || '-'}
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
                        </div>

                        {transactions.last_page > 1 && (
                            <div className="flex items-center justify-between border-t border-slate-200 px-4 py-3">
                                <p className="text-sm text-slate-500">
                                    Menampilkan {transactions.from}–{transactions.to} dari {transactions.total}{' '}
                                    transaksi
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
                    </div>
                )}
            </div>
        </AdminLayout>
    );
}
