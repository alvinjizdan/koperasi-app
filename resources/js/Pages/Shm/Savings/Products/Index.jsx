import React, { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import EmptyState from '@/Components/Admin/EmptyState';
import ConfirmModal from '@/Components/Admin/ConfirmModal';
import TableSortButton from '@/Components/Admin/TableSortButton';
import TablePagination from '@/Components/Admin/TablePagination';
import useTableSort from '@/hooks/useTableSort';
import {
    Wallet,
    Plus,
    Search,
    Edit3,
    PowerOff,
    CheckCircle2,
    AlertCircle,
} from 'lucide-react';

const CATEGORY_LABELS = {
    principal: 'Simpanan Pokok',
    mandatory: 'Simpanan Wajib',
    voluntary: 'Simpanan Sukarela',
};

const CATEGORY_BADGES = {
    principal: 'bg-indigo-50 text-indigo-700 border-indigo-200',
    mandatory: 'bg-amber-50 text-amber-700 border-amber-200',
    voluntary: 'bg-emerald-50 text-emerald-700 border-emerald-200',
};

export default function Index({ products, sort, filters, flash }) {
    const [search, setSearch] = useState(filters.search || '');
    const [deactivateProduct, setDeactivateProduct] = useState(null);
    const [isDeactivating, setIsDeactivating] = useState(false);
    const handleSort = useTableSort('shm.savings.products.index');

    const handleSearchSubmit = (e) => {
        e.preventDefault();
        router.get(route('shm.savings.products.index'), { search }, { preserveState: true, replace: true });
    };

    const confirmDeactivate = () => {
        if (!deactivateProduct) return;
        setIsDeactivating(true);

        router.patch(
            route('shm.savings.products.deactivate', deactivateProduct.id),
            {},
            {
                onSuccess: () => {
                    setDeactivateProduct(null);
                    setIsDeactivating(false);
                },
                onError: () => {
                    setIsDeactivating(false);
                },
                onFinish: () => {
                    setIsDeactivating(false);
                },
            }
        );
    };

    return (
        <ShmLayout title="Produk Simpanan BSM Makro" pageTitle="Produk Simpanan SHM">
            <div className="space-y-6">
                {/* Flash messages */}
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

                {/* Header */}
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 className="text-lg font-semibold text-slate-900">Produk Simpanan SHM</h2>
                        <p className="mt-0.5 text-sm text-slate-500">
                            {products.total} produk terdaftar pada domain BSM Makro (SHM)
                        </p>
                    </div>
                    <Link
                        href={route('shm.savings.products.create')}
                        className="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white transition-all duration-100 hover:bg-slate-800 active:scale-95"
                    >
                        <Plus className="h-4 w-4" />
                        Tambah Produk SHM
                    </Link>
                </div>

                {/* Search */}
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <form onSubmit={handleSearchSubmit} className="relative w-full sm:w-72">
                        <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                        <input
                            type="text"
                            placeholder="Cari kode atau nama..."
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            className="w-full rounded-lg border border-slate-200 bg-white py-2 pl-9 pr-4 text-sm text-slate-800 placeholder-slate-400 transition-all focus:border-slate-400 focus:outline-none focus:ring-2 focus:ring-slate-100"
                        />
                    </form>
                </div>

                {/* Table */}
                <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xs">
                    <div className="overflow-x-auto">
                        <table className="w-full border-collapse text-left text-sm">
                            <thead>
                                <tr className="border-b border-slate-200 bg-slate-50/75">
                                    <th className="px-4 py-3 font-medium text-slate-600">
                                        <TableSortButton
                                            label="Kode"
                                            column="code"
                                            currentColumn={sort.column}
                                            direction={sort.direction}
                                            onSort={handleSort}
                                        />
                                    </th>
                                    <th className="px-4 py-3 font-medium text-slate-600">
                                        <TableSortButton
                                            label="Nama Produk"
                                            column="name"
                                            currentColumn={sort.column}
                                            direction={sort.direction}
                                            onSort={handleSort}
                                        />
                                    </th>
                                    <th className="px-4 py-3 font-medium text-slate-600">
                                        <TableSortButton
                                            label="Kategori"
                                            column="category"
                                            currentColumn={sort.column}
                                            direction={sort.direction}
                                            onSort={handleSort}
                                        />
                                    </th>
                                    <th className="px-4 py-3 font-medium text-slate-600">
                                        <TableSortButton
                                            label="Status"
                                            column="is_active"
                                            currentColumn={sort.column}
                                            direction={sort.direction}
                                            onSort={handleSort}
                                        />
                                    </th>
                                    <th className="px-4 py-3 font-medium text-slate-600">
                                        <TableSortButton
                                            label="Dibuat"
                                            column="created_at"
                                            currentColumn={sort.column}
                                            direction={sort.direction}
                                            onSort={handleSort}
                                        />
                                    </th>
                                    <th className="px-4 py-3 text-right font-medium text-slate-600">Aksi</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {products.data.length === 0 ? (
                                    <tr>
                                        <td colSpan={6} className="py-12">
                                            <EmptyState
                                                icon={Wallet}
                                                title="Tidak ada produk simpanan SHM"
                                                description={
                                                    search
                                                        ? 'Tidak ditemukan produk yang cocok dengan pencarian Anda.'
                                                        : 'Belum ada produk simpanan SHM yang terdaftar.'
                                                }
                                                action={
                                                    !search && (
                                                        <Link
                                                            href={route('shm.savings.products.create')}
                                                            className="inline-flex items-center gap-1.5 rounded-lg bg-slate-900 px-3.5 py-2 text-xs font-medium text-white transition-all hover:bg-slate-800"
                                                        >
                                                            <Plus className="h-3.5 w-3.5" />
                                                            Tambah Produk
                                                        </Link>
                                                    )
                                                }
                                            />
                                        </td>
                                    </tr>
                                ) : (
                                    products.data.map((product) => (
                                        <tr key={product.id} className="transition-colors hover:bg-slate-50/50">
                                            <td className="px-4 py-3 font-mono font-medium text-slate-800">
                                                {product.code}
                                            </td>
                                            <td className="px-4 py-3">
                                                <div className="font-medium text-slate-900">{product.name}</div>
                                                {product.description && (
                                                    <div className="line-clamp-1 text-xs text-slate-400">
                                                        {product.description}
                                                    </div>
                                                )}
                                            </td>
                                            <td className="px-4 py-3">
                                                <span
                                                    className={`inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium ${
                                                        CATEGORY_BADGES[product.category] || 'bg-slate-100 text-slate-600'
                                                    }`}
                                                >
                                                    {CATEGORY_LABELS[product.category] || product.category}
                                                </span>
                                            </td>
                                            <td className="px-4 py-3">
                                                {product.is_active ? (
                                                    <span className="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700">
                                                        <span className="h-1.5 w-1.5 rounded-full bg-emerald-500" />
                                                        Aktif
                                                    </span>
                                                ) : (
                                                    <span className="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-500">
                                                        <span className="h-1.5 w-1.5 rounded-full bg-slate-400" />
                                                        Nonaktif
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-4 py-3 text-slate-500">
                                                {new Date(product.created_at).toLocaleDateString('id-ID', {
                                                    day: 'numeric',
                                                    month: 'short',
                                                    year: 'numeric',
                                                })}
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                <div className="flex items-center justify-end gap-1">
                                                    <Link
                                                        href={route('shm.savings.products.edit', product.id)}
                                                        className="inline-flex h-8 w-8 items-center justify-center rounded-lg text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-700"
                                                        title="Edit Produk"
                                                    >
                                                        <Edit3 className="h-4 w-4" />
                                                    </Link>
                                                    {product.is_active && (
                                                        <button
                                                            type="button"
                                                            onClick={() => setDeactivateProduct(product)}
                                                            className="inline-flex h-8 w-8 items-center justify-center rounded-lg text-rose-400 transition-colors hover:bg-rose-50 hover:text-rose-600"
                                                            title="Nonaktifkan Produk"
                                                        >
                                                            <PowerOff className="h-4 w-4" />
                                                        </button>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>

                    {products.total > products.per_page && (
                        <div className="border-t border-slate-200 px-4 py-3">
                            <TablePagination
                                currentPage={products.current_page}
                                lastPage={products.last_page}
                                total={products.total}
                                perPage={products.per_page}
                                from={products.from}
                                to={products.to}
                                onPageChange={(page) => {
                                    router.get(
                                        route('shm.savings.products.index'),
                                        { ...filters, page },
                                        { preserveState: true }
                                    );
                                }}
                            />
                        </div>
                    )}
                </div>
            </div>

            {/* Deactivate confirmation modal */}
            <ConfirmModal
                isOpen={!!deactivateProduct}
                title="Nonaktifkan Produk Simpanan SHM?"
                message={`Produk "${deactivateProduct?.name}" akan dinonaktifkan. Rekening yang sudah ada tetap dapat dilihat, namun pembukaan rekening baru dan mutasi transaksi baru untuk produk ini akan ditolak.`}
                confirmLabel="Ya, Nonaktifkan"
                cancelLabel="Batal"
                variant="danger"
                isLoading={isDeactivating}
                onConfirm={confirmDeactivate}
                onClose={() => setDeactivateProduct(null)}
            />
        </ShmLayout>
    );
}

