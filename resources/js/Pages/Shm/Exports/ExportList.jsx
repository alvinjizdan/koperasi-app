import React, { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import ReportTable from '@/Components/Admin/ReportTable';
import StatusBadge from '@/Components/Admin/StatusBadge';
import useTableSort from '@/hooks/useTableSort';
import { formatDateTime } from '@/utils/format';
import { FileText, Filter, RotateCcw, Search } from 'lucide-react';

/**
 * Peta warna badge untuk tahap pengajuan pada modul export.
 *
 * Kunci mengikuti StatusBadge existing; tidak ada warna baru.
 */
const APPLICATION_BADGES = {
    submitted: 'PENDING',
    credit_checking: 'REVIEW',
    memorandum: 'REVIEW',
    approval: 'REVIEW',
    approved: 'COMPLETED',
    document_check: 'REVIEW',
    ready_for_disbursement: 'REVIEW',
    rejected: 'FAILED',
    disbursed: 'COMPLETED',
    completed: 'COMPLETED',
    draft: 'PENDING',
    cancelled: 'FAILED',
};

const CATEGORY_TABS = [
    { key: 'masuk', label: 'Masuk' },
    { key: 'approve', label: 'Approve' },
    { key: 'reject', label: 'Reject' },
    { key: 'pencairan', label: 'Pencairan' },
];

/**
 * Modul Export — pemantauan data operasional (Ticket 55).
 *
 * Satu komponen daftar READ-ONLY dipakai bersama oleh empat kategori export
 * (masuk / approve / reject / pencairan). Mapping kategori ke status dilakukan
 * di server dari kolom/status existing; halaman ini tidak mengarang data.
 *
 * Tidak ada kolom nominal karena tabel pengajuan SHM memang tidak memiliki
 * kolom finansial. Baris membuka detail pengajuan existing — tidak ada aksi
 * approve/reject/submit/disburse di modul ini.
 */
export default function ExportList({ category, categoryConfig, applications, sort, filters, types, routes, flash }) {
    const handleSort = useTableSort(`shm.exports.${category}`);

    const [search, setSearch] = useState(filters.search || '');
    const [type, setType] = useState(filters.type || 'all');

    const applyFilters = (event) => {
        event.preventDefault();
        router.get(
            routes[category],
            {
                search: search || undefined,
                type: type !== 'all' ? type : undefined,
            },
            { preserveState: true, replace: true },
        );
    };

    const resetFilters = () => {
        setSearch('');
        setType('all');
        router.get(routes[category], {}, { preserveState: true, replace: true });
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
        {
            key: 'customer_label',
            label: 'Nasabah',
            render: (row) => (
                <div>
                    <p className="font-medium text-slate-700">{row.customer_label}</p>
                    <p className="text-[11px] text-slate-400">{row.customer_number ?? '-'}</p>
                </div>
            ),
        },
        {
            key: 'memorandum_type_label',
            label: 'Memorandum',
            render: (row) => row.memorandum_type_label ?? <span className="text-slate-400">-</span>,
        },
        {
            key: 'status',
            label: 'Status',
            sortable: false,
            render: (row) => (
                <StatusBadge
                    status={APPLICATION_BADGES[row.status] ?? 'PENDING'}
                    label={row.status_label}
                />
            ),
        },
        {
            key: 'submitted_at',
            label: 'Tanggal Pengajuan',
            sortable: true,
            render: (row) => (row.submitted_at ? formatDateTime(row.submitted_at) : '-'),
        },
        {
            key: categoryConfig.event_column,
            label: categoryConfig.event_label,
            sortable: true,
            render: (row) =>
                row[categoryConfig.event_column]
                    ? formatDateTime(row[categoryConfig.event_column])
                    : '-',
        },
        {
            key: 'updated_at',
            label: 'Diperbarui',
            sortable: true,
            render: (row) => (row.updated_at ? formatDateTime(row.updated_at) : '-'),
        },
        {
            key: 'action',
            label: 'Aksi',
            sortable: false,
            render: (row) => (
                <Link
                    href={route('shm.applications.show', row.id)}
                    className="text-xs font-semibold text-indigo-600 hover:underline"
                >
                    Detail
                </Link>
            ),
        },
    ];

    return (
        <ShmLayout title={categoryConfig.title}>
            <div className="space-y-6">
                <div>
                    <h1 className="text-xl font-semibold text-gray-800">{categoryConfig.title}</h1>
                    <p className="text-sm text-gray-500">{categoryConfig.description}</p>
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

                {/* Tab kategori: navigasi antar empat halaman export existing */}
                <div className="flex flex-wrap gap-2">
                    {CATEGORY_TABS.map((tab) => (
                        <a
                            key={tab.key}
                            href={routes[tab.key]}
                            className={`rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors ${
                                tab.key === category
                                    ? 'bg-slate-800 text-white'
                                    : 'border border-slate-300 bg-white text-slate-700 hover:bg-slate-50'
                            }`}
                        >
                            {tab.label}
                        </a>
                    ))}
                </div>

                <form
                    onSubmit={applyFilters}
                    className="grid grid-cols-1 gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-xs sm:grid-cols-2 lg:grid-cols-4"
                >
                    <div className="lg:col-span-2">
                        <label className="block text-xs font-medium text-slate-600">Cari</label>
                        <div className="relative mt-1">
                            <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                            <input
                                type="text"
                                value={search}
                                onChange={(event) => setSearch(event.target.value)}
                                placeholder="No. pengajuan, nama/nomor nasabah"
                                className="placeholder-slate-400 peer block w-full rounded-lg border border-slate-300 py-2 pl-9 pr-3 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                            />
                        </div>
                    </div>
                    <div>
                        <label className="block text-xs font-medium text-slate-600">Jenis Memorandum</label>
                        <select
                            value={type}
                            onChange={(event) => setType(event.target.value)}
                            className="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                        >
                            <option value="all">Semua jenis</option>
                            {Object.entries(types).map(([value, label]) => (
                                <option key={value} value={value}>
                                    {label}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="flex flex-wrap items-end gap-2">
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
                    rows={applications.data}
                    pagination={applications}
                    sort={sort}
                    onSort={handleSort}
                    routeName={`shm.exports.${category}`}
                    emptyIcon={FileText}
                    emptyTitle="Belum ada data"
                    emptyDescription="Tidak ada pengajuan yang sesuai dengan kategori dan filter ini."
                />
            </div>
        </ShmLayout>
    );
}
