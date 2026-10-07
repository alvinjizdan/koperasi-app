import React, { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import ReportTable from '@/Components/Admin/ReportTable';
import StatusBadge from '@/Components/Admin/StatusBadge';
import SecondaryButton from '@/Components/SecondaryButton';
import useTableSort from '@/hooks/useTableSort';
import { formatDateTime } from '@/utils/format';
import { Filter, Plus, RotateCcw, ScrollText } from 'lucide-react';

/**
 * Modul Memorandum (Ticket 49): daftar SHM/SHGB & Buyback.
 *
 * Satu domain memorandum untuk dua kategori — kategori hanya filter
 * (`?type=`), bukan halaman kedua. Kolom referensi memakai nomor pengajuan
 * karena belum ada nomor dokumen memorandum resmi (PENDING BUSINESS DECISION).
 */
export default function Index({
    memorandums,
    sort,
    filters,
    types,
    statuses,
    can_create,
    flash,
}) {
    const handleSort = useTableSort('shm.memorandums.index');

    const [search, setSearch] = useState(filters.search || '');
    const [type, setType] = useState(filters.type || 'all');
    const [status, setStatus] = useState(filters.status || 'all');

    const applyFilters = (event) => {
        event.preventDefault();
        router.get(
            route('shm.memorandums.index'),
            {
                search: search || undefined,
                type: type !== 'all' ? type : undefined,
                status: status !== 'all' ? status : undefined,
            },
            { preserveState: true, replace: true },
        );
    };

    const resetFilters = () => {
        setSearch('');
        setType('all');
        setStatus('all');
        router.get(route('shm.memorandums.index'), {}, { preserveState: true, replace: true });
    };

    const columns = [
        {
            key: 'reference',
            label: 'Referensi (No. Pengajuan)',
            render: (row) => (
                <Link
                    href={route('shm.memorandums.show', row.id)}
                    className="font-medium text-indigo-600 hover:underline"
                >
                    {row.reference}
                </Link>
            ),
        },
        {
            key: 'customer',
            label: 'Nasabah',
            render: (row) => (
                <div>
                    <p className="font-medium text-slate-700">{row.customer_name ?? '-'}</p>
                    <p className="text-[11px] text-slate-400">{row.customer_number ?? '-'}</p>
                </div>
            ),
        },
        {
            key: 'application_status_label',
            label: 'Tahap Pengajuan',
            render: (row) => row.application_status_label ?? '-',
        },
        {
            key: 'type',
            label: 'Kategori',
            sortable: true,
            render: (row) => row.type_label ?? '-',
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
            key: 'creator',
            label: 'Dibuat Oleh',
            render: (row) => row.creator ?? '-',
        },
        {
            key: 'updated_at',
            label: 'Diperbarui',
            sortable: true,
            render: (row) => formatDateTime(row.updated_at),
        },
        {
            key: 'action',
            label: 'Aksi',
            sortable: false,
            render: (row) => (
                <Link
                    href={route('shm.memorandums.show', row.id)}
                    className="text-xs font-semibold text-indigo-600 hover:underline"
                >
                    Detail
                </Link>
            ),
        },
    ];

    return (
        <ShmLayout title="Memorandum">
            <div className="space-y-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="text-xl font-semibold text-gray-800">Memorandum</h1>
                        <p className="text-sm text-gray-500">
                            Naskah memorandum kategori SHM/SHGB dan Buyback. Tidak ada nominal,
                            margin, bunga, atau formula buyback pada modul ini.
                        </p>
                    </div>
                    {can_create && (
                        <Link href={route('shm.memorandums.create')}>
                            <SecondaryButton>
                                <span className="inline-flex items-center gap-2">
                                    <Plus className="h-4 w-4" />
                                    Buat Memorandum
                                </span>
                            </SecondaryButton>
                        </Link>
                    )}
                </div>

                {flash?.success && (
                    <div className="rounded-md bg-green-50 px-4 py-3 text-sm text-green-800">
                        {flash.success}
                    </div>
                )}
                {flash?.error && (
                    <div className="rounded-md bg-rose-50 px-4 py-3 text-sm text-rose-800">
                        {flash.error}
                    </div>
                )}

                <form
                    onSubmit={applyFilters}
                    className="grid grid-cols-1 gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-xs sm:grid-cols-2 lg:grid-cols-4"
                >
                    <div className="lg:col-span-2">
                        <label className="block text-xs font-medium text-slate-600">Cari</label>
                        <input
                            type="text"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="No. pengajuan, nama/nomor nasabah, atau isi memorandum"
                            className="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                        />
                    </div>
                    <div>
                        <label className="block text-xs font-medium text-slate-600">Kategori</label>
                        <select
                            value={type}
                            onChange={(event) => setType(event.target.value)}
                            className="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                        >
                            <option value="all">Semua kategori</option>
                            {Object.entries(types).map(([value, label]) => (
                                <option key={value} value={value}>
                                    {label}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div>
                        <label className="block text-xs font-medium text-slate-600">Status</label>
                        <select
                            value={status}
                            onChange={(event) => setStatus(event.target.value)}
                            className="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                        >
                            <option value="all">Semua status</option>
                            {statuses.map((item) => (
                                <option key={item.value} value={item.value}>
                                    {item.label}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="flex flex-wrap items-end gap-2 sm:col-span-2 lg:col-span-4">
                        <button
                            type="submit"
                            className="inline-flex items-center gap-1.5 rounded-lg bg-slate-800 px-3 py-2 text-xs font-semibold text-white transition-all hover:bg-slate-900 active:scale-95"
                        >
                            <Filter className="h-3.5 w-3.5" />
                            Terapkan
                        </button>
                        <button
                            type="button"
                            onClick={resetFilters}
                            className="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 px-3 py-2 text-xs font-medium text-slate-700 transition-colors hover:bg-slate-50"
                        >
                            <RotateCcw className="h-3.5 w-3.5" />
                            Reset
                        </button>
                    </div>
                </form>

                <ReportTable
                    columns={columns}
                    rows={memorandums.data}
                    pagination={memorandums}
                    sort={sort}
                    onSort={handleSort}
                    routeName="shm.memorandums.index"
                    emptyIcon={ScrollText}
                    emptyTitle="Belum ada memorandum"
                    emptyDescription="Memorandum yang dibuat dari halaman pengajuan atau menu ini akan tampil di sini."
                />
            </div>
        </ShmLayout>
    );
}
