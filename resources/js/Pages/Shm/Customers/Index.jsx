import React, { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import ReportTable from '@/Components/Admin/ReportTable';
import PrimaryButton from '@/Components/PrimaryButton';
import useTableSort from '@/hooks/useTableSort';
import { formatDateTime } from '@/utils/format';
import { Filter, Plus, Search } from 'lucide-react';

/**
 * Daftar Nasabah SHM.
 *
 * Nasabah SHM adalah master data tersendiri dan BUKAN anggota koperasi.
 * Master nasabah bersifat bersama karena Marketing perlu memilih nasabah yang
 * sudah ada saat membuat pengajuan; yang tetap dibatasi kepemilikan adalah
 * data pengajuan, bukan data nasabah.
 */
export default function Index({ customers, sort, filters, permissions, flash }) {
    const handleSort = useTableSort('shm.customers.index');

    const [search, setSearch] = useState(filters.search || '');
    const [status, setStatus] = useState(filters.status || 'all');

    const applyFilters = (event) => {
        event.preventDefault();
        router.get(
            route('shm.customers.index'),
            {
                search: search || undefined,
                status: status !== 'all' ? status : undefined,
            },
            { preserveState: true, replace: true },
        );
    };

    const columns = [
        {
            key: 'customer_number',
            label: 'No. Nasabah',
            sortable: true,
            render: (row) => (
                <Link
                    href={route('shm.customers.show', row.id)}
                    className="font-medium text-indigo-600 hover:underline"
                >
                    {row.customer_number}
                </Link>
            ),
        },
        { key: 'name', label: 'Nama', sortable: true },
        {
            key: 'nik',
            label: 'NIK',
            sortable: true,
            render: (row) => row.nik || '-',
        },
        {
            key: 'phone',
            label: 'Telepon',
            render: (row) => row.phone || '-',
        },
        {
            key: 'address',
            label: 'Alamat',
            render: (row) => (
                <span className="line-clamp-1 max-w-xs">{row.address || '-'}</span>
            ),
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
            key: 'applications_count',
            label: 'Jml. Pengajuan',
            align: 'right',
            render: (row) => row.applications_count ?? 0,
        },
        {
            key: 'created_at',
            label: 'Dibuat',
            sortable: true,
            render: (row) => formatDateTime(row.created_at),
        },
    ];

    return (
        <ShmLayout title="Nasabah SHM">
            <div className="space-y-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-xl font-semibold text-gray-800">Nasabah SHM</h1>
                        <p className="text-sm text-gray-500">
                            Master nasabah SHM/SHGB. Terpisah dari data anggota koperasi.
                        </p>
                    </div>
                    {permissions?.can_create && (
                        <Link href={route('shm.customers.create')}>
                            <PrimaryButton>
                                <Plus className="mr-2 h-4 w-4" />
                                Tambah Nasabah
                            </PrimaryButton>
                        </Link>
                    )}
                </div>

                {flash?.success && (
                    <div className="rounded-md bg-green-50 px-4 py-3 text-sm text-green-800">
                        {flash.success}
                    </div>
                )}

                <form onSubmit={applyFilters} className="flex flex-wrap items-end gap-3">
                    <div>
                        <label className="text-xs font-semibold text-slate-700">Cari</label>
                        <input
                            type="text"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Nama, nomor nasabah, NIK, atau telepon"
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
                    rows={customers.data}
                    pagination={customers}
                    sort={sort}
                    onSort={handleSort}
                    routeName="shm.customers.index"
                    emptyTitle="Belum ada nasabah SHM"
                    emptyDescription="Nasabah SHM yang ditambahkan akan tampil di sini."
                />
            </div>
        </ShmLayout>
    );
}
