import React, { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import ReportTable from '@/Components/Admin/ReportTable';
import StatusBadge from '@/Components/Admin/StatusBadge';
import SecondaryButton from '@/Components/SecondaryButton';
import useTableSort from '@/hooks/useTableSort';
import { formatDateTime } from '@/utils/format';
import { Search, Filter, RotateCcw, FileText } from 'lucide-react';

/**
 * Kelengkapan Dokumen — Admin monitoring.
 *
 * Menampilkan item checklist dokumen (`shm_documents`) beserta konteks
 * pengajuan, nasabah, dan objek. Kelengkapan dokumen bersifat administratif:
 * tidak ada daftar dokumen wajib, tidak ada upload berkas, dan tidak ada
 * aturan legal yang digantungkan di sini.
 *
 * Status dokumen memakai terminologi existing: `pending`, `complete`,
 * `not_applicable`.
 */
export default function Index({
    documents,
    sort,
    filters,
    document_statuses,
    application_statuses,
    customers,
    can_update,
    flash,
}) {
    const handleSort = useTableSort('shm.documents.index');

    const [search, setSearch] = useState(filters.search || '');
    const [document_status, setDocumentStatus] = useState(filters.document_status || 'all');
    const [application_status, setApplicationStatus] = useState(filters.application_status || 'all');
    const [shm_customer_id, setShmCustomerId] = useState(filters.shm_customer_id || 'all');

    const applyFilters = (event) => {
        event.preventDefault();
        router.get(
            route('shm.documents.index'),
            {
                search: search || undefined,
                document_status: document_status !== 'all' ? document_status : undefined,
                application_status: application_status !== 'all' ? application_status : undefined,
                shm_customer_id: shm_customer_id !== 'all' ? shm_customer_id : undefined,
            },
            { preserveState: true, replace: true },
        );
    };

    const resetFilters = () => {
        setSearch('');
        setDocumentStatus('all');
        setApplicationStatus('all');
        setShmCustomerId('all');
        router.get(route('shm.documents.index'), {}, { preserveState: true, replace: true });
    };

    const columns = [
        {
            key: 'document_name',
            label: 'Nama Dokumen',
            sortable: true,
            render: (row) => (
                <span className="font-medium text-slate-700">{row.document_name}</span>
            ),
        },
        {
            key: 'status',
            label: 'Status Dokumen',
            sortable: true,
            render: (row) => (
                <StatusBadge
                    status={row.status === 'complete' ? 'COMPLETED' : row.status === 'not_applicable' ? 'REVIEW' : 'PENDING'}
                    label={row.status_label}
                />
            ),
        },
        {
            key: 'application_number',
            label: 'No. Pengajuan',
            sortable: true,
            render: (row) => (
                <Link
                    href={route('shm.documents.show', row.application_id)}
                    className="font-medium text-indigo-600 hover:underline"
                >
                    {row.application_number}
                </Link>
            ),
        },
        {
            key: 'customer_name',
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
            key: 'property_summary',
            label: 'Objek SHM/SHGB',
            render: (row) => {
                if (!row.property_summary) {
                    return <span className="text-slate-400">-</span>;
                }

                return (
                    <div className="text-right">
                        <p className="text-sm font-medium text-slate-700">
                            {row.property_summary.certificate_type}
                            {row.property_summary.certificate_number
                                ? ` - ${row.property_summary.certificate_number}`
                                : ''}
                        </p>
                        <p className="text-[11px] text-slate-400">{row.property_summary.address}</p>
                    </div>
                );
            },
        },
        {
            key: 'checked_at',
            label: 'Tercatat Pada',
            sortable: true,
            render: (row) => row.checked_at ? formatDateTime(row.checked_at) : '-',
        },
        {
            key: 'checker',
            label: 'Checker',
            render: (row) => row.checker ?? '-',
        },
        {
            key: 'action',
            label: 'Aksi',
            sortable: false,
            render: (row) => (
                <Link
                    href={route('shm.documents.show', row.application_id)}
                    className="text-xs font-semibold text-indigo-600 hover:underline"
                >
                    Detail
                </Link>
            ),
        },
    ];

    return (
        <ShmLayout title="Kelengkapan Dokumen">
            <div className="space-y-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="text-xl font-semibold text-gray-800">Kelengkapan Dokumen</h1>
                        <p className="text-sm text-gray-500">
                            Checklists kelengkapan dokumen pengajuan SHM/SHGB. Tidak ada daftar dokumen
                            wajib dari KSP dan tidak ada upload berkas pada modul ini.
                        </p>
                    </div>
                    {can_update && (
                        <Link href={route('shm.applications.index')}>
                            <SecondaryButton>
                                <span className="inline-flex items-center gap-2">
                                    <FileText className="h-4 w-4" />
                                    Lihat Pengajuan
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
                        <div className="relative mt-1">
                            <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                            <input
                                type="text"
                                value={search}
                                onChange={(event) => setSearch(event.target.value)}
                                placeholder="Nama dokumen, no. pengajuan, nama/nomor nasabah"
                                className="placeholder-slate-400 peer block w-full rounded-lg border border-slate-300 py-2 pl-9 pr-3 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                            />
                        </div>
                    </div>
                    <div>
                        <label className="block text-xs font-medium text-slate-600">Status Dokumen</label>
                        <select
                            value={document_status}
                            onChange={(event) => setDocumentStatus(event.target.value)}
                            className="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                        >
                            <option value="all">Semua status</option>
                            {Object.entries(document_statuses).map(([value, label]) => (
                                <option key={value} value={value}>
                                    {label}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div>
                        <label className="block text-xs font-medium text-slate-600">Tahap Pengajuan</label>
                        <select
                            value={application_status}
                            onChange={(event) => setApplicationStatus(event.target.value)}
                            className="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                        >
                            <option value="all">Semua tahap</option>
                            {application_statuses.map((item) => (
                                <option key={item.value} value={item.value}>
                                    {item.label}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div>
                        <label className="block text-xs font-medium text-slate-600">Nasabah</label>
                        <select
                            value={shm_customer_id}
                            onChange={(event) => setShmCustomerId(event.target.value)}
                            className="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                        >
                            <option value="all">Semua nasabah</option>
                            {customers.map((customer) => (
                                <option key={customer.id} value={customer.id}>
                                    {customer.name} ({customer.customer_number})
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
                    rows={documents.data}
                    pagination={documents}
                    sort={sort}
                    onSort={handleSort}
                    routeName="shm.documents.index"
                    emptyIcon={FileText}
                    emptyTitle="Belum ada data dokumen"
                    emptyDescription="Tidak ada item checklist dokumen yang sesuai dengan filter ini. Tambahkan dokumen dari halaman pengajuan jika perlu."
                />
            </div>
        </ShmLayout>
    );
}
