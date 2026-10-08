import React, { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import ReportTable from '@/Components/Admin/ReportTable';
import StatusBadge from '@/Components/Admin/StatusBadge';
import useTableSort from '@/hooks/useTableSort';
import { formatDateTime } from '@/utils/format';
import { Gavel, Filter, RotateCcw, Search } from 'lucide-react';

/**
 * Peta warna badge untuk tahap pengajuan pada modul approval.
 *
 * Kunci mengikuti StatusBadge existing; tidak ada warna baru. Hanya tahap
 * keputusan (approval/approved/rejected) yang relevan untuk modul ini.
 */
const APPLICATION_BADGES = {
    approval: 'REVIEW',
    approved: 'COMPLETED',
    rejected: 'FAILED',
};

/**
 * Peta warna badge untuk hasil keputusan yang tercatat pada shm_approvals
 * (pending/approved/rejected — struktur existing Ticket 44).
 */
const DECISION_BADGES = {
    pending: 'PENDING',
    approved: 'COMPLETED',
    rejected: 'FAILED',
};

const DECISION_LABELS = {
    pending: 'Menunggu Keputusan',
    approved: 'Disetujui',
    rejected: 'Ditolak',
};

/**
 * Modul Approval operasional (Ticket 54).
 *
 * Daftar pengajuan yang relevan untuk keputusan approval. Bawaan menampilkan
 * pengajuan pada tahap approval; filter status memungkinkan melihat hasil
 * keputusan. Seluruh keputusan dilakukan dari halaman detail dan tetap lewat
 * workflow existing — halaman ini hanya membaca.
 *
 * Tidak ada kolom nominal karena tabel pengajuan SHM memang tidak memiliki
 * kolom finansial; tidak ada data yang dikarang.
 *
 * Ticket 58: ringkasan antrean approval PER MARKETING ditampilkan di atas
 * daftar ini. Source of truth-nya adalah ShmApproval.status = pending yang
 * di-aggregate per marketing_id (T58), scope-safe dan read-only; aksi
 * tetap ke halaman approval existing (shm.approvals.index).
 */
export default function Index({ approvals, sort, filters, statuses, types, pending_count, marketing_queue, flash }) {
    const handleSort = useTableSort('shm.approvals.index');

    const [search, setSearch] = useState(filters.search || '');
    const [status, setStatus] = useState(filters.status || 'approval');
    const [type, setType] = useState(filters.type || 'all');

    const applyFilters = (event) => {
        event.preventDefault();
        router.get(
            route('shm.approvals.index'),
            {
                search: search || undefined,
                status: status !== 'approval' ? status : undefined,
                type: type !== 'all' ? type : undefined,
            },
            { preserveState: true, replace: true },
        );
    };

    const resetFilters = () => {
        setSearch('');
        setStatus('approval');
        setType('all');
        router.get(route('shm.approvals.index'), {}, { preserveState: true, replace: true });
    };

    const columns = [
        {
            key: 'application_number',
            label: 'No. Pengajuan',
            sortable: true,
            render: (row) => (
                <Link
                    href={route('shm.approvals.show', row.id)}
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
            label: 'Tahap Pengajuan',
            sortable: true,
            render: (row) => (
                <StatusBadge
                    status={APPLICATION_BADGES[row.status] ?? 'PENDING'}
                    label={row.status_label}
                />
            ),
        },
        {
            key: 'decision',
            label: 'Keputusan',
            render: (row) => {
                if (!row.decision) {
                    return <span className="text-xs text-slate-400">Belum diputuskan</span>;
                }

                return (
                    <div>
                        <StatusBadge
                            status={DECISION_BADGES[row.decision.status] ?? 'PENDING'}
                            label={DECISION_LABELS[row.decision.status] ?? row.decision.status}
                        />
                        {row.decision.decider && (
                            <p className="mt-0.5 text-[11px] text-slate-400">
                                {row.decision.decider}
                            </p>
                        )}
                    </div>
                );
            },
        },
        {
            key: 'submitted_at',
            label: 'Tanggal Pengajuan',
            sortable: true,
            render: (row) => (row.submitted_at ? formatDateTime(row.submitted_at) : '-'),
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
                    href={route('shm.approvals.show', row.id)}
                    className="text-xs font-semibold text-indigo-600 hover:underline"
                >
                    Detail
                </Link>
            ),
        },
    ];

    return (
        <ShmLayout title="Approval">
            <div className="space-y-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="text-xl font-semibold text-gray-800">Approval</h1>
                        <p className="text-sm text-gray-500">
                            Pengajuan pada tahap keputusan approval. Keputusan memakai workflow
                            yang sudah ada; tidak ada approval berjenjang, pagu nominal, atau
                            aturan baru pada modul ini.
                        </p>
                    </div>
                    {/* Ticket 57: penekanan pekerjaan pending. Angka berasal dari
                        ShmApproval.status = pending (source of truth existing),
                        scope-safe, read-only; klik mengarah ke filter bawaan
                        "Menunggu Keputusan" — tanpa endpoint atau action baru. */}
                    <div className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3">
                        <p className="text-[11px] font-semibold uppercase tracking-wide text-amber-700">
                            Menunggu Keputusan
                        </p>
                        <p className="mt-0.5 flex items-baseline gap-1">
                            <span className="text-2xl font-bold text-amber-900">
                                {typeof pending_count === 'number' && pending_count >= 0
                                    ? pending_count
                                    : 0}
                            </span>
                            <span className="text-xs text-amber-700">pengajuan</span>
                        </p>
                        <button
                            type="button"
                            onClick={() => {
                                setStatus('approval');
                                setSearch('');
                                setType('all');
                                router.get(
                                    route('shm.approvals.index'),
                                    {},
                                    { preserveState: true, replace: true },
                                );
                            }}
                            className="mt-1 inline-flex items-center gap-1 text-[11px] font-semibold text-amber-800 underline-offset-2 transition-colors hover:text-amber-950 hover:underline"
                        >
                            Lihat antrean pending
                        </button>
                    </div>
                </div>

                {/* Ticket 58: ringkasan antrean approval per marketing. */}
                {typeof marketing_queue === 'object' && marketing_queue !== null && (
                    <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-xs">
                        <div className="mb-4 flex items-center justify-between">
                            <h2 className="flex items-center gap-2 text-sm font-semibold uppercase text-slate-600">
                                <Gavel className="h-4 w-4" />
                                Antrean Approval per Marketing
                            </h2>
                            <Link
                                href={route('shm.approvals.index')}
                                className="text-xs font-semibold text-indigo-600 hover:underline"
                            >
                                Buka antrean
                            </Link>
                        </div>

                        {marketing_queue.length === 0 ? (
                            <div className="rounded-lg border border-dashed border-slate-200 px-4 py-6 text-center">
                                <p className="text-sm text-slate-500">
                                    Tidak ada approval pending.
                                </p>
                            </div>
                        ) : (
                            <ReportTable
                                columns={[
                                    {
                                        key: 'name',
                                        label: 'Marketing',
                                        sortable: false,
                                        render: (row) => (
                                            <span className="font-medium text-slate-800">
                                                {row.name}
                                            </span>
                                        ),
                                    },
                                    {
                                        key: 'pending_count',
                                        label: 'Pending Approval',
                                        sortable: false,
                                        align: 'right',
                                        render: (row) => (
                                            <span className="font-semibold text-slate-900">
                                                {row.pending_count}
                                            </span>
                                        ),
                                    },
                                    {
                                        key: 'action',
                                        label: 'Aksi',
                                        sortable: false,
                                        render: () => (
                                            <Link
                                                href={route('shm.approvals.index')}
                                                className="text-xs font-semibold text-indigo-600 hover:underline"
                                            >
                                                Lihat Antrean
                                            </Link>
                                        ),
                                    },
                                ]}
                                rows={marketing_queue}
                                sort={null}
                                onSort={() => {}}
                                routeName="shm.approvals.index"
                                emptyIcon={Gavel}
                                emptyTitle="Tidak ada approval pending"
                                emptyDescription="Tidak ada approval yang menunggu keputusan pada cakupan data Anda."
                            />
                        )}
                    </div>
                )}

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
                                placeholder="No. pengajuan, nama/nomor nasabah"
                                className="placeholder-slate-400 peer block w-full rounded-lg border border-slate-300 py-2 pl-9 pr-3 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                            />
                        </div>
                    </div>
                    <div>
                        <label className="block text-xs font-medium text-slate-600">Tahap</label>
                        <select
                            value={status}
                            onChange={(event) => setStatus(event.target.value)}
                            className="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                        >
                            <option value="approval">Menunggu Keputusan</option>
                            {statuses.map((item) => (
                                <option key={item.value} value={item.value}>
                                    {item.label}
                                </option>
                            ))}
                            <option value="all">Semua tahap keputusan</option>
                        </select>
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
                    rows={approvals.data}
                    pagination={approvals}
                    sort={sort}
                    onSort={handleSort}
                    routeName="shm.approvals.index"
                    emptyIcon={Gavel}
                    emptyTitle="Belum ada pengajuan untuk diputuskan"
                    emptyDescription="Tidak ada pengajuan pada tahap approval yang sesuai dengan filter ini."
                />
            </div>
        </ShmLayout>
    );
}
