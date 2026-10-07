import React, { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import ReportTable from '@/Components/Admin/ReportTable';
import PrimaryButton from '@/Components/PrimaryButton';
import useTableSort from '@/hooks/useTableSort';
import { formatDateTime } from '@/utils/format';
import { Filter, Plus } from 'lucide-react';

/**
 * Daftar pengajuan SHM/SHGB.
 *
 * Cakupan data sudah dibatasi di server: tanpa shm.view_all, pengguna hanya
 * melihat pengajuannya sendiri. Tombol di halaman ini hanya menyesuaikan
 * tampilan; otorisasi tetap diverifikasi ulang di backend pada setiap request.
 *
 * Filter nasabah memakai master Nasabah SHM, bukan anggota koperasi.
 */
export default function Index({
    applications,
    sort,
    statuses,
    customers,
    filters,
    can_view_all,
    can_create,
    flash,
}) {
    const handleSort = useTableSort('shm.applications.index');

    const [status, setStatus] = useState(filters.status || '');
    const [customerId, setCustomerId] = useState(filters.shm_customer_id || '');

    const applyFilters = (event) => {
        event.preventDefault();
        router.get(
            route('shm.applications.index'),
            {
                status: status || undefined,
                shm_customer_id: customerId || undefined,
            },
            { preserveState: true, replace: true },
        );
    };

    const columns = [
        {
            key: 'application_number',
            label: 'No. Pengajuan',
            sortable: true,
            render: (row) => (
                <Link
                    href={route('shm.applications.show', row.id)}
                    className="font-medium text-indigo-600 hover:underline"
                >
                    {row.application_number}
                </Link>
            ),
        },
        { key: 'customer_label', label: 'Nasabah', sortable: false },
        {
            key: 'status',
            label: 'Status',
            sortable: true,
            render: (row) => (
                <span className="rounded-full bg-gray-100 px-2 py-1 text-xs font-medium text-gray-700">
                    {row.status_label}
                </span>
            ),
        },
        ...(can_view_all
            ? [{ key: 'marketing', label: 'Marketing', sortable: false }]
            : []),
        {
            key: 'submitted_at',
            label: 'Tanggal Submit',
            sortable: true,
            render: (row) => (row.submitted_at ? formatDateTime(row.submitted_at) : '-'),
        },
        {
            key: 'created_at',
            label: 'Dibuat',
            sortable: true,
            render: (row) => formatDateTime(row.created_at),
        },
    ];

    return (
        <ShmLayout title="Pengajuan SHM/SHGB">
            <div className="space-y-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-xl font-semibold text-gray-800">
                            Pengajuan SHM/SHGB
                        </h1>
                        <p className="text-sm text-gray-500">
                            Alur ini adalah workflow teknis MVP, bukan SOP resmi KSP.
                        </p>
                    </div>
                    {can_create && (
                        <Link href={route('shm.applications.create')}>
                            <PrimaryButton>
                                <Plus className="mr-2 h-4 w-4" />
                                Buat Pengajuan
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
                        <label className="text-xs font-semibold text-slate-700">Status</label>
                        <select
                            value={status}
                            onChange={(event) => setStatus(event.target.value)}
                            className="mt-1 block rounded-lg border border-slate-300 px-3 py-2 text-sm"
                        >
                            <option value="">Semua status</option>
                            {statuses.map((item) => (
                                <option key={item.value} value={item.value}>
                                    {item.label}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div>
                        <label className="text-xs font-semibold text-slate-700">Nasabah SHM</label>
                        <select
                            value={customerId}
                            onChange={(event) => setCustomerId(event.target.value)}
                            className="mt-1 block rounded-lg border border-slate-300 px-3 py-2 text-sm"
                        >
                            <option value="">Semua nasabah</option>
                            {customers.map((customer) => (
                                <option key={customer.id} value={customer.id}>
                                    {customer.name}
                                </option>
                            ))}
                        </select>
                    </div>
                    <PrimaryButton type="submit">
                        <Filter className="mr-2 h-4 w-4" />
                        Filter
                    </PrimaryButton>
                </form>

                <ReportTable
                    columns={columns}
                    rows={applications.data}
                    pagination={applications}
                    sort={sort}
                    onSort={handleSort}
                    routeName="shm.applications.index"
                    emptyTitle="Belum ada pengajuan"
                    emptyDescription="Pengajuan SHM yang dibuat akan tampil di sini."
                />
            </div>
        </ShmLayout>
    );
}
