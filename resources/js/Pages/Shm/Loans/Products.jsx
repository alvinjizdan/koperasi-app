import React, { useState } from 'react';
import { router } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import ReportTable from '@/Components/Admin/ReportTable';
import PrimaryButton from '@/Components/PrimaryButton';
import useTableSort from '@/hooks/useTableSort';
import { formatDate } from '@/utils/format';
import { Search, Landmark } from 'lucide-react';

/**
 * Daftar produk Pinjaman SHM (Ticket 64).
 *
 * Master ini TERPISAH dari produk pinjaman koperasi. Tidak ada kolom rate
 * default: rate resmi SHM adalah 2%, tetapi periodisasi (per bulan/per tahun)
 * dan formula angsuran masih PENDING BUSINESS CONFIRMATION sehingga tidak
 * dikunci di master. Halaman bersifat baca.
 */
export default function Products({ products, sort, filters }) {
    const handleSort = useTableSort('shm.loans.products.index');

    const [search, setSearch] = useState(filters.search || '');
    const [status, setStatus] = useState(filters.status || 'all');

    const applyFilters = (event) => {
        event.preventDefault();
        router.get(
            route('shm.loans.products.index'),
            {
                search: search || undefined,
                status: status !== 'all' ? status : undefined,
            },
            { preserveState: true, replace: true },
        );
    };

    const columns = [
        { key: 'code', label: 'Kode', sortable: true },
        { key: 'name', label: 'Nama Produk', sortable: true },
        {
            key: 'description',
            label: 'Keterangan',
            render: (row) => (
                <span className="line-clamp-1 max-w-xs">{row.description || '-'}</span>
            ),
        },
        {
            key: 'loans_count',
            label: 'Jml. Pinjaman',
            align: 'right',
            render: (row) => row.loans_count ?? 0,
        },
        {
            key: 'is_active',
            label: 'Status',
            sortable: true,
            render: (row) => (
                <span
                    className={`rounded-full px-2 py-1 text-xs font-medium ${
                        row.is_active
                            ? 'bg-emerald-50 text-emerald-700'
                            : 'bg-slate-100 text-slate-600'
                    }`}
                >
                    {row.is_active ? 'Aktif' : 'Nonaktif'}
                </span>
            ),
        },
        {
            key: 'created_at',
            label: 'Dibuat',
            sortable: true,
            render: (row) => formatDate(row.created_at),
        },
    ];

    return (
        <ShmLayout title="Produk Pinjaman SHM">
            <div className="space-y-6">
                <div>
                    <h1 className="flex items-center gap-2 text-xl font-semibold text-gray-800">
                        <Landmark className="h-5 w-5 text-slate-400" />
                        Produk Pinjaman SHM
                    </h1>
                    <p className="text-sm text-gray-500">
                        Master pinjaman milik modul SHM. Terpisah dari produk
                        pinjaman koperasi.
                    </p>
                </div>

                <form onSubmit={applyFilters} className="flex flex-wrap items-end gap-3">
                    <div>
                        <label className="text-xs font-semibold text-slate-700">Cari</label>
                        <input
                            type="text"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Kode atau nama produk"
                            className="mt-1 block w-64 rounded-lg border border-slate-300 px-3 py-2 text-sm"
                        />
                    </div>
                    <div>
                        <label className="text-xs font-semibold text-slate-700">Status</label>
                        <select
                            value={status}
                            onChange={(event) => setStatus(event.target.value)}
                            className="mt-1 block rounded-lg border border-slate-300 px-3 py-2 text-sm"
                        >
                            <option value="all">Semua status</option>
                            <option value="active">Aktif</option>
                            <option value="inactive">Nonaktif</option>
                        </select>
                    </div>
                    <PrimaryButton type="submit">
                        <Search className="mr-2 h-4 w-4" />
                        Cari
                    </PrimaryButton>
                </form>

                <ReportTable
                    columns={columns}
                    rows={products.data}
                    pagination={products}
                    sort={sort}
                    onSort={handleSort}
                    routeName="shm.loans.products.index"
                    emptyTitle="Belum ada produk pinjaman SHM"
                    emptyDescription="Produk pinjaman SHM yang didaftarkan akan tampil di sini."
                />
            </div>
        </ShmLayout>
    );
}
