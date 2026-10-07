import React, { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import ReportTable from '@/Components/Admin/ReportTable';
import StatusBadge from '@/Components/Admin/StatusBadge';
import ConfirmModal from '@/Components/Admin/ConfirmModal';
import useTableSort from '@/hooks/useTableSort';
import { formatDateTime } from '@/utils/format';
import { Filter, Play, RotateCcw, Eye, ClipboardCheck } from 'lucide-react';

/**
 * Credit Checking → Proses Marketing.
 *
 * Daftar berbasis PENGAJUAN supaya pengajuan yang belum memiliki credit
 * checking tetap muncul dengan aksi "Mulai Checking". Tidak ada baris credit
 * checking dummy yang dibuat otomatis.
 *
 * Ini workflow pencatatan manual, BUKAN integrasi BI Checking/SLIK dan bukan
 * penilaian kredit.
 */
export default function Index({
    credit_checks,
    sort,
    filters,
    statuses,
    outcomes,
    checkers,
    can_update,
    flash,
}) {
    const handleSort = useTableSort('shm.credit-checks.index');

    const [search, setSearch] = useState(filters.search || '');
    const [status, setStatus] = useState(filters.status || 'all');
    const [outcome, setOutcome] = useState(filters.outcome || 'all');
    const [checkerId, setCheckerId] = useState(filters.checker_id || 'all');
    const [dateFrom, setDateFrom] = useState(filters.date_from || '');
    const [dateTo, setDateTo] = useState(filters.date_to || '');
    const [pendingStart, setPendingStart] = useState(null);

    const buildParams = () => ({
        search: search || undefined,
        status: status !== 'all' ? status : undefined,
        outcome: outcome !== 'all' ? outcome : undefined,
        checker_id: checkerId !== 'all' ? checkerId : undefined,
        date_from: dateFrom || undefined,
        date_to: dateTo || undefined,
    });

    const applyFilters = (event) => {
        event.preventDefault();
        router.get(route('shm.credit-checks.index'), buildParams(), {
            preserveState: true,
            replace: true,
        });
    };

    const resetFilters = () => {
        setSearch('');
        setStatus('all');
        setOutcome('all');
        setCheckerId('all');
        setDateFrom('');
        setDateTo('');
        router.get(route('shm.credit-checks.index'), {}, { preserveState: true, replace: true });
    };

    const startChecking = () => {
        if (!pendingStart) return;

        router.post(
            route('shm.applications.credit-checking.start', pendingStart.application_id),
            {},
            {
                preserveScroll: true,
                onFinish: () => setPendingStart(null),
            },
        );
    };

    const columns = [
        {
            key: 'application_number',
            label: 'No. Pengajuan',
            sortable: true,
            render: (row) => (
                <div>
                    <Link
                        href={route('shm.applications.show', row.application_id)}
                        className="font-medium text-indigo-600 hover:underline"
                    >
                        {row.application_number}
                    </Link>
                    <p className="text-[11px] text-slate-400">
                        Tahap pengajuan: {row.application_status_label}
                    </p>
                </div>
            ),
        },
        {
            key: 'customer',
            label: 'Nasabah',
            sortable: true,
            render: (row) => (
                <div>
                    <p className="font-medium text-slate-700">{row.customer_name ?? '-'}</p>
                    <p className="text-[11px] text-slate-400">
                        {row.customer_number ?? '-'}
                    </p>
                </div>
            ),
        },
        {
            key: 'customer_nik',
            label: 'NIK',
            render: (row) => row.customer_nik || '-',
        },
        {
            key: 'status',
            label: 'Status Checking',
            sortable: true,
            render: (row) => (
                <StatusBadge status={row.status_badge} label={row.status_label} />
            ),
        },
        {
            key: 'outcome',
            label: 'Hasil',
            render: (row) =>
                row.outcome_badge ? (
                    <StatusBadge status={row.outcome_badge} label={row.outcome_label} />
                ) : (
                    <span className="text-slate-400">-</span>
                ),
        },
        {
            key: 'checker',
            label: 'Petugas',
            render: (row) => row.checker || '-',
        },
        {
            key: 'started_at',
            label: 'Mulai',
            sortable: true,
            render: (row) => (row.started_at ? formatDateTime(row.started_at) : '-'),
        },
        {
            key: 'completed_at',
            label: 'Selesai',
            render: (row) => (row.completed_at ? formatDateTime(row.completed_at) : '-'),
        },
        {
            key: 'updated_at',
            label: 'Diperbarui',
            sortable: true,
            render: (row) =>
                row.updated_at ? formatDateTime(row.updated_at) : '-',
        },
        {
            key: 'action',
            label: 'Aksi',
            sortable: false,
            render: (row) => (
                <div className="flex flex-wrap items-center gap-2">
                    {row.action === 'start' && can_update && (
                        <button
                            type="button"
                            onClick={() => setPendingStart(row)}
                            className="inline-flex items-center gap-1 rounded-lg bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white transition-all hover:bg-slate-800 active:scale-95"
                        >
                            <Play className="h-3.5 w-3.5" />
                            Mulai Checking
                        </button>
                    )}
                    {row.action === 'continue' && row.credit_check_id && (
                        <Link
                            href={route('shm.credit-checks.show', row.credit_check_id)}
                            className="inline-flex items-center gap-1 rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 transition-colors hover:bg-slate-50"
                        >
                            <ClipboardCheck className="h-3.5 w-3.5" />
                            Lanjutkan
                        </Link>
                    )}
                    {row.action === 'view' && row.credit_check_id && (
                        <Link
                            href={route('shm.credit-checks.show', row.credit_check_id)}
                            className="inline-flex items-center gap-1 rounded-lg border border-emerald-300 bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-800 transition-colors hover:bg-emerald-100"
                        >
                            <Eye className="h-3.5 w-3.5" />
                            Lihat Hasil
                        </Link>
                    )}
                </div>
            ),
        },
    ];

    return (
        <ShmLayout title="Proses Credit Checking">
            <div className="space-y-6">
                <div>
                    <h1 className="text-xl font-semibold text-gray-800">
                        Credit Checking — Proses Marketing
                    </h1>
                    <p className="text-sm text-gray-500">
                        Workflow pencatatan manual: belum dimulai → sedang berjalan → selesai.
                        Bukan integrasi BI Checking/SLIK dan bukan penilaian kredit.
                    </p>
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
                    className="grid grid-cols-1 gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-xs sm:grid-cols-2 lg:grid-cols-6"
                >
                    <div className="lg:col-span-2">
                        <label className="block text-xs font-medium text-slate-600">Cari</label>
                        <input
                            type="text"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="No. pengajuan, nama, nomor nasabah, atau NIK"
                            className="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                        />
                    </div>
                    <div>
                        <label className="block text-xs font-medium text-slate-600">Status</label>
                        <select
                            value={status}
                            onChange={(event) => setStatus(event.target.value)}
                            className="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                        >
                            {statuses.map((item) => (
                                <option key={item.value} value={item.value}>
                                    {item.label}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div>
                        <label className="block text-xs font-medium text-slate-600">Hasil</label>
                        <select
                            value={outcome}
                            onChange={(event) => setOutcome(event.target.value)}
                            className="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                        >
                            {outcomes.map((item) => (
                                <option key={item.value} value={item.value}>
                                    {item.label}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div>
                        <label className="block text-xs font-medium text-slate-600">Petugas</label>
                        <select
                            value={checkerId}
                            onChange={(event) => setCheckerId(event.target.value)}
                            className="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                        >
                            <option value="all">Semua petugas</option>
                            {checkers.map((item) => (
                                <option key={item.value} value={item.value}>
                                    {item.label}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div>
                        <label className="block text-xs font-medium text-slate-600">
                            Mulai dari
                        </label>
                        <input
                            type="date"
                            value={dateFrom}
                            onChange={(event) => setDateFrom(event.target.value)}
                            className="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                        />
                    </div>
                    <div>
                        <label className="block text-xs font-medium text-slate-600">Sampai</label>
                        <input
                            type="date"
                            value={dateTo}
                            onChange={(event) => setDateTo(event.target.value)}
                            className="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                        />
                    </div>

                    <div className="flex flex-wrap items-end gap-2 sm:col-span-2 lg:col-span-6">
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
                        <p className="ml-auto text-[11px] text-slate-400">
                            Rentang tanggal mengikuti waktu mulai pengerjaan.
                        </p>
                    </div>
                </form>

                <ReportTable
                    columns={columns}
                    rows={credit_checks.data}
                    pagination={credit_checks}
                    sort={sort}
                    onSort={handleSort}
                    routeName="shm.credit-checks.index"
                    emptyTitle="Belum ada pengajuan pada tahap credit checking"
                    emptyDescription="Pengajuan yang masuk tahap credit checking akan tampil di sini."
                />
            </div>

            <ConfirmModal
                isOpen={pendingStart !== null}
                onClose={() => setPendingStart(null)}
                onConfirm={startChecking}
                title="Mulai Credit Checking"
                message={`Mulai pengerjaan credit checking untuk pengajuan ${
                    pendingStart?.application_number ?? ''
                }? Status akan berubah menjadi sedang berjalan dan waktu mulai dicatat.`}
                confirmText="Ya, Mulai"
            />
        </ShmLayout>
    );
}
