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
import { Filter, Plus, RotateCcw, FileSpreadsheet, Eye, Pencil, Trash2 } from 'lucide-react';

export default function ShmShgbIndex({
    items,
    sort,
    filters,
    statuses,
    can_create,
    can_edit,
    can_delete,
    flash,
}) {
    const handleSort = useTableSort('shm.memorandums.shm-shgb.index');

    const [search, setSearch] = useState(filters.search || '');
    const [status, setStatus] = useState(filters.status || 'all');

    const applyFilters = (event) => {
        event.preventDefault();
        router.get(
            route('shm.memorandums.shm-shgb.index'),
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
        router.get(route('shm.memorandums.shm-shgb.index'), {}, { preserveState: true, replace: true });
    };

    const handleDelete = (id, applicationNumber) => {
        if (confirm(`Apakah Anda yakin ingin menghapus data SHM/SHGB untuk pengajuan ${applicationNumber}? Data pengajuan asal tidak akan terhapus.`)) {
            router.delete(route('shm.memorandums.shm-shgb.destroy', id));
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
                        href={route('shm.memorandums.shm-shgb.show', row.id)}
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
                        href={route('shm.memorandums.shm-shgb.show', row.id)}
                        className="inline-flex items-center gap-1 rounded px-2 py-1 text-xs font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900"
                    >
                        <Eye className="h-3.5 w-3.5" />
                        Detail
                    </Link>
                    {can_edit && (
                        <Link
                            href={route('shm.memorandums.shm-shgb.edit', row.id)}
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
        <ShmLayout title="Memorandum SHM/SHGB">
            <Head title="Memorandum SHM/SHGB" />

            <div className="space-y-6">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-xl font-semibold text-gray-800">Memorandum SHM/SHGB</h1>
                        <p className="text-sm text-gray-500">
                            Daftar berkas memorandum kategori SHM/SHGB operasional dari pengajuan yang telah selesai.
                        </p>
                    </div>

                    <div className="flex items-center gap-2">
                        {can_create && (
                            <Link href={route('shm.memorandums.shm-shgb.create')}>
                                <PrimaryButton className="inline-flex items-center gap-1.5">
                                    <Plus className="h-4 w-4" />
                                    Buat SHM/SHGB
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
                                placeholder="Cari nomor pengajuan atau nama nasabah..."
                                className="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm focus:border-bsm-primary focus:outline-none focus:ring-1 focus:ring-bsm-primary"
                            />
                        </div>

                        <div>
                            <label className="block text-xs font-medium text-slate-600 mb-1">Status</label>
                            <select
                                value={status}
                                onChange={(e) => setStatus(e.target.value)}
                                className="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm focus:border-bsm-primary focus:outline-none focus:ring-1 focus:ring-bsm-primary"
                            >
                                <option value="all">Semua Status</option>
                                {Object.entries(statuses || {}).map(([key, label]) => (
                                    <option key={key} value={key}>{label}</option>
                                ))}
                            </select>
                        </div>

                        <div className="flex items-end gap-2">
                            <PrimaryButton type="submit" className="inline-flex items-center gap-1 py-2">
                                <Filter className="h-3.5 w-3.5" />
                                Filter
                            </PrimaryButton>
                            <SecondaryButton type="button" onClick={resetFilters} className="inline-flex items-center gap-1 py-2">
                                <RotateCcw className="h-3.5 w-3.5" />
                                Reset
                            </SecondaryButton>
                        </div>
                    </form>
                </div>

                <div className="rounded-lg border border-slate-200 bg-white shadow-sm overflow-hidden">
                    {items?.data?.length > 0 ? (
                        <ReportTable
                            columns={columns}
                            rows={items.data}
                            pagination={items}
                            sort={sort}
                            onSort={handleSort}
                            routeName="shm.memorandums.shm-shgb.index"
                        />
                    ) : (
                        <div className="p-8">
                            <EmptyState
                                title="Belum ada data Memorandum SHM/SHGB"
                                description="Data memorandum kategori SHM/SHGB akan muncul di sini."
                                icon={FileSpreadsheet}
                                action={
                                    can_create ? (
                                        <Link href={route('shm.memorandums.create')}>
                                            <PrimaryButton className="inline-flex items-center gap-1.5 text-xs">
                                                <Plus className="h-3.5 w-3.5" />
                                                Tambah Memorandum Baru
                                            </PrimaryButton>
                                        </Link>
                                    ) : null
                                }
                            />
                        </div>
                    )}
                </div>
            </div>
        </ShmLayout>
    );
}

