import React from 'react';
import { Link } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import StatCard from '@/Components/Admin/StatCard';
import ReportFilters from '@/Components/Admin/ReportFilters';
import ReportTable from '@/Components/Admin/ReportTable';
import useTableSort from '@/hooks/useTableSort';
import { Eye, Landmark, PiggyBank, UserCheck, UserMinus, Users } from 'lucide-react';
import { formatNumber, formatDate } from '@/utils/format';

export default function Anggota({ rows, sort, summary, filters, options, report_slug, export_route, operational_timezone }) {
    const handleSort = useTableSort('laporan.anggota');
    const columns = [
        { key: 'member_number', label: 'Nomor Anggota', className: 'font-mono text-xs font-medium text-slate-900' },
        { key: 'full_name', label: 'Nama Anggota', className: 'font-medium text-slate-900' },
        {
            key: 'identity_number_masked',
            label: 'Nomor Identitas',
            className: 'font-mono text-xs text-slate-500',
            render: (row) => row.identity_number_masked || '-',
        },
        {
            key: 'phone_masked',
            label: 'Telepon',
            className: 'font-mono text-xs text-slate-500',
            render: (row) => row.phone_masked || '-',
        },
        { key: 'joined_at', label: 'Tanggal Bergabung', render: (row) => formatDate(row.joined_at) },
        {
            key: 'status',
            label: 'Status',
            render: (row) => (
                <span
                    className={`inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold ${
                        row.status === 'active' ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-600'
                    }`}
                >
                    {row.status === 'active' ? 'Aktif' : 'Nonaktif'}
                </span>
            ),
        },
        {
            key: 'savings_accounts_count',
            label: 'Rekening',
            align: 'right',
            className: 'tabular-nums text-slate-700',
            render: (row) => formatNumber(row.savings_accounts_count),
        },
        {
            key: 'active_loans_count',
            label: 'Pinjaman Aktif',
            align: 'right',
            className: 'tabular-nums text-slate-700',
            render: (row) => formatNumber(row.active_loans_count),
        },
        {
            key: 'action',
            label: 'Aksi',
            align: 'right',
            render: (row) => (
                <Link
                    href={row.url}
                    className="inline-flex items-center gap-1 rounded border border-slate-300 bg-white px-2.5 py-1 text-xs font-medium text-slate-700 transition-colors hover:bg-slate-50"
                >
                    <Eye className="h-3.5 w-3.5" />
                    Detail
                </Link>
            ),
        },
    ];

    return (
        <AdminLayout title="Laporan Anggota" pageTitle="Laporan Anggota">
            <div className="space-y-6">
                <ReportFilters
                    routeName="laporan.anggota"
                    filters={filters}
                    showPeriod={false}
                    showDateRange
                    exportRoute={export_route}
                    reportSlug={report_slug}
                    timezone={operational_timezone}
                    fields={[
                        {
                            name: 'search',
                            label: 'Pencarian',
                            type: 'text',
                            placeholder: 'Nomor anggota atau nama',
                        },
                        { name: 'status', label: 'Status', type: 'select', options: options.statuses },
                    ]}
                />

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
                    <StatCard
                        title="Total Anggota"
                        value={formatNumber(summary.total)}
                        icon={Users}
                        statusNote="Sesuai filter"
                        scope="Sumber: tabel anggota"
                    />
                    <StatCard
                        title="Anggota Aktif"
                        value={formatNumber(summary.active)}
                        icon={UserCheck}
                        statusNote="Status aktif"
                    />
                    <StatCard
                        title="Anggota Nonaktif"
                        value={formatNumber(summary.inactive)}
                        icon={UserMinus}
                        statusNote="Status nonaktif"
                    />
                    <StatCard
                        title="Punya Rekening Simpanan"
                        value={formatNumber(summary.with_savings_account)}
                        icon={PiggyBank}
                        statusNote="Minimal satu rekening"
                    />
                    <StatCard
                        title="Punya Pinjaman Aktif"
                        value={formatNumber(summary.with_active_loan)}
                        icon={Landmark}
                        statusNote="Status aktif atau dicairkan"
                    />
                </div>

                <div className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-[11px] leading-relaxed text-amber-900">
                    Nomor identitas dan nomor telepon ditampilkan dalam bentuk tersamarkan dan tidak disertakan pada
                    ekspor CSV. Lapisan izin berbasis role belum tersedia, sehingga data pribadi penuh belum dapat
                    ditampilkan.
                </div>

                <ReportTable
                    columns={columns}
                    rows={rows.data}
                    pagination={rows}
                    sort={sort}
                    onSort={handleSort}
                    routeName="laporan.anggota"
                    emptyIcon={Users}
                    emptyTitle="Tidak ada anggota sesuai filter"
                    emptyDescription="Ubah kata kunci, status, atau rentang tanggal bergabung."
                />
            </div>
        </AdminLayout>
    );
}
