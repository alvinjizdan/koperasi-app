import React, { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import ReportTable from '@/Components/Admin/ReportTable';
import PrimaryButton from '@/Components/PrimaryButton';
import useTableSort from '@/hooks/useTableSort';
import { formatDate } from '@/utils/format';
import { Search, Landmark, PlusCircle, Pencil, CheckCircle2, AlertTriangle } from 'lucide-react';

/**
 * Daftar produk Pinjaman SHM (Ticket 64; kontrak rate T82; CRUD T98-FIX).
 *
 * Master ini TERPISAH dari produk pinjaman koperasi. Halaman menampilkan
 * kontrak produk resmi: kategori, nama, status aktif, tier rate EXAKT per
 * rentang tenor (per bulan, flat — rate = f(product, tenor); universal 2%
 * tidak berlaku), dan ringkasan syarat (eligibility).
 *
 * Ticket 98-FIX menambahkan CRUD master (Tambah/Edit) yang dijaga backend
 * dengan permission terpisah `shm.product.manage` (baca ≠ tulis); tombol
 * hanya dirender bagi pemegang permission tersebut. Tier rate TIDAK dapat
 * diubah lewat halaman ini — tetap seed-driven (T82).
 */
export default function Products({ products, sort, filters, canManage, flash }) {
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
            key: 'category',
            label: 'Kategori',
            render: (row) => (
                <span className="rounded-full bg-slate-100 px-2 py-1 text-xs font-medium text-slate-700">
                    {row.category || '-'}
                </span>
            ),
        },
        {
            key: 'rate_tiers',
            label: 'Rate per Tenor (bulanan, flat)',
            render: (row) =>
                row.rate_tiers && row.rate_tiers.length > 0 ? (
                    <ul className="space-y-0.5 text-xs">
                        {row.rate_tiers.map((tier) => (
                            <li key={`${tier.min_tenor_months}-${tier.max_tenor_months}`}>
                                {tier.min_tenor_months === tier.max_tenor_months
                                    ? `${tier.min_tenor_months} bulan`
                                    : `${tier.min_tenor_months}–${tier.max_tenor_months} bulan`}
                                : <span className="font-semibold">{Number(tier.rate_percent).toFixed(2)}%</span>
                            </li>
                        ))}
                    </ul>
                ) : (
                    <span className="text-xs text-slate-400">-</span>
                ),
        },
        {
            key: 'eligibility_summary',
            label: 'Syarat Produk',
            render: (row) => (
                <span className="line-clamp-2 max-w-xs text-xs">
                    {row.eligibility_summary || '-'}
                </span>
            ),
        },
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
        ...(canManage
            ? [
                  {
                      key: 'actions',
                      label: '',
                      render: (row) => (
                          <Link
                              href={route('shm.loans.products.edit', row.id)}
                              className="inline-flex items-center gap-1 text-xs font-medium text-indigo-600 hover:underline"
                          >
                              <Pencil className="h-3.5 w-3.5" />
                              Edit
                          </Link>
                      ),
                  },
              ]
            : []),
    ];

    return (
        <ShmLayout title="Produk Pinjaman SHM">
            <div className="space-y-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="flex items-center gap-2 text-xl font-semibold text-gray-800">
                            <Landmark className="h-5 w-5 text-slate-400" />
                            Produk Pinjaman SHM
                        </h1>
                        <p className="text-sm text-gray-500">
                            Master pinjaman milik modul SHM — kategori, tier
                            rate (product + tenor eksak; bulanan flat), dan
                            syarat produk resmi. Terpisah dari produk pinjaman
                            koperasi.
                        </p>
                    </div>
                    {canManage && (
                        <Link
                            href={route('shm.loans.products.create')}
                            className="inline-flex items-center gap-2 rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700"
                        >
                            <PlusCircle className="h-4 w-4" />
                            Tambah Produk
                        </Link>
                    )}
                </div>

                {flash?.success && (
                    <div className="flex items-start gap-2 rounded-md bg-green-50 px-4 py-3 text-sm text-green-800">
                        <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0" />
                        <span>{flash.success}</span>
                    </div>
                )}

                {flash?.error && (
                    <div className="flex items-start gap-2 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">
                        <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                        <span>{flash.error}</span>
                    </div>
                )}

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
