import React, { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import ReportTable from '@/Components/Admin/ReportTable';
import ConfirmModal from '@/Components/Admin/ConfirmModal';
import PrimaryButton from '@/Components/PrimaryButton';
import useTableSort from '@/hooks/useTableSort';
import { formatDateTime } from '@/utils/format';
import { Filter, Pencil, Plus, Search, Trash2, CheckCircle, XCircle } from 'lucide-react';

/**
 * Daftar Master Agen SHM / BSM Makro.
 */
export default function Index({ agents, sort, filters, permissions, flash }) {
    const handleSort = useTableSort('shm.agents.index');

    const [search, setSearch] = useState(filters.search || '');
    const [status, setStatus] = useState(filters.status || 'all');
    const [deleteTarget, setDeleteTarget] = useState(null);
    const [isDeleting, setIsDeleting] = useState(false);

    const confirmDelete = () => {
        if (!deleteTarget) return;
        setIsDeleting(true);
        router.delete(route('shm.agents.destroy', deleteTarget.id), {
            onSuccess: () => {
                setDeleteTarget(null);
                setIsDeleting(false);
            },
            onError: () => setIsDeleting(false),
            onFinish: () => setIsDeleting(false),
        });
    };

    const toggleStatus = (agent) => {
        const nextStatus = !agent.is_active;
        const actionLabel = nextStatus ? 'mengaktifkan' : 'menonaktifkan';

        if (window.confirm(`Apakah Anda yakin ingin ${actionLabel} agen ${agent.name} (${agent.code})?`)) {
            router.patch(route('shm.agents.toggle-active', agent.id), {
                is_active: nextStatus,
            }, {
                preserveScroll: true,
            });
        }
    };

    const applyFilters = (event) => {
        event.preventDefault();
        router.get(
            route('shm.agents.index'),
            {
                search: search || undefined,
                status: status !== 'all' ? status : undefined,
            },
            { preserveState: true, replace: true },
        );
    };

    const columns = [
        {
            key: 'code',
            label: 'Kode Agen',
            sortable: true,
            render: (row) => (
                <span className="font-semibold text-slate-800">
                    {row.code}
                </span>
            ),
        },
        { key: 'name', label: 'Nama Lengkap', sortable: true },
        {
            key: 'nik',
            label: 'NIK',
            sortable: true,
            render: (row) => <span className="font-mono">{row.nik || '-'}</span>,
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
            label: 'Terdaftar',
            sortable: true,
            render: (row) => formatDateTime(row.created_at),
        },
        {
            key: 'actions',
            label: 'Aksi',
            render: (row) => (
                <div className="flex items-center gap-1">
                    {permissions?.can_update && (
                        <button
                            type="button"
                            title={row.is_active ? 'Nonaktifkan' : 'Aktifkan'}
                            onClick={() => toggleStatus(row)}
                            className={`rounded-md p-1.5 ${
                                row.is_active
                                    ? 'text-slate-500 hover:bg-amber-50 hover:text-amber-600'
                                    : 'text-slate-500 hover:bg-emerald-50 hover:text-emerald-600'
                            }`}
                        >
                            {row.is_active ? (
                                <XCircle className="h-4 w-4" />
                            ) : (
                                <CheckCircle className="h-4 w-4" />
                            )}
                        </button>
                    )}
                    {permissions?.can_update && (
                        <Link
                            href={route('shm.agents.edit', row.id)}
                            title="Edit"
                            className="rounded-md p-1.5 text-slate-500 hover:bg-slate-100 hover:text-indigo-600"
                        >
                            <Pencil className="h-4 w-4" />
                        </Link>
                    )}
                    {permissions?.can_delete && (
                        <button
                            type="button"
                            title="Hapus"
                            onClick={() => setDeleteTarget(row)}
                            className="rounded-md p-1.5 text-slate-500 hover:bg-rose-50 hover:text-rose-600"
                        >
                            <Trash2 className="h-4 w-4" />
                        </button>
                    )}
                </div>
            ),
        },
    ];

    return (
        <ShmLayout title="Master Agen SHM" pageTitle="Master Agen BSM Makro">
            <div className="space-y-6">
                {flash?.success && (
                    <div className="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                        {flash.success}
                    </div>
                )}
                {flash?.error && (
                    <div className="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                        {flash.error}
                    </div>
                )}

                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-xl font-bold text-slate-900">
                            Master Agen SHM
                        </h1>
                        <p className="mt-1 text-sm text-slate-500">
                            Kelola data agen yang mereferensikan nasabah pinjaman SHM.
                        </p>
                    </div>

                    {permissions?.can_create && (
                        <Link href={route('shm.agents.create')}>
                            <PrimaryButton className="gap-2">
                                <Plus className="h-4 w-4" />
                                Tambah Agen
                            </PrimaryButton>
                        </Link>
                    )}
                </div>

                <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <form
                        onSubmit={applyFilters}
                        className="grid grid-cols-1 gap-3 md:grid-cols-4"
                    >
                        <div className="relative md:col-span-2">
                            <Search className="pointer-events-none absolute left-3 top-2.5 h-4 w-4 text-slate-400" />
                            <input
                                type="text"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Cari kode, nama, atau NIK agen..."
                                className="w-full rounded-lg border border-slate-300 py-2 pl-9 pr-3 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                            />
                        </div>

                        <div>
                            <select
                                value={status}
                                onChange={(e) => setStatus(e.target.value)}
                                className="w-full rounded-lg border border-slate-300 py-2 pl-3 pr-8 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                            >
                                <option value="all">Semua Status</option>
                                <option value="active">Aktif</option>
                                <option value="inactive">Nonaktif</option>
                            </select>
                        </div>

                        <div className="flex gap-2">
                            <button
                                type="submit"
                                className="inline-flex flex-1 items-center justify-center gap-1.5 rounded-lg bg-slate-800 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700"
                            >
                                <Filter className="h-4 w-4" />
                                Filter
                            </button>
                            {(filters.search || filters.status !== 'all') && (
                                <Link
                                    href={route('shm.agents.index')}
                                    className="inline-flex items-center justify-center rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-600 hover:bg-slate-50"
                                >
                                    Reset
                                </Link>
                            )}
                        </div>
                    </form>
                </div>

                <ReportTable
                    columns={columns}
                    rows={agents.data}
                    pagination={agents}
                    sort={sort}
                    onSort={handleSort}
                    emptyTitle="Belum ada agen"
                    emptyDescription="Tambahkan agen baru untuk mulai menugaskan referensi pada pengajuan pinjaman SHM."
                />

                <ConfirmModal
                    isOpen={deleteTarget !== null}
                    onClose={() => setDeleteTarget(null)}
                    onConfirm={confirmDelete}
                    title="Hapus Agen?"
                    message={`Apakah Anda yakin ingin menghapus agen ${deleteTarget?.name} (${deleteTarget?.code})? Tindakan ini tidak dapat dibatalkan.`}
                    confirmText="Hapus Permanen"
                    cancelText="Batal"
                    variant="danger"
                    isLoading={isDeleting}
                />
            </div>
        </ShmLayout>
    );
}

