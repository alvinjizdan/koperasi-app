import React, { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import EmptyState from '@/Components/Admin/EmptyState';
import TableSortButton from '@/Components/Admin/TableSortButton';
import TablePagination from '@/Components/Admin/TablePagination';
import useTableSort from '@/hooks/useTableSort';
import { Combobox, ComboboxContent, ComboboxEmpty, ComboboxInput, ComboboxItem, ComboboxList } from '@/Components/ui/combobox';
import { PiggyBank, Plus, Search, Eye, CheckCircle2, AlertCircle } from 'lucide-react';
import { formatRupiah } from '@/utils/format';

const ACCOUNT_STATUS_OPTIONS = [
    { value: 'all', label: 'Semua Status' },
    { value: 'active', label: 'Aktif' },
    { value: 'closed', label: 'Ditutup' },
];

export default function Index({ accounts, sort, filters, flash }) {
    const [search, setSearch] = useState(filters.search || '');
    const [status, setStatus] = useState(filters.status || 'all');
    const handleSort = useTableSort('simpanan-rekening.index');

    const handleSearchSubmit = (e) => {
        e.preventDefault();
        router.get(route('simpanan-rekening.index'), { search, status }, { preserveState: true, replace: true });
    };

    const handleStatusChange = (e) => {
        const newStatus = e?.target?.value ?? e ?? 'all';
        setStatus(newStatus);
        router.get(route('simpanan-rekening.index'), { search, status: newStatus }, { preserveState: true, replace: true });
    };

    return (
        <AdminLayout title="Rekening Simpanan" pageTitle="Rekening Simpanan">
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

                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 className="text-lg font-semibold text-slate-900">Rekening Simpanan</h2>
                        <p className="mt-0.5 text-sm text-slate-500">{accounts.total} rekening terdaftar</p>
                    </div>
                    <Link
                        href={route('simpanan-rekening.create')}
                        className="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white transition-all duration-100 hover:bg-slate-800 active:scale-95"
                    >
                        <Plus className="h-4 w-4" />
                        Buka Rekening
                    </Link>
                </div>

                {/* Filter bar */}
                <div className="flex flex-col gap-2 sm:flex-row">
                    <form onSubmit={handleSearchSubmit} className="flex flex-1 gap-2">
                        <div className="relative flex-1">
                            <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                            <input
                                type="text"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Cari nomor rekening atau nama anggota..."
                                className="w-full rounded-lg border border-slate-300 bg-white py-2 pl-9 pr-3 text-sm text-slate-900 placeholder-slate-400 focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"
                            />
                        </div>
                        <button
                            type="submit"
                            className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 active:scale-95 transition-all"
                        >
                            Cari
                        </button>
                    </form>
                    <Combobox
                        items={ACCOUNT_STATUS_OPTIONS.map((o) => o.value)}
                        value={status}
                        onValueChange={handleStatusChange}
                        itemToStringValue={(v) => ACCOUNT_STATUS_OPTIONS.find((o) => o.value === v)?.label ?? v}
                    >
                        <ComboboxInput aria-label="Filter status rekening" />
                        <ComboboxContent>
                            <ComboboxEmpty>Tidak ada status.</ComboboxEmpty>
                            <ComboboxList>
                                {(v) => (
                                    <ComboboxItem key={v} value={v}>
                                        {ACCOUNT_STATUS_OPTIONS.find((o) => o.value === v)?.label}
                                    </ComboboxItem>
                                )}
                            </ComboboxList>
                        </ComboboxContent>
                    </Combobox>
                </div>

                {accounts.data.length === 0 ? (
                    <EmptyState
                        icon={PiggyBank}
                        title={search ? 'Tidak ada rekening yang cocok' : 'Belum ada rekening simpanan'}
                        description={
                            search
                                ? 'Coba kata kunci lain atau ubah filter.'
                                : 'Buka rekening simpanan pertama untuk anggota.'
                        }
                        action={
                            !search && (
                                <Link
                                    href={route('simpanan-rekening.create')}
                                    className="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 active:scale-95 transition-all"
                                >
                                    <Plus className="h-4 w-4" />
                                    Buka Rekening
                                </Link>
                            )
                        }
                    />
                ) : (
                    <div className="rounded-xl border border-slate-200 bg-white shadow-xs overflow-hidden">
                        <table className="min-w-full divide-y divide-slate-200">
                            <thead className="bg-slate-50">
                                <tr>
                                    <th className="px-4 py-3 text-left">
                                        <TableSortButton column="account_number" sort={sort?.key} direction={sort?.direction} onSort={handleSort}>No. Rekening</TableSortButton>
                                    </th>
                                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Anggota</th>
                                    <th className="hidden md:table-cell px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Produk</th>
                                    <th className="px-4 py-3 text-right">
                                        <TableSortButton column="balance" sort={sort?.key} direction={sort?.direction} onSort={handleSort}>Saldo</TableSortButton>
                                    </th>
                                    <th className="px-4 py-3 text-left">
                                        <TableSortButton column="status" sort={sort?.key} direction={sort?.direction} onSort={handleSort}>Status</TableSortButton>
                                    </th>
                                    <th className="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-slate-500">Aksi</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {accounts.data.map((account) => (
                                    <tr key={account.id} className="hover:bg-slate-50 transition-colors">
                                        <td className="px-4 py-3 text-sm font-mono font-medium text-slate-900">
                                            {account.account_number}
                                        </td>
                                        <td className="px-4 py-3">
                                            <p className="text-sm font-medium text-slate-900">{account.member?.full_name}</p>
                                            <p className="text-xs text-slate-400">{account.member?.member_number}</p>
                                        </td>
                                        <td className="hidden md:table-cell px-4 py-3 text-sm text-slate-600">
                                            {account.saving_product?.name}
                                        </td>
                                        <td className="px-4 py-3 text-right text-sm font-semibold tabular-nums text-slate-900">
                                            {formatRupiah(account.balance)}
                                        </td>
                                        <td className="px-4 py-3">
                                            <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${
                                                account.status === 'active'
                                                    ? 'bg-emerald-100 text-emerald-800'
                                                    : 'bg-slate-100 text-slate-600'
                                            }`}>
                                                {account.status === 'active' ? 'Aktif' : 'Ditutup'}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            <Link
                                                href={route('simpanan-rekening.show', account.id)}
                                                className="rounded p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700 transition-colors inline-flex"
                                                title="Lihat detail"
                                            >
                                                <Eye className="h-4 w-4" />
                                            </Link>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>

                        <TablePagination paginator={accounts} itemLabel="rekening" routeName="simpanan-rekening.index" />
                    </div>
                )}
            </div>
        </AdminLayout>
    );
}
