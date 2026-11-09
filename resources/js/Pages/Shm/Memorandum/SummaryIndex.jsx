import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import ReportTable from '@/Components/Admin/ReportTable';
import StatusBadge from '@/Components/Admin/StatusBadge';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import useTableSort from '@/hooks/useTableSort';
import { formatDateTime } from '@/utils/format';
import {
    FileSpreadsheet,
    RefreshCw,
    Layers,
    CheckCircle2,
    Clock,
    Filter,
    RotateCcw,
    Eye,
    Pencil,
    Trash2,
    AlertCircle,
    FileText,
} from 'lucide-react';

/**
 * Halaman Submenu Summary Memorandum (Ticket 5).
 *
 * Menampilkan integrasi terpusat seluruh data Pengajuan (SHGB & Buyback)
 * beserta status dokumen memorandum terkait, statistik KPI, filter,
 * pencarian, serta aksi kontekstual Edit dan Delete.
 */
export default function SummaryIndex({
    stats,
    items,
    sort,
    filters,
    types,
    application_statuses,
    memorandum_statuses,
    can_update,
    can_update_application,
    flash,
}) {
    const handleSort = useTableSort('shm.memorandums.summary.index');

    const [search, setSearch] = useState(filters.search || '');
    const [type, setType] = useState(filters.type || '');
    const [status, setStatus] = useState(filters.status || '');
    const [memorandumStatus, setMemorandumStatus] = useState(filters.memorandum_status || '');

    const applyFilters = (event) => {
        event.preventDefault();
        router.get(
            route('shm.memorandums.summary.index'),
            {
                search: search || undefined,
                type: type || undefined,
                status: status || undefined,
                memorandum_status: memorandumStatus || undefined,
            },
            { preserveState: true, replace: true },
        );
    };

    const resetFilters = () => {
        setSearch('');
        setType('');
        setStatus('');
        setMemorandumStatus('');
        router.get(route('shm.memorandums.summary.index'), {}, { preserveState: true, replace: true });
    };

    const handleDeleteMemorandum = (memorandumId, applicationNumber, memorandumType) => {
        const typeLabel = memorandumType === 'buyback' ? 'Buyback' : 'SHM/SHGB';
        if (
            confirm(
                `Apakah Anda yakin ingin menghapus data Memorandum ${typeLabel} untuk pengajuan ${applicationNumber}? Data pengajuan sumber tidak akan terhapus.`,
            )
        ) {
            const deleteRoute =
                memorandumType === 'buyback'
                    ? route('shm.memorandums.buyback.destroy', memorandumId)
                    : route('shm.memorandums.shm-shgb.destroy', memorandumId);

            router.delete(deleteRoute);
        }
    };

    const handleDeleteApplicationNotice = () => {
        alert(
            'Penghapusan pengajuan yang sedang berjalan atau telah memiliki berkas memorandum tidak diizinkan demi menjaga integritas data riwayat.',
        );
    };

    const columns = [
        {
            key: 'application_number',
            label: 'Kode Pengajuan',
            sortable: true,
            render: (row) => (
                <Link
                    href={route('shm.applications.show', row.id)}
                    className="font-medium text-bsm-primary hover:underline"
                >
                    {row.application_number}
                </Link>
            ),
        },
        {
            key: 'created_at',
            label: 'Tanggal Pengajuan',
            sortable: true,
            render: (row) => (
                <span className="text-slate-600 text-xs">
                    {formatDateTime(row.created_at)}
                </span>
            ),
        },
        {
            key: 'customer_name',
            label: 'Nama Customer',
            sortable: false,
            render: (row) => (
                <div>
                    <span className="font-medium text-slate-800">{row.customer_name}</span>
                    <span className="block text-xs text-slate-400">{row.customer_number}</span>
                </div>
            ),
        },
        {
            key: 'customer_nik',
            label: 'NIK',
            sortable: false,
            render: (row) => (
                <span className="font-mono text-xs text-slate-600">{row.customer_nik}</span>
            ),
        },
        {
            key: 'type',
            label: 'Tipe Pengajuan',
            sortable: true,
            render: (row) => (
                <span
                    className={`rounded-full px-2.5 py-0.5 text-xs font-semibold uppercase ${
                        row.type === 'buyback'
                            ? 'bg-indigo-50 text-indigo-700 border border-indigo-200'
                            : 'bg-blue-50 text-blue-700 border border-blue-200'
                    }`}
                >
                    {row.type_label}
                </span>
            ),
        },
        {
            key: 'status',
            label: 'Status Pengajuan',
            sortable: true,
            render: (row) => (
                <span className="rounded-full bg-slate-100 px-2 py-1 text-xs font-medium text-slate-700">
                    {row.status_label}
                </span>
            ),
        },
        {
            key: 'memorandum_status',
            label: 'Status Memorandum',
            sortable: false,
            render: (row) => {
                if (!row.has_memorandum) {
                    return (
                        <span className="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-500 border border-slate-200">
                            Belum Dibuat
                        </span>
                    );
                }

                return (
                    <div className="flex items-center gap-1.5 flex-wrap">
                        <StatusBadge
                            status={
                                row.memorandum.status === 'approved'
                                    ? 'COMPLETED'
                                    : row.memorandum.status === 'under_review'
                                    ? 'PROCESSING'
                                    : row.memorandum.status === 'submitted'
                                    ? 'PENDING'
                                    : 'DRAFT'
                            }
                            label={row.memorandum.status_label}
                        />
                        {row.memorandum.has_been_rejected && row.memorandum.status === 'draft' && (
                            <span
                                className="inline-flex items-center rounded bg-rose-50 px-1.5 py-0.5 text-[10px] font-semibold text-rose-700 border border-rose-200"
                                title={row.memorandum.rejection_notes ? `Alasan: ${row.memorandum.rejection_notes}` : 'Pernah ditolak'}
                            >
                                Pernah Ditolak
                            </span>
                        )}
                        {row.memorandum.buyback_documents_ratio && (
                            <span className="inline-flex items-center rounded bg-indigo-50 px-1.5 py-0.5 text-[10px] font-semibold text-indigo-700 border border-indigo-200" title="Kelengkapan Dokumen Legalitas Buyback">
                                Dok: {row.memorandum.buyback_documents_ratio}
                            </span>
                        )}
                    </div>
                );
            },
        },
        {
            key: 'memorandum_date',
            label: 'Tanggal Memorandum',
            sortable: false,
            render: (row) => (
                <span className="text-slate-600 text-xs">
                    {row.has_memorandum ? formatDateTime(row.memorandum.created_at) : '-'}
                </span>
            ),
        },
        {
            key: 'actions',
            label: 'Aksi',
            sortable: false,
            render: (row) => (
                <div className="flex items-center gap-1.5 flex-wrap">
                    {/* Aksi Detail Pengajuan */}
                    <Link
                        href={route('shm.applications.show', row.id)}
                        className="inline-flex items-center gap-1 rounded px-2 py-1 text-xs font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900"
                        title="Buka Detail Pengajuan"
                    >
                        <Eye className="h-3.5 w-3.5" />
                        Pengajuan
                    </Link>

                    {/* Aksi Edit Pengajuan jika masih editable */}
                    {row.is_editable && can_update_application && (
                        <Link
                            href={route('shm.applications.edit', row.id)}
                            className="inline-flex items-center gap-1 rounded px-2 py-1 text-xs font-medium text-bsm-primary hover:bg-blue-50"
                            title="Edit Pengajuan (Draft)"
                        >
                            <Pencil className="h-3.5 w-3.5" />
                            Edit Pengajuan
                        </Link>
                    )}

                    {/* Aksi Memorandum (Detail & Edit jika sudah dibuat) */}
                    {row.has_memorandum && (
                        <>
                            <Link
                                href={
                                    row.memorandum.type === 'buyback'
                                        ? route('shm.memorandums.buyback.show', row.memorandum.id)
                                        : route('shm.memorandums.shm-shgb.show', row.memorandum.id)
                                }
                                className="inline-flex items-center gap-1 rounded px-2 py-1 text-xs font-medium text-indigo-600 hover:bg-indigo-50"
                                title="Buka Detail Memorandum"
                            >
                                <Eye className="h-3.5 w-3.5" />
                                Memorandum
                            </Link>

                            {can_update && (
                                <Link
                                    href={
                                        row.memorandum.type === 'buyback'
                                            ? route('shm.memorandums.buyback.edit', row.memorandum.id)
                                            : route('shm.memorandums.shm-shgb.edit', row.memorandum.id)
                                    }
                                    className="inline-flex items-center gap-1 rounded px-2 py-1 text-xs font-medium text-amber-600 hover:bg-amber-50"
                                    title="Edit Memorandum"
                                >
                                    <Pencil className="h-3.5 w-3.5" />
                                    Edit Memo
                                </Link>
                            )}

                            {can_update && (
                                <button
                                    type="button"
                                    onClick={() =>
                                        handleDeleteMemorandum(
                                            row.memorandum.id,
                                            row.application_number,
                                            row.memorandum.type,
                                        )
                                    }
                                    className="inline-flex items-center gap-1 rounded px-2 py-1 text-xs font-medium text-rose-600 hover:bg-rose-50"
                                    title="Hapus Memorandum"
                                >
                                    <Trash2 className="h-3.5 w-3.5" />
                                    Hapus Memo
                                </button>
                            )}
                        </>
                    )}

                    {/* Notice jika pengajuan tidak dapat dihapus */}
                    {!row.is_editable && (
                        <button
                            type="button"
                            onClick={handleDeleteApplicationNotice}
                            className="inline-flex items-center p-1 text-slate-300 hover:text-slate-400"
                            title="Pengajuan terkunci (tidak dapat dihapus)"
                        >
                            <AlertCircle className="h-3.5 w-3.5" />
                        </button>
                    )}
                </div>
            ),
        },
    ];

    return (
        <ShmLayout title="Summary Memorandum & Pengajuan">
            <Head title="Summary Memorandum & Pengajuan" />

            <div className="space-y-6">
                <div>
                    <h1 className="text-xl font-semibold text-gray-800">
                        Summary Memorandum & Pengajuan
                    </h1>
                    <p className="text-sm text-gray-500">
                        Pusat pemantauan dan pengelolaan terintegrasi seluruh berkas pengajuan SHM/SHGB dan Buyback beserta status memorandumnya.
                    </p>
                </div>

                {flash?.success && (
                    <div className="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">
                        {flash.success}
                    </div>
                )}

                {/* KPI Summary Cards */}
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
                    <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <div className="flex items-center justify-between">
                            <span className="text-xs font-semibold text-slate-500 uppercase tracking-wider">
                                Total Pengajuan
                            </span>
                            <div className="rounded-lg bg-slate-100 p-2 text-slate-700">
                                <Layers className="h-4 w-4" />
                            </div>
                        </div>
                        <div className="mt-2">
                            <span className="text-2xl font-bold text-slate-900">
                                {stats?.total_applications ?? 0}
                            </span>
                            <span className="ml-1 text-xs text-slate-500">berkas</span>
                        </div>
                        <p className="mt-1 text-[11px] text-slate-400">Seluruh pengajuan terdaftar</p>
                    </div>

                    <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <div className="flex items-center justify-between">
                            <span className="text-xs font-semibold text-slate-500 uppercase tracking-wider">
                                Total SHM/SHGB
                            </span>
                            <div className="rounded-lg bg-blue-50 p-2 text-bsm-primary">
                                <FileSpreadsheet className="h-4 w-4" />
                            </div>
                        </div>
                        <div className="mt-2">
                            <span className="text-2xl font-bold text-slate-900">
                                {stats?.total_shgb ?? 0}
                            </span>
                            <span className="ml-1 text-xs text-slate-500">pengajuan</span>
                        </div>
                        <p className="mt-1 text-[11px] text-slate-400">Pengajuan tipe SHGB/SHM</p>
                    </div>

                    <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <div className="flex items-center justify-between">
                            <span className="text-xs font-semibold text-slate-500 uppercase tracking-wider">
                                Total Buyback
                            </span>
                            <div className="rounded-lg bg-indigo-50 p-2 text-indigo-600">
                                <RefreshCw className="h-4 w-4" />
                            </div>
                        </div>
                        <div className="mt-2">
                            <span className="text-2xl font-bold text-slate-900">
                                {stats?.total_buyback ?? 0}
                            </span>
                            <span className="ml-1 text-xs text-slate-500">pengajuan</span>
                        </div>
                        <p className="mt-1 text-[11px] text-slate-400">Pengajuan tipe Buyback</p>
                    </div>

                    <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <div className="flex items-center justify-between">
                            <span className="text-xs font-semibold text-slate-500 uppercase tracking-wider">
                                Memiliki Memorandum
                            </span>
                            <div className="rounded-lg bg-emerald-50 p-2 text-emerald-600">
                                <CheckCircle2 className="h-4 w-4" />
                            </div>
                        </div>
                        <div className="mt-2">
                            <span className="text-2xl font-bold text-slate-900">
                                {stats?.with_memorandum ?? 0}
                            </span>
                            <span className="ml-1 text-xs text-slate-500">berkas</span>
                        </div>
                        <p className="mt-1 text-[11px] text-emerald-600 font-medium">Sudah dikonversi</p>
                    </div>

                    <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <div className="flex items-center justify-between">
                            <span className="text-xs font-semibold text-slate-500 uppercase tracking-wider">
                                Belum Memorandum
                            </span>
                            <div className="rounded-lg bg-amber-50 p-2 text-amber-600">
                                <Clock className="h-4 w-4" />
                            </div>
                        </div>
                        <div className="mt-2">
                            <span className="text-2xl font-bold text-slate-900">
                                {stats?.without_memorandum ?? 0}
                            </span>
                            <span className="ml-1 text-xs text-slate-500">berkas</span>
                        </div>
                        <p className="mt-1 text-[11px] text-amber-600 font-medium">Belum / dalam proses</p>
                    </div>
                </div>

                {/* Filter and Search Box */}
                <div className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                    <form onSubmit={applyFilters} className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5 items-end">
                        <div>
                            <label className="block text-xs font-medium text-slate-600 mb-1">
                                Pencarian
                            </label>
                            <input
                                type="text"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Cari No. Pengajuan, Nama, NIK..."
                                className="w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-bsm-primary focus:ring-bsm-primary"
                            />
                        </div>

                        <div>
                            <label className="block text-xs font-medium text-slate-600 mb-1">
                                Tipe Pengajuan
                            </label>
                            <select
                                value={type}
                                onChange={(e) => setType(e.target.value)}
                                className="w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-bsm-primary focus:ring-bsm-primary"
                            >
                                <option value="">Semua Tipe</option>
                                {types.map((t) => (
                                    <option key={t.value} value={t.value}>
                                        {t.label}
                                    </option>
                                ))}
                            </select>
                        </div>

                        <div>
                            <label className="block text-xs font-medium text-slate-600 mb-1">
                                Status Pengajuan
                            </label>
                            <select
                                value={status}
                                onChange={(e) => setStatus(e.target.value)}
                                className="w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-bsm-primary focus:ring-bsm-primary"
                            >
                                <option value="">Semua Status Pengajuan</option>
                                {application_statuses.map((s) => (
                                    <option key={s.value} value={s.value}>
                                        {s.label}
                                    </option>
                                ))}
                            </select>
                        </div>

                        <div>
                            <label className="block text-xs font-medium text-slate-600 mb-1">
                                Status Memorandum
                            </label>
                            <select
                                value={memorandumStatus}
                                onChange={(e) => setMemorandumStatus(e.target.value)}
                                className="w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-bsm-primary focus:ring-bsm-primary"
                            >
                                <option value="">Semua Status Memorandum</option>
                                {memorandum_statuses.map((m) => (
                                    <option key={m.value} value={m.value}>
                                        {m.label}
                                    </option>
                                ))}
                            </select>
                        </div>

                        <div className="flex items-center gap-2">
                            <PrimaryButton type="submit" className="w-full inline-flex justify-center items-center gap-1">
                                <Filter className="h-4 w-4" />
                                Filter
                            </PrimaryButton>
                            {(search || type || status || memorandumStatus) && (
                                <SecondaryButton
                                    type="button"
                                    onClick={resetFilters}
                                    className="inline-flex justify-center items-center gap-1"
                                >
                                    <RotateCcw className="h-4 w-4" />
                                    Reset
                                </SecondaryButton>
                            )}
                        </div>
                    </form>
                </div>

                {/* Table Data */}
                <ReportTable
                    columns={columns}
                    rows={items.data}
                    pagination={items}
                    sort={sort}
                    onSort={handleSort}
                    routeName="shm.memorandums.summary.index"
                    emptyTitle="Tidak Ada Data Pengajuan"
                    emptyDescription="Tidak ditemukan data pengajuan atau memorandum yang sesuai dengan kriteria filter."
                />
            </div>
        </ShmLayout>
    );
}
