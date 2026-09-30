import React, { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import EmptyState from '@/Components/Admin/EmptyState';
import ConfirmModal from '@/Components/Admin/ConfirmModal';
import TableSortButton from '@/Components/Admin/TableSortButton';
import TablePagination from '@/Components/Admin/TablePagination';
import useTableSort from '@/hooks/useTableSort';
import {
    BookOpen,
    Plus,
    Search,
    Eye,
    Edit3,
    PowerOff,
    CheckCircle2,
    AlertCircle,
} from 'lucide-react';

export default function Index({ products, sort, filters, flash }) {
    const [search, setSearch] = useState(filters.search || '');
    const [deactivateProduct, setDeactivateProduct] = useState(null);
    const [isDeactivating, setIsDeactivating] = useState(false);
    const handleSort = useTableSort('simpanan-produk.index');

    const handleSearchSubmit = (e) => {
        e.preventDefault();
        router.get(route('simpanan-produk.index'), { search }, { preserveState: true, replace: true });
    };

    const confirmDeactivate = () => {
        if (!deactivateProduct) return;
        setIsDeactivating(true);

        router.patch(
            route('simpanan-produk.deactivate', deactivateProduct.id),
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
        <AdminLayout title="Produk Simpanan" pageTitle="Produk Simpanan">
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
                        <h2 className="text-lg font-semibold text-slate-900">Produk Simpanan</h2>
                        <p className="mt-0.5 text-sm text-slate-500">
                            {products.total} produk terdaftar
                        </p>
                    </div>
                    <Link
                        href={route('simpanan-produk.create')}
                        className="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white transition-all duration-100 hover:bg-slate-800 active:scale-95"
                    >
                        <Plus className="h-4 w-4" />
                        Tambah Produk
                    </Link>
                </div>

                {/* Search */}
                <form onSubmit={handleSearchSubmit} className="flex gap-2">
                    <div className="relative flex-1">
                        <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                        <input
                            type="text"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Cari kode atau nama produk..."
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

                {/* Table */}
                {products.data.length === 0 ? (
                    <EmptyState
                        icon={BookOpen}
                        title={search ? 'Tidak ada produk yang cocok' : 'Belum ada produk simpanan'}
                        description={
                            search
                                ? 'Coba kata kunci lain atau hapus pencarian.'
                                : 'Tambahkan produk simpanan pertama untuk memulai.'
                        }
                        action={
                            !search && (
                                <Link
                                    href={route('simpanan-produk.create')}
                                    className="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 active:scale-95 transition-all"
                                >
                                    <Plus className="h-4 w-4" />
                                    Tambah Produk
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
                                        <TableSortButton column="code" sort={sort?.key} direction={sort?.direction} onSort={handleSort}>Kode</TableSortButton>
                                    </th>
                                    <th className="px-4 py-3 text-left">
                                        <TableSortButton column="name" sort={sort?.key} direction={sort?.direction} onSort={handleSort}>Nama Produk</TableSortButton>
                                    </th>
                                    <th className="px-4 py-3 text-left">
                                        <TableSortButton column="category" sort={sort?.key} direction={sort?.direction} onSort={handleSort}>Kategori</TableSortButton>
                                    </th>
                                    <th className="hidden md:table-cell px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Deskripsi</th>
                                    <th className="px-4 py-3 text-left">
                                        <TableSortButton column="is_active" sort={sort?.key} direction={sort?.direction} onSort={handleSort}>Status</TableSortButton>
                                    </th>
                                    <th className="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-slate-500">Aksi</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {products.data.map((product) => (
                                    <tr key={product.id} className="hover:bg-slate-50 transition-colors">
                                        <td className="px-4 py-3 text-sm font-mono font-medium text-slate-900">
                                            {product.code}
                                        </td>
                                        <td className="px-4 py-3 text-sm font-medium text-slate-900">
                                            {product.name}
                                        </td>
                                        <td className="px-4 py-3">
                                            <span className="inline-flex items-center rounded-full bg-indigo-50 px-2.5 py-0.5 text-xs font-medium text-indigo-700">
                                                {{ principal: 'Simpanan Pokok', mandatory: 'Simpanan Wajib', voluntary: 'Simpanan Sukarela' }[product.category] ?? product.category}
                                            </span>
                                        </td>
                                        <td className="hidden md:table-cell px-4 py-3 text-sm text-slate-500 max-w-xs truncate">
                                            {product.description || <span className="text-slate-300">-</span>}
                                        </td>
                                        <td className="px-4 py-3">
                                            <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${
                                                product.is_active
                                                    ? 'bg-emerald-100 text-emerald-800'
                                                    : 'bg-slate-100 text-slate-600'
                                            }`}>
                                                {product.is_active ? 'Aktif' : 'Nonaktif'}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3">
                                            <div className="flex items-center justify-end gap-2">
                                                <Link
                                                    href={route('simpanan-produk.edit', product.id)}
                                                    className="rounded p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700 transition-colors"
                                                    title="Edit"
                                                >
                                                    <Edit3 className="h-4 w-4" />
                                                </Link>
                                                {product.is_active && (
                                                    <button
                                                        type="button"
                                                        onClick={() => setDeactivateProduct(product)}
                                                        className="rounded p-1.5 text-slate-400 hover:bg-rose-50 hover:text-rose-600 transition-colors"
                                                        title="Nonaktifkan"
                                                    >
                                                        <PowerOff className="h-4 w-4" />
                                                    </button>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>

                        {/* Pagination */}
                        <TablePagination paginator={products} itemLabel="produk" routeName="simpanan-produk.index" />
                    </div>
                )}
            </div>

            <ConfirmModal
                isOpen={!!deactivateProduct}
                onClose={() => setDeactivateProduct(null)}
                onConfirm={confirmDeactivate}
                isProcessing={isDeactivating}
                title="Nonaktifkan Produk"
                message={`Produk "${deactivateProduct?.name}" akan dinonaktifkan. Rekening lama tetap dapat dilihat, namun transaksi baru pada rekening produk ini akan ditolak.`}
                confirmText="Nonaktifkan"
            />
        </AdminLayout>
    );
}
