import React, { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import ReportTable from '@/Components/Admin/ReportTable';
import PrimaryButton from '@/Components/PrimaryButton';
import useTableSort from '@/hooks/useTableSort';
import { formatRupiah } from '@/utils/format';
import { FileDown, FileSignature, Plus, Search } from 'lucide-react';

/**
 * Daftar Pengajuan Pinjaman SHM (Ticket 68).
 *
 * Server-side: search nomor pengajuan & nama/nomor nasabah, filter status,
 * sorting whitelist, pagination dengan query string yang bertahan — tidak ada
 * filtering/pagination di React. Kolom Approval dibaca dari baris ShmApproval
 * existing (null = belum diajuan); action Edit hanya tampil bila pengajuan
 * masih dapat diedit (belum terkunci approval) — keputusan akhir tetap di
 * server.
 */

const STATUS_LABELS = {
    pending: { text: 'BARU', className: 'bg-indigo-50 text-indigo-700' },
    approved: { text: 'DISETUJUI', className: 'bg-emerald-50 text-emerald-700' },
    rejected: { text: 'DITOLAK', className: 'bg-rose-50 text-rose-700' },
};

const APPROVAL_LABELS = {
    pending: { text: 'Menunggu Approval', className: 'bg-amber-50 text-amber-700' },
    approved: { text: 'Disetujui', className: 'bg-emerald-50 text-emerald-700' },
    rejected: { text: 'Ditolak', className: 'bg-rose-50 text-rose-700' },
};

function Chip({ value, labels, fallback }) {
    const found = value ? labels[value] : null;
    const style = found ?? fallback;

    return (
        <span
            className={`inline-block rounded-full px-2 py-1 text-xs font-medium ${style.className}`}
        >
            {found ? found.text : fallback.text}
        </span>
    );
}

export default function PengajuanDaftar({ applications, sort, filters, flash }) {
    const handleSort = useTableSort('shm.loans.pengajuan.daftar');

    const [search, setSearch] = useState(filters.search || '');
    const [status, setStatus] = useState(filters.status || 'all');

    const applyFilters = (event) => {
        event.preventDefault();
        router.get(
            route('shm.loans.pengajuan.daftar'),
            {
                search: search || undefined,
                status: status !== 'all' ? status : undefined,
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
                    href={route('shm.loans.pengajuan.show', row.id)}
                    className="font-medium text-indigo-600 hover:underline"
                >
                    {row.application_number}
                </Link>
            ),
        },
        {
            key: 'application_date',
            label: 'Tanggal Pengajuan',
            sortable: true,
            render: (row) => row.application_date || '-',
        },
        {
            key: 'customer',
            label: 'Nasabah SHM',
            render: (row) => row.customer?.name || '-',
        },
        {
            key: 'product',
            label: 'Produk Pinjaman',
            render: (row) => row.product?.name || '—',
        },
        {
            key: 'principal',
            label: 'Jumlah Pengajuan',
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
            key: 'status',
            label: 'Status',
            sortable: true,
            render: (row) => (
                <Chip
                    value={row.status}
                    labels={STATUS_LABELS}
                    fallback={{ text: row.status, className: 'bg-slate-100 text-slate-600' }}
                />
            ),
        },
        {
            key: 'approval',
            label: 'Approval',
            render: (row) => (
                <Chip
                    value={row.approval_status}
                    labels={APPROVAL_LABELS}
                    fallback={{ text: 'Belum Diajukan', className: 'bg-slate-100 text-slate-500' }}
                />
            ),
        },
        {
            key: 'updated_at',
            label: 'Updated At',
            sortable: true,
            render: (row) =>
                row.updated_at
                    ? new Date(row.updated_at).toLocaleDateString('id-ID')
                    : '-',
        },
        {
            key: 'actions',
            label: 'Aksi',
            render: (row) => (
                <div className="flex flex-wrap items-center gap-2 text-xs">
                    <Link
                        href={route('shm.loans.pengajuan.show', row.id)}
                        className="rounded-md border border-slate-300 px-2 py-1 font-medium text-slate-600 hover:bg-slate-50"
                    >
                        Detail
                    </Link>
                    {row.editable && (
                        <Link
                            href={route('shm.loans.pengajuan.edit', row.id)}
                            className="rounded-md border border-indigo-200 px-2 py-1 font-medium text-indigo-600 hover:bg-indigo-50"
                        >
                            Edit
                        </Link>
                    )}
                    <a
                        href={route('shm.loans.pengajuan.pdf', row.id)}
                        className="rounded-md border border-slate-300 px-2 py-1 font-medium text-slate-600 hover:bg-slate-50"
                    >
                        PDF
                    </a>
                </div>
            ),
        },
    ];

    return (
        <ShmLayout title="Daftar Pengajuan Pinjaman">
            <div className="space-y-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="flex items-center gap-2 text-xl font-semibold text-gray-800">
                            <FileSignature className="h-5 w-5 text-slate-400" />
                            Daftar Pengajuan Pinjaman
                        </h1>
                        <p className="text-sm text-gray-500">
                            Pengajuan Pinjaman SHM tersimpan. Pencarian &
                            filter diproses di server; keputusan approval tetap
                            melalui modul Approval SHM existing.
                        </p>
                    </div>
                    <Link href={route('shm.loans.pengajuan')}>
                        <PrimaryButton type="button">
                            <Plus className="mr-2 h-4 w-4" />
                            Pengajuan Baru
                        </PrimaryButton>
                    </Link>
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

                <form onSubmit={applyFilters} className="flex flex-wrap items-end gap-3">
                    <div>
                        <label className="text-xs font-semibold text-slate-700">
                            Cari
                        </label>
                        <input
                            type="text"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="No. pengajuan atau nama/nomor nasabah"
                            className="mt-1 block w-72 rounded-lg border border-slate-300 px-3 py-2 text-sm"
                        />
                    </div>
                    <div>
                        <label className="text-xs font-semibold text-slate-700">
                            Status
                        </label>
                        <select
                            value={status}
                            onChange={(event) => setStatus(event.target.value)}
                            className="mt-1 block rounded-lg border border-slate-300 px-3 py-2 text-sm"
                        >
                            <option value="all">Semua status</option>
                            <option value="pending">Pending</option>
                            <option value="approved">Approved</option>
                            <option value="rejected">Rejected</option>
                        </select>
                    </div>
                    <PrimaryButton type="submit">
                        <Search className="mr-2 h-4 w-4" />
                        Cari
                    </PrimaryButton>
                </form>

                <ReportTable
                    columns={columns}
                    rows={applications.data}
                    pagination={applications}
                    sort={sort}
                    onSort={handleSort}
                    routeName="shm.loans.pengajuan.daftar"
                    emptyTitle="Belum ada pengajuan pinjaman"
                    emptyDescription="Buat pengajuan baru, lalu simpan — datanya akan tampil di sini."
                />

                <p className="text-xs text-slate-500">
                    <FileDown className="mr-1 inline h-3 w-3" />
                    Aksi PDF mengekspor pengajuan lengkap (11 section, rate
                    resmi 2%) dengan authorization server-side yang sama.
                </p>
            </div>
        </ShmLayout>
    );
}
