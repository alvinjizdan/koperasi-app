import React, { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import ReportTable from '@/Components/Admin/ReportTable';
import PrimaryButton from '@/Components/PrimaryButton';
import useTableSort from '@/hooks/useTableSort';
import { formatRupiah, formatDate } from '@/utils/format';
import { Calculator, Search } from 'lucide-react';

/**
 * Daftar record Pinjaman nasabah SHM (Ticket 64).
 *
 * Domain SHM: hanya membaca `shm_loans` yang berelasi ke `shm_customers`.
 * Pinjaman koperasi (`loans`) dan jadwal angsurannya tidak pernah dibaca di
 * sini. Rate yang tampil adalah rate resmi SHM (2%).
 */
export default function Index({ loans, sort, filters }) {
    const handleSort = useTableSort('shm.loans.index');

    const [search, setSearch] = useState(filters.search || '');
    const [status, setStatus] = useState(filters.status || 'all');

    const applyFilters = (event) => {
        event.preventDefault();
        router.get(
            route('shm.loans.index'),
            {
                search: search || undefined,
                status: status !== 'all' ? status : undefined,
            },
            { preserveState: true, replace: true },
        );
    };

    const columns = [
        {
            key: 'loan_number',
            label: 'No. Pinjaman',
            sortable: true,
            render: (row) => (
                <Link
                    href={route('shm.loans.show', row.id)}
                    className="font-medium text-indigo-600 hover:underline"
                >
                    {row.loan_number}
                </Link>
            ),
        },
        {
            key: 'customer',
            label: 'Nasabah SHM',
            sortable: false,
            render: (row) => (
                <div>
                    <p className="font-medium text-slate-700">{row.customer?.name || '-'}</p>
                    {row.customer?.customer_number && (
                        <p className="text-xs text-slate-400">{row.customer.customer_number}</p>
                    )}
                </div>
            ),
        },
        {
            key: 'product',
            label: 'Produk',
            sortable: false,
            render: (row) => row.product?.name || '-',
        },
        {
            key: 'disbursed_at',
            label: 'Tanggal Pencairan',
            sortable: true,
            render: (row) => (row.disbursed_at ? formatDate(row.disbursed_at) : '-'),
        },
        {
            key: 'principal',
            label: 'Plafon',
            sortable: true,
            align: 'right',
            render: (row) => formatRupiah(row.principal),
        },
        {
            key: 'tenor_months',
            label: 'Tenor',
            sortable: true,
            align: 'right',
            render: (row) => `${row.tenor_months} bln`,
        },
        {
            key: 'rate_percent',
            label: 'Bunga/Jasa',
            sortable: true,
            align: 'right',
            render: (row) => `${Number(row.rate_percent)}%`,
        },
        {
            key: 'status',
            label: 'Status',
            sortable: true,
            render: (row) => (
                <span
                    className={`rounded-full px-2 py-1 text-xs font-medium ${
                        row.status === 'active'
                            ? 'bg-emerald-50 text-emerald-700'
                            : 'bg-slate-100 text-slate-600'
                    }`}
                >
                    {row.status === 'active' ? 'Berjalan' : 'Ditutup'}
                </span>
            ),
        },
        {
            key: 'action',
            label: 'Aksi',
            sortable: false,
            align: 'right',
            render: (row) => (
                <Link
                    href={route('shm.loans.show', row.id)}
                    className="inline-flex items-center gap-1 rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-1.5 text-xs font-medium text-indigo-700 hover:bg-indigo-100"
                >
                    Detail
                </Link>
            ),
        },
    ];

    return (
        <ShmLayout title="Pinjaman SHM">
            <div className="space-y-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="flex items-center gap-2 text-xl font-semibold text-gray-800">
                            <Calculator className="h-5 w-5 text-slate-400" />
                            Pinjaman SHM
                        </h1>
                        <p className="text-sm text-gray-500">
                            Record pinjaman nasabah SHM. Terpisah dari pinjaman
                            koperasi. Rate bunga/jasa resmi SHM: 2%.
                        </p>
                    </div>
                    <Link href={route('shm.loans.simulation')}>
                        <PrimaryButton>
                            <Calculator className="mr-2 h-4 w-4" />
                            Simulasi Pinjaman
                        </PrimaryButton>
                    </Link>
                </div>

                <form onSubmit={applyFilters} className="flex flex-wrap items-end gap-3">
                    <div>
                        <label className="text-xs font-semibold text-slate-700">Cari</label>
                        <input
                            type="text"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="No. pinjaman, nasabah, no. nasabah, produk, atau tujuan"
                            className="mt-1 block w-72 rounded-lg border border-slate-300 px-3 py-2 text-sm"
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
                            <option value="active">Berjalan</option>
                            <option value="closed">Ditutup</option>
                        </select>
                    </div>
                    <PrimaryButton type="submit">
                        <Search className="mr-2 h-4 w-4" />
                        Cari
                    </PrimaryButton>
                </form>

                <ReportTable
                    columns={columns}
                    rows={loans.data}
                    pagination={loans}
                    sort={sort}
                    onSort={handleSort}
                    routeName="shm.loans.index"
                    emptyTitle="Belum ada pinjaman SHM"
                    emptyDescription="Record pinjaman nasabah SHM akan tampil di sini."
                />
            </div>
        </ShmLayout>
    );
}
