import React, { useState } from 'react';
import { router } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import ReportTable from '@/Components/Admin/ReportTable';
import useTableSort from '@/hooks/useTableSort';
import { formatDateTime } from '@/utils/format';
import { MapPin, Filter, Activity } from 'lucide-react';

/**
 * Monitoring seluruh aktivitas Marketing.
 *
 * Hanya dapat dibuka oleh pemegang permission `marketing.monitoring.view`.
 * Halaman ini memakai pagination server-side (whitelist per_page yang sama
 * dengan halaman lain) sehingga data tidak pernah diambil sekaligus.
 */
export default function Monitoring({ activities, sort, members, marketers, filters, flash, shm }) {
    const handleSort = useTableSort('shm.marketing.monitoring.index');

    const [marketingId, setMarketingId] = useState(filters.marketing_id || '');
    const [memberId, setMemberId] = useState(filters.member_id || '');
    const [dateFrom, setDateFrom] = useState(filters.date_from || '');
    const [dateTo, setDateTo] = useState(filters.date_to || '');

    const applyFilters = (event) => {
        event.preventDefault();
        router.get(
            route('shm.marketing.monitoring.index'),
            {
                marketing_id: marketingId || undefined,
                member_id: memberId || undefined,
                date_from: dateFrom || undefined,
                date_to: dateTo || undefined,
            },
            { preserveState: true, replace: true },
        );
    };

    const columns = [
        {
            key: 'marketing',
            label: 'Marketing',
            render: (row) => row.marketing?.name ?? '-',
        },
        {
            key: 'member',
            label: 'Anggota',
            render: (row) => (
                <div>
                    <p className="font-semibold text-slate-800">{row.member?.full_name ?? '-'}</p>
                    <p className="text-[11px] text-slate-400">{row.member?.member_number ?? '-'}</p>
                </div>
            ),
        },
        {
            key: 'checked_in_at',
            label: 'Waktu Check-in',
            render: (row) => formatDateTime(row.checked_in_at),
        },
        {
            key: 'location',
            label: 'Lokasi',
            sortable: false,
            render: (row) =>
                row.latitude !== null && row.longitude !== null ? (
                    <span className="inline-flex items-center gap-1 text-[11px] text-slate-600">
                        <MapPin className="h-3.5 w-3.5" />
                        {Number(row.latitude).toFixed(5)}, {Number(row.longitude).toFixed(5)}
                    </span>
                ) : (
                    <span className="text-[11px] text-slate-400">Tidak tercatat</span>
                ),
        },
        {
            key: 'result',
            label: 'Hasil',
            render: (row) => row.result || <span className="text-slate-400">Belum diisi</span>,
        },
        {
            key: 'notes',
            label: 'Catatan',
            sortable: false,
            render: (row) => (
                <span className="line-clamp-2 text-[11px] text-slate-500">
                    {row.notes || '-'}
                </span>
            ),
        },
    ];

    return (
        <ShmLayout title="Monitoring Aktivitas" pageTitle="Monitoring Aktivitas Marketing">
            <div className="space-y-6">
                <form
                    onSubmit={applyFilters}
                    className="rounded-xl border border-slate-200 bg-white p-5 shadow-xs"
                >
                    <h2 className="flex items-center gap-2 text-sm font-bold text-slate-900">
                        <Filter className="h-4 w-4 text-emerald-600" />
                        Filter
                    </h2>
                    <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <div>
                            <label className="text-xs font-semibold text-slate-700">Marketing</label>
                            <select
                                value={marketingId}
                                onChange={(event) => setMarketingId(event.target.value)}
                                className="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                            >
                                <option value="">Semua Marketing</option>
                                {marketers.map((marketer) => (
                                    <option key={marketer.id} value={marketer.id}>
                                        {marketer.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <label className="text-xs font-semibold text-slate-700">Anggota</label>
                            <select
                                value={memberId}
                                onChange={(event) => setMemberId(event.target.value)}
                                className="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                            >
                                <option value="">Semua Anggota</option>
                                {members.map((member) => (
                                    <option key={member.id} value={member.id}>
                                        {member.full_name} ({member.member_number})
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <label className="text-xs font-semibold text-slate-700">
                                Dari Tanggal
                            </label>
                            <input
                                type="date"
                                value={dateFrom}
                                onChange={(event) => setDateFrom(event.target.value)}
                                className="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                            />
                        </div>
                        <div>
                            <label className="text-xs font-semibold text-slate-700">
                                Sampai Tanggal
                            </label>
                            <input
                                type="date"
                                value={dateTo}
                                onChange={(event) => setDateTo(event.target.value)}
                                className="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                            />
                        </div>
                    </div>
                    <div className="mt-4">
                        <button
                            type="submit"
                            className="rounded-lg bg-slate-900 px-4 py-2 text-xs font-semibold text-white hover:bg-slate-800"
                        >
                            Terapkan Filter
                        </button>
                    </div>
                </form>

                <div className="rounded-xl border border-slate-200 bg-white shadow-xs">
                    <ReportTable
                        columns={columns}
                        rows={activities.data}
                        pagination={activities}
                        sort={sort}
                        onSort={handleSort}
                        routeName="shm.marketing.monitoring.index"
                        emptyIcon={Activity}
                        emptyTitle="Belum ada aktivitas"
                        emptyDescription="Belum ada kunjungan Marketing yang tercatat pada filter ini."
                    />
                </div>
            </div>
        </ShmLayout>
    );
}
