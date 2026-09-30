import React, { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import EmptyState from '@/Components/Admin/EmptyState';
import TablePagination from '@/Components/Admin/TablePagination';
import { Combobox, ComboboxContent, ComboboxEmpty, ComboboxInput, ComboboxItem, ComboboxList } from '@/Components/ui/combobox';
import {
    PiggyBank,
    ChevronLeft,
    TrendingUp,
    TrendingDown,
    CheckCircle2,
    AlertCircle,
    ArrowDownCircle,
    ArrowUpCircle,
    Receipt,
} from 'lucide-react';
import { formatRupiah, formatDate, formatDateTime } from '@/utils/format';

const TYPE_OPTIONS = [
    { value: 'all', label: 'Semua Jenis' },
    { value: 'deposit', label: 'Setoran' },
    { value: 'withdrawal', label: 'Penarikan' },
];

export default function Show({ account, transactions, filters, flash }) {
    const [dateFrom, setDateFrom] = useState(filters.date_from || '');
    const [dateTo, setDateTo] = useState(filters.date_to || '');
    const [type, setType] = useState(filters.type || 'all');
    const [search, setSearch] = useState(filters.search || '');

    const handleFilterSubmit = (e) => {
        e.preventDefault();
        router.get(
            route('simpanan-rekening.show', account.id),
            { type, date_from: dateFrom, date_to: dateTo, search },
            { preserveState: true, replace: true }
        );
    };

    const handleTypeChange = (newType) => {
        if (newType == null) newType = 'all';
        setType(newType);
        router.get(
            route('simpanan-rekening.show', account.id),
            { type: newType, date_from: dateFrom, date_to: dateTo, search },
            { preserveState: true, replace: true }
        );
    };

    return (
        <AdminLayout title={`Rekening ${account.account_number}`} pageTitle="Detail Rekening Simpanan">
            <div className="space-y-6">
                <div className="flex items-center justify-between gap-4">
                    <Link
                        href={route('simpanan-rekening.index')}
                        className="inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-slate-800 transition-colors"
                    >
                        <ChevronLeft className="h-4 w-4" />
                        Daftar rekening
                    </Link>
                    {account.status === 'active' && (
                        <div className="flex gap-2">
                            <Link
                                href={route('simpanan-transaksi.deposit.create', account.id)}
                                className="inline-flex items-center gap-2 rounded-lg border border-emerald-300 bg-emerald-50 px-3 py-1.5 text-sm font-medium text-emerald-800 hover:bg-emerald-100 active:scale-95 transition-all"
                            >
                                <ArrowDownCircle className="h-4 w-4" />
                                Setoran
                            </Link>
                            <Link
                                href={route('simpanan-transaksi.withdraw.create', account.id)}
                                className="inline-flex items-center gap-2 rounded-lg border border-rose-300 bg-rose-50 px-3 py-1.5 text-sm font-medium text-rose-800 hover:bg-rose-100 active:scale-95 transition-all"
                            >
                                <ArrowUpCircle className="h-4 w-4" />
                                Penarikan
                            </Link>
                        </div>
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

                {/* Account summary */}
                <div className="rounded-xl border border-slate-200 bg-white shadow-xs overflow-hidden">
                    <div className="border-b border-slate-100 px-6 py-4 bg-slate-50">
                        <div className="flex items-center gap-3">
                            <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-emerald-100 text-emerald-700">
                                <PiggyBank className="h-5 w-5" />
                            </div>
                            <div>
                                <p className="font-mono text-sm font-semibold text-slate-900">{account.account_number}</p>
                                <p className="text-xs text-slate-500">{account.saving_product?.name}</p>
                            </div>
                            <div className="ml-auto">
                                <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${
                                    account.status === 'active'
                                        ? 'bg-emerald-100 text-emerald-800'
                                        : 'bg-slate-100 text-slate-600'
                                }`}>
                                    {account.status === 'active' ? 'Aktif' : 'Ditutup'}
                                </span>
                            </div>
                        </div>
                    </div>

                    <div className="grid grid-cols-2 divide-x divide-slate-100 sm:grid-cols-4">
                        <div className="px-5 py-4">
                            <p className="text-xs text-slate-500">Nama Anggota</p>
                            <p className="mt-0.5 text-sm font-semibold text-slate-900">{account.member?.full_name}</p>
                        </div>
                        <div className="px-5 py-4">
                            <p className="text-xs text-slate-500">Nomor Anggota</p>
                            <p className="mt-0.5 text-sm font-mono font-medium text-slate-900">{account.member?.member_number}</p>
                        </div>
                        <div className="px-5 py-4">
                            <p className="text-xs text-slate-500">Dibuka</p>
                            <p className="mt-0.5 text-sm font-medium text-slate-900">{formatDate(account.opened_at)}</p>
                        </div>
                        <div className="px-5 py-4">
                            <p className="text-xs text-slate-500">Saldo</p>
                            <p className="mt-0.5 text-base font-bold tabular-nums text-slate-900">
                                {formatRupiah(account.balance)}
                            </p>
                        </div>
                    </div>
                </div>

                {/* Transaction history */}
                <div>
                    <div className="mb-3 flex items-center justify-between">
                        <h3 className="text-sm font-semibold text-slate-700">Riwayat Transaksi</h3>
                        <span className="text-xs text-slate-400">{transactions.total} transaksi</span>
                    </div>

                    {/* Filters */}
                    <form onSubmit={handleFilterSubmit} className="mb-4 flex flex-wrap gap-2">
                        <div className="relative">
                            <input
                                type="text"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Cari no. transaksi..."
                                className="rounded-lg border border-slate-300 bg-white py-1.5 pl-3 pr-3 text-sm text-slate-900 placeholder-slate-400 focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"
                            />
                        </div>
                        <Combobox
                            items={TYPE_OPTIONS.map((o) => o.value)}
                            value={type}
                            onValueChange={handleTypeChange}
                            itemToStringValue={(v) => TYPE_OPTIONS.find((o) => o.value === v)?.label ?? v}
                        >
                            <ComboboxInput aria-label="Filter jenis transaksi" />
                            <ComboboxContent>
                                <ComboboxEmpty>Tidak ada jenis.</ComboboxEmpty>
                                <ComboboxList>
                                    {(v) => (
                                        <ComboboxItem key={v} value={v}>
                                            {TYPE_OPTIONS.find((o) => o.value === v)?.label}
                                        </ComboboxItem>
                                    )}
                                </ComboboxList>
                            </ComboboxContent>
                        </Combobox>
                        <input
                            type="date"
                            value={dateFrom}
                            onChange={(e) => setDateFrom(e.target.value)}
                            className="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm text-slate-700 focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"
                        />
                        <input
                            type="date"
                            value={dateTo}
                            onChange={(e) => setDateTo(e.target.value)}
                            className="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm text-slate-700 focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"
                        />
                        <button
                            type="submit"
                            className="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50 active:scale-95 transition-all"
                        >
                            Filter
                        </button>
                    </form>

                    {transactions.data.length === 0 ? (
                        <EmptyState
                            icon={Receipt}
                            title="Belum ada transaksi"
                            description={
                                type !== 'all' || dateFrom || dateTo
                                    ? 'Tidak ada transaksi yang cocok dengan filter yang dipilih.'
                                    : 'Rekening ini belum memiliki transaksi. Catat setoran pertama.'
                            }
                        />
                    ) : (
                        <div className="rounded-xl border border-slate-200 bg-white shadow-xs overflow-hidden">
                            <table className="min-w-full divide-y divide-slate-200">
                                <thead className="bg-slate-50">
                                    <tr>
                                        <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">No. Transaksi</th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Tanggal</th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Jenis</th>
                                        <th className="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-slate-500">Nominal</th>
                                        <th className="hidden md:table-cell px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Keterangan</th>
                                        <th className="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-slate-500">Detail</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {transactions.data.map((trx) => (
                                        <tr key={trx.id} className="hover:bg-slate-50 transition-colors">
                                            <td className="px-4 py-3 text-xs font-mono text-slate-700">
                                                {trx.transaction_number}
                                            </td>
                                            <td className="px-4 py-3 text-sm text-slate-600">
                                                {formatDate(trx.transaction_date)}
                                            </td>
                                            <td className="px-4 py-3">
                                                <span className={`inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium ${
                                                    trx.type === 'deposit'
                                                        ? 'bg-emerald-100 text-emerald-800'
                                                        : 'bg-rose-100 text-rose-800'
                                                }`}>
                                                    {trx.type === 'deposit'
                                                        ? <TrendingUp className="h-3 w-3" />
                                                        : <TrendingDown className="h-3 w-3" />}
                                                    {trx.type === 'deposit' ? 'Setoran' : 'Penarikan'}
                                                </span>
                                            </td>
                                            <td className={`px-4 py-3 text-right text-sm font-semibold tabular-nums ${
                                                trx.type === 'deposit' ? 'text-emerald-700' : 'text-rose-700'
                                            }`}>
                                                {trx.type === 'withdrawal' ? '-' : '+'}{formatRupiah(trx.amount)}
                                            </td>
                                            <td className="hidden md:table-cell px-4 py-3 text-sm text-slate-500 max-w-xs truncate">
                                                {trx.description || <span className="text-slate-300">-</span>}
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                <Link
                                                    href={route('simpanan-transaksi.show', trx.id)}
                                                    className="text-xs text-slate-400 hover:text-slate-700 underline"
                                                >
                                                    Lihat
                                                </Link>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>

                            <TablePagination
                                paginator={transactions}
                                itemLabel="transaksi"
                                routeName="simpanan-rekening.show"
                                routeParams={[account.id]}
                            />
                        </div>
                    )}
                </div>
            </div>
        </AdminLayout>
    );
}
