import React, { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import EmptyState from '@/Components/Admin/EmptyState';
import TableSortButton from '@/Components/Admin/TableSortButton';
import TablePagination from '@/Components/Admin/TablePagination';
import useTableSort from '@/hooks/useTableSort';
import { Wallet, Plus, Search, Eye, CheckCircle2, AlertCircle } from 'lucide-react';
import { formatRupiah } from '@/utils/format';

const ACCOUNT_STATUS_OPTIONS = [
    { value: 'all', label: 'Semua Status' },
    { value: 'active', label: 'Aktif' },
    { value: 'closed', label: 'Ditutup' },
];

export default function Index({ accounts, products, sort, filters, flash }) {
    const [search, setSearch] = useState(filters.search || '');
    const [status, setStatus] = useState(filters.status || 'all');
    const [productId, setProductId] = useState(filters.product_id || '');
    const handleSort = useTableSort('shm.savings.accounts.index');

    const handleSearchSubmit = (e) => {
        e.preventDefault();
        router.get(
            route('shm.savings.accounts.index'),
            { search, status, product_id: productId },
            { preserveState: true, replace: true }
        );
    };

    const handleStatusChange = (newStatus) => {
        setStatus(newStatus);
        router.get(
            route('shm.savings.accounts.index'),
            { search, status: newStatus, product_id: productId },
            { preserveState: true, replace: true }
        );
    };

    const handleProductChange = (newProductId) => {
        setProductId(newProductId);
        router.get(
            route('shm.savings.accounts.index'),
            { search, status, product_id: newProductId },
            { preserveState: true, replace: true }
        );
    };

    return (
        <ShmLayout title="Rekening Simpanan BSM Makro" pageTitle="Rekening Simpanan SHM">
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
                        <h2 className="text-lg font-semibold text-slate-900">Rekening Simpanan SHM</h2>
                        <p className="mt-0.5 text-sm text-slate-500">{accounts.total} rekening terdaftar (BSM Makro)</p>
                    </div>
                    <Link
                        href={route('shm.savings.accounts.create')}
                        className="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white transition-all duration-100 hover:bg-slate-800 active:scale-95"
                    >
                        <Plus className="h-4 w-4" />
                        Buka Rekening SHM
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
                                placeholder="Cari nomor rekening atau nama nasabah SHM..."
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

                    <div className="flex items-center gap-2">
                        {/* Status Filter */}
                        <select
                            value={status}
                            onChange={(e) => handleStatusChange(e.target.value)}
                            className="rounded-lg border border-slate-300 bg-white py-2 px-3 text-sm text-slate-700 focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"
                        >
                            {ACCOUNT_STATUS_OPTIONS.map((opt) => (
                                <option key={opt.value} value={opt.value}>
                                    {opt.label}
                                </option>
                            ))}
                        </select>

                        {/* Product Filter */}
                        <select
                            value={productId}
                            onChange={(e) => handleProductChange(e.target.value)}
                            className="rounded-lg border border-slate-300 bg-white py-2 px-3 text-sm text-slate-700 focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"
                        >
                            <option value="">Semua Produk</option>
                            {products.map((p) => (
                                <option key={p.id} value={p.id}>
                                    {p.name}
                                </option>
                            ))}
                        </select>
                    </div>
                </div>

                {/* Table */}
                <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xs">
                    <div className="overflow-x-auto">
                        <table className="w-full border-collapse text-left text-sm">
                            <thead>
                                <tr className="border-b border-slate-200 bg-slate-50/75">
                                    <th className="px-4 py-3 font-medium text-slate-600">
                                        <TableSortButton
                                            label="No. Rekening"
                                            column="account_number"
                                            currentColumn={sort.column}
                                            direction={sort.direction}
                                            onSort={handleSort}
                                        />
                                    </th>
                                    <th className="px-4 py-3 font-medium text-slate-600">Nasabah SHM</th>
                                    <th className="px-4 py-3 font-medium text-slate-600">Produk</th>
                                    <th className="px-4 py-3 font-medium text-slate-600">
                                        <TableSortButton
                                            label="Saldo"
                                            column="balance"
                                            currentColumn={sort.column}
                                            direction={sort.direction}
                                            onSort={handleSort}
                                        />
                                    </th>
                                    <th className="px-4 py-3 font-medium text-slate-600">
                                        <TableSortButton
                                            label="Status"
                                            column="status"
                                            currentColumn={sort.column}
                                            direction={sort.direction}
                                            onSort={handleSort}
                                        />
                                    </th>
                                    <th className="px-4 py-3 font-medium text-slate-600">
                                        <TableSortButton
                                            label="Tgl Buka"
                                            column="opened_at"
                                            currentColumn={sort.column}
                                            direction={sort.direction}
                                            onSort={handleSort}
                                        />
                                    </th>
                                    <th className="px-4 py-3 text-right font-medium text-slate-600">Aksi</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {accounts.data.length === 0 ? (
                                    <tr>
                                        <td colSpan={7} className="py-12">
                                            <EmptyState
                                                icon={Wallet}
                                                title="Tidak ada rekening simpanan SHM"
                                                description={
                                                    search || status !== 'all' || productId
                                                        ? 'Tidak ditemukan rekening simpanan SHM yang sesuai kriteria.'
                                                        : 'Belum ada rekening simpanan SHM yang terdaftar.'
                                                }
                                                action={
                                                    !search && (
                                                        <Link
                                                            href={route('shm.savings.accounts.create')}
                                                            className="inline-flex items-center gap-1.5 rounded-lg bg-slate-900 px-3.5 py-2 text-xs font-medium text-white transition-all hover:bg-slate-800"
                                                        >
                                                            <Plus className="h-3.5 w-3.5" />
                                                            Buka Rekening SHM
                                                        </Link>
                                                    )
                                                }
                                            />
                                        </td>
                                    </tr>
                                ) : (
                                    accounts.data.map((account) => (
                                        <tr key={account.id} className="transition-colors hover:bg-slate-50/50">
                                            <td className="px-4 py-3 font-mono font-medium text-slate-900">
                                                {account.account_number}
                                            </td>
                                            <td className="px-4 py-3">
                                                <div className="font-medium text-slate-900">
                                                    {account.customer?.name ?? '—'}
                                                </div>
                                                <div className="text-xs text-slate-400 font-mono">
                                                    {account.customer?.customer_number ?? '—'}
                                                </div>
                                            </td>
                                            <td className="px-4 py-3">
                                                <div className="text-slate-800 font-medium">
                                                    {account.saving_product?.name ?? '—'}
                                                </div>
                                                <div className="text-xs text-slate-400 font-mono">
                                                    {account.saving_product?.code}
                                                </div>
                                            </td>
                                            <td className="px-4 py-3 font-mono font-semibold text-slate-900">
                                                {formatRupiah(account.balance)}
                                            </td>
                                            <td className="px-4 py-3">
                                                {account.status === 'active' ? (
                                                    <span className="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700">
                                                        <span className="h-1.5 w-1.5 rounded-full bg-emerald-500" />
                                                        Aktif
                                                    </span>
                                                ) : (
                                                    <span className="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-500">
                                                        <span className="h-1.5 w-1.5 rounded-full bg-slate-400" />
                                                        Ditutup
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-4 py-3 text-slate-500">
                                                {account.opened_at
                                                    ? new Date(account.opened_at).toLocaleDateString('id-ID', {
                                                          day: 'numeric',
                                                          month: 'short',
                                                          year: 'numeric',
                                                      })
                                                    : '—'}
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                <Link
                                                    href={route('shm.savings.accounts.show', account.id)}
                                                    className="inline-flex h-8 w-8 items-center justify-center rounded-lg text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-700"
                                                    title="Lihat Detail & Mutasi"
                                                >
                                                    <Eye className="h-4 w-4" />
                                                </Link>
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>

                    {accounts.total > accounts.per_page && (
                        <div className="border-t border-slate-200 px-4 py-3">
                            <TablePagination
                                currentPage={accounts.current_page}
                                lastPage={accounts.last_page}
                                total={accounts.total}
                                perPage={accounts.per_page}
                                from={accounts.from}
                                to={accounts.to}
                                onPageChange={(page) => {
                                    router.get(
                                        route('shm.savings.accounts.index'),
                                        { ...filters, page },
                                        { preserveState: true }
                                    );
                                }}
                            />
                        </div>
                    )}
                </div>
            </div>
        </ShmLayout>
    );
}

