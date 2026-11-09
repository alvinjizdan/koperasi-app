import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import ReportTable from '@/Components/Admin/ReportTable';
import StatusBadge from '@/Components/Admin/StatusBadge';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import EmptyState from '@/Components/Admin/EmptyState';
import useTableSort from '@/hooks/useTableSort';
import { formatDateTime } from '@/utils/format';
import { Filter, Plus, RotateCcw, RefreshCw, Eye, Pencil, Trash2 } from 'lucide-react';

/**
 * Halaman Submenu Memorandum Buyback (Ticket 4).
 *
 * Menampilkan hanya daftar berkas memorandum kategori Buyback dari
 * pengajuan Buyback yang telah selesai (Completed).
 */
export default function BuybackIndex({
    items,
    sort,
    filters,
    statuses,
    can_create,
    can_edit,
    can_delete,
    flash,
}) {
    const handleSort = useTableSort('shm.memorandums.buyback.index');

    const [search, setSearch] = useState(filters.search || '');
    const [status, setStatus] = useState(filters.status || 'all');

    const applyFilters = (event) => {
        event.preventDefault();
        router.get(
            route('shm.memorandums.buyback.index'),
            {
                search: search || undefined,
                status: status !== 'all' ? status : undefined,
            },
            { preserveState: true, replace: true },
        );
    };

    const resetFilters = () => {
        setSearch('');
        setStatus('all');
        router.get(route('shm.memorandums.buyback.index'), {}, { preserveState: true, replace: true });
    };

    const handleDelete = (id, applicationNumber) => {
        if (
            confirm(
                `Apakah Anda yakin ingin menghapus data Memorandum Buyback untuk pengajuan ${applicationNumber}? Data pengajuan asal tidak akan terhapus.`,
            )
        ) {
            router.delete(route('shm.memorandums.buyback.destroy', id));
        }
    };

    const columns = [
        {
            key: 'application_number',
            label: 'No. Pengajuan',
            sortable: false,
            render: (row) => (
                <div className="font-medium text-slate-800">
                    <Link
                        href={route('shm.memorandums.buyback.show', row.id)}
                        className="text-bsm-primary hover:underline"
                    >
                        {row.application_number}
                    </Link>
                </div>
            ),
        },
        {
            key: 'customer_name',
            label: 'Nama Nasabah',
            sortable: false,
            render: (row) => (
                <div>
                    <span className="font-medium text-slate-800">{row.customer_name}</span>
                    <span className="block text-xs text-slate-400">{row.customer_number}</span>
                </div>
            ),
        },
        {
            key: 'status',
            label: 'Status',
            sortable: true,
            render: (row) => (
                <StatusBadge
                    status={row.status === 'submitted' ? 'COMPLETED' : 'PENDING'}
                    label={row.status_label}
                />
            ),
        },
        {
            key: 'creator_name',
            label: 'Dibuat Oleh',
            sortable: false,
            render: (row) => (
                <span className="text-slate-600">{row.creator_name ?? '-'}</span>
            ),
        },
        {
            key: 'updated_at',
            label: 'Terakhir Diubah',
            sortable: true,
            render: (row) => (
                <span className="text-slate-600 text-xs">
                    {formatDateTime(row.updated_at)}
                </span>
            ),
        },
        {
            key: 'actions',
            label: 'Aksi',
            sortable: false,
            render: (row) => (
                <div className="flex items-center gap-2">
                    <Link
                        href={route('shm.memorandums.buyback.show', row.id)}
                        className="inline-flex items-center gap-1 rounded px-2 py-1 text-xs font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900"
                    >
                        <Eye className="h-3.5 w-3.5" />
                        Detail
                    </Link>
                    {can_edit && (
                        <Link
                            href={route('shm.memorandums.buyback.edit', row.id)}
                            className="inline-flex items-center gap-1 rounded px-2 py-1 text-xs font-medium text-bsm-primary hover:bg-blue-50"
                        >
                            <Pencil className="h-3.5 w-3.5" />
                            Edit
                        </Link>
                    )}
                    {can_delete && (
                        <button
                            type="button"
                            onClick={() => handleDelete(row.id, row.application_number)}
                            className="inline-flex items-center gap-1 rounded px-2 py-1 text-xs font-medium text-rose-600 hover:bg-rose-50"
                        >
                            <Trash2 className="h-3.5 w-3.5" />
                            Hapus
                        </button>
                    )}
                </div>
            ),
        },
    ];

    return (
        <ShmLayout title="Memorandum Buyback">
            <Head title="Memorandum Buyback" />

            <div className="space-y-6">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-xl font-semibold text-gray-800">Memorandum Buyback</h1>
                        <p className="text-sm text-gray-500">
                            Daftar berkas memorandum kategori Buyback operasional dari pengajuan yang telah selesai.
                        </p>
                    </div>

                    <div className="flex items-center gap-2">
                        {can_create && (
                            <Link href={route('shm.memorandums.buyback.create')}>
                                <PrimaryButton className="inline-flex items-center gap-1.5">
                                    <Plus className="h-4 w-4" />
                                    Tambah Buyback
                                </PrimaryButton>
                            </Link>
                        )}
                    </div>
                </div>

                {flash?.success && (
                    <div className="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">
                        {flash.success}
                    </div>
                )}

                <div className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                    <form onSubmit={applyFilters} className="grid grid-cols-1 gap-4 md:grid-cols-3">
                        <div>
                            <label className="block text-xs font-medium text-slate-600 mb-1">Pencarian</label>
                            <input
                                type="text"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Cari No. Pengajuan, Catatan, atau Nasabah..."
                                className="w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-bsm-primary focus:ring-bsm-primary"
                            />
                        </div>

                        <div>
                            <label className="block text-xs font-medium text-slate-600 mb-1">Status Dokumen</label>
                            <select
                                value={status}
                                onChange={(e) => setStatus(e.target.value)}
                                className="w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-bsm-primary focus:ring-bsm-primary"
                            >
                                <option value="all">Semua Status</option>
                                {statuses &&
                                    Object.entries(statuses).map(([val, label]) => (
                                        <option key={val} value={val}>
                                            {label}
                                        </option>
                                    ))}
                            </select>
                        </div>

                        <div className="flex items-end gap-2">
                            <PrimaryButton type="submit" className="inline-flex items-center gap-1">
                                <Filter className="h-4 w-4" />
                                Terapkan Filter
                            </PrimaryButton>
                            {(search || status !== 'all') && (
                                <SecondaryButton type="button" onClick={resetFilters} className="inline-flex items-center gap-1">
                                    <RotateCcw className="h-4 w-4" />
                                    Reset
                                </SecondaryButton>
                            )}
                        </div>
                    </form>
                </div>

                <ReportTable
                    columns={columns}
                    rows={items.data}
                    pagination={items}
                    sort={sort}
                    onSort={handleSort}
                    routeName="shm.memorandums.buyback.index"
                    emptyTitle="Belum Ada Memorandum Buyback"
                    emptyDescription="Data memorandum Buyback yang dibuat dari pengajuan selesai akan ditampilkan di sini."
                />
            </div>
        </ShmLayout>
    );
}
