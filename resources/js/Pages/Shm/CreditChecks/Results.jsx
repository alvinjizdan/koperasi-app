import React, { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import ReportTable from '@/Components/Admin/ReportTable';
import StatusBadge from '@/Components/Admin/StatusBadge';
import useTableSort from '@/hooks/useTableSort';
import { formatDateTime } from '@/utils/format';
import { Eye, Filter, RotateCcw } from 'lucide-react';

/**
 * Credit Checking → Hasil Marketing.
 *
 * Hanya menampilkan credit checking yang sudah SELESAI. Data yang masih
 * berjalan tidak pernah muncul di sini supaya tidak terbaca sebagai hasil
 * final. Penjagaan utama ada di server (query hanya mengambil status completed).
 *
 * Hasil di sini adalah hasil pencatatan manual, bukan keputusan kelayakan dan
 * tidak mengubah status pengajuan.
 */
export default function Results({ results, sort, filters, outcomes, checkers, flash }) {
    const handleSort = useTableSort('shm.credit-checks.results');

    const [search, setSearch] = useState(filters.search || '');
    const [outcome, setOutcome] = useState(filters.outcome || 'all');
    const [checkerId, setCheckerId] = useState(filters.checker_id || 'all');
    const [dateFrom, setDateFrom] = useState(filters.date_from || '');
    const [dateTo, setDateTo] = useState(filters.date_to || '');

    const applyFilters = (event) => {
        event.preventDefault();
        router.get(
            route('shm.credit-checks.results'),
            {
                search: search || undefined,
                outcome: outcome !== 'all' ? outcome : undefined,
                checker_id: checkerId !== 'all' ? checkerId : undefined,
                date_from: dateFrom || undefined,
                date_to: dateTo || undefined,
            },
            { preserveState: true, replace: true },
        );
    };

    const resetFilters = () => {
        setSearch('');
        setOutcome('all');
        setCheckerId('all');
        setDateFrom('');
        setDateTo('');
        router.get(route('shm.credit-checks.results'), {}, { preserveState: true, replace: true });
    };

    const columns = [
        {
            key: 'application_number',
            label: 'No. Pengajuan',
            sortable: true,
            render: (row) => (
                <Link
                    href={route('shm.applications.show', row.application_id)}
                    className="font-medium text-indigo-600 hover:underline"
                >
                    {row.application_number}
                </Link>
            ),
        },
        {
            key: 'customer',
            label: 'Nasabah',
            sortable: true,
            render: (row) => (
                <div>
                    <p className="font-medium text-slate-700">{row.customer_name ?? '-'}</p>
                    <p className="text-[11px] text-slate-400">{row.customer_number ?? '-'}</p>
                </div>
            ),
        },
        {
            key: 'outcome',
            label: 'Hasil',
            sortable: true,
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
            key: 'completed_at',
            label: 'Selesai',
            sortable: true,
            render: (row) => (row.completed_at ? formatDateTime(row.completed_at) : '-'),
        },
        {
            key: 'result',
            label: 'Ringkasan Hasil',
            render: (row) => (
                <span className="line-clamp-2 max-w-sm text-slate-600">{row.result || '-'}</span>
            ),
        },
        {
            key: 'action',
            label: 'Aksi',
            sortable: false,
            render: (row) => (
                <Link
                    href={route('shm.credit-checks.show', row.id)}
                    className="inline-flex items-center gap-1 rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 transition-colors hover:bg-slate-50"
                >
                    <Eye className="h-3.5 w-3.5" />
                    Lihat
                </Link>
            ),
        },
    ];

    return (
        <ShmLayout title="Hasil Credit Checking">
            <div className="space-y-6">
                <div>
                    <h1 className="text-xl font-semibold text-gray-800">
                        Credit Checking — Hasil Marketing
                    </h1>
                    <p className="text-sm text-gray-500">
                        Hanya credit checking yang sudah selesai. Hasil dicatat manual dan tidak
                        mengubah status pengajuan.
                    </p>
                </div>

                {flash?.success && (
                    <div className="rounded-md bg-green-50 px-4 py-3 text-sm text-green-800">
                        {flash.success}
                    </div>
                )}

                <form
                    onSubmit={applyFilters}
                    className="grid grid-cols-1 gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-xs sm:grid-cols-2 lg:grid-cols-5"
                >
                    <div>
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
                            Selesai dari
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

                    <div className="flex flex-wrap items-end gap-2 sm:col-span-2 lg:col-span-5">
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
                            Rentang tanggal mengikuti waktu penyelesaian checking.
                        </p>
                    </div>
                </form>

                <ReportTable
                    columns={columns}
                    rows={results.data}
                    pagination={results}
                    sort={sort}
                    onSort={handleSort}
                    routeName="shm.credit-checks.results"
                    emptyTitle="Belum ada hasil credit checking"
                    emptyDescription="Hasil akan tampil setelah credit checking diselesaikan."
                />
            </div>
        </ShmLayout>
    );
}
