import React from 'react';
import { Link } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import StatCard from '@/Components/Admin/StatCard';
import ReportFilters from '@/Components/Admin/ReportFilters';
import ReportTable from '@/Components/Admin/ReportTable';
import { AlertTriangle, CalendarClock, Eye, Landmark, Receipt } from 'lucide-react';
import { formatRupiah, formatNumber, formatDate } from '@/utils/format';

export default function Tunggakan({ rows, summary, filters, report_slug, export_route, operational_timezone }) {
    const columns = [
        { key: 'loan_number', label: 'Nomor Pinjaman', className: 'font-mono text-xs font-medium text-slate-900' },
        {
            key: 'member',
            label: 'Anggota',
            render: (row) => (
                <div>
                    <p className="font-medium text-slate-900">{row.member_name || '-'}</p>
                    <p className="font-mono text-[11px] text-slate-400">{row.member_number || '-'}</p>
                </div>
            ),
        },
        {
            key: 'installment_number',
            label: 'Angsuran Ke',
            align: 'right',
            className: 'tabular-nums text-slate-700',
            render: (row) => formatNumber(row.installment_number),
        },
        { key: 'due_date', label: 'Jatuh Tempo', render: (row) => formatDate(row.due_date) },
        {
            key: 'total_amount',
            label: 'Nominal Tagihan',
            align: 'right',
            className: 'tabular-nums text-slate-900',
            render: (row) => formatRupiah(row.total_amount),
        },
        {
            key: 'paid_amount',
            label: 'Sudah Dialokasikan',
            align: 'right',
            className: 'tabular-nums text-emerald-700',
            render: (row) => formatRupiah(row.paid_amount),
        },
        {
            key: 'remaining_amount',
            label: 'Sisa Tagihan',
            align: 'right',
            className: 'font-semibold tabular-nums text-rose-700',
            render: (row) => formatRupiah(row.remaining_amount),
        },
        {
            key: 'status_label',
            label: 'Status Angsuran',
            render: (row) => (
                <span className="inline-flex items-center whitespace-nowrap rounded-full bg-rose-100 px-2 py-0.5 text-[11px] font-semibold text-rose-800">
                    {row.status_label}
                </span>
            ),
        },
        {
            key: 'days_late',
            label: 'Hari Terlambat',
            align: 'right',
            className: 'tabular-nums font-semibold text-rose-700',
            render: (row) => formatNumber(row.days_late),
        },
        {
            key: 'action',
            label: 'Aksi',
            align: 'right',
            render: (row) => (
                <div className="flex items-center justify-end gap-2">
                    <Link
                        href={row.schedule_url}
                        className="inline-flex items-center gap-1 rounded border border-slate-300 bg-white px-2.5 py-1 text-xs font-medium text-slate-700 transition-colors hover:bg-slate-50"
                    >
                        <CalendarClock className="h-3.5 w-3.5" />
                        Jadwal
                    </Link>
                    <Link
                        href={row.loan_url}
                        className="inline-flex items-center gap-1 rounded border border-slate-300 bg-white px-2.5 py-1 text-xs font-medium text-slate-700 transition-colors hover:bg-slate-50"
                    >
                        <Eye className="h-3.5 w-3.5" />
                        Pinjaman
                    </Link>
                </div>
            ),
        },
    ];

    return (
        <AdminLayout title="Laporan Tunggakan" pageTitle="Laporan Tunggakan">
            <div className="space-y-6">
                <ReportFilters
                    routeName="laporan.tunggakan"
                    filters={filters}
                    showPeriod={false}
                    showDateRange={false}
                    timezone={operational_timezone}
                    exportRoute={export_route}
                    reportSlug={report_slug}
                    fields={[
                        { name: 'search', label: 'Pencarian', type: 'text', placeholder: 'Nomor pinjaman, nama, atau nomor anggota' },
                        { name: 'as_of_date', label: 'Per Tanggal', type: 'date' },
                        { name: 'minimum_days_late', label: 'Minimal Hari Terlambat', type: 'number', min: 0, placeholder: '0' },
                    ]}
                />

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard
                        title="Total Sisa Tagihan"
                        value={formatRupiah(summary.total_remaining)}
                        icon={AlertTriangle}
                        statusNote={`${formatNumber(summary.schedules_count)} angsuran menunggak`}
                        scope={`Per tanggal ${formatDate(summary.as_of_date)}`}
                    />
                    <StatCard
                        title="Pinjaman Terdampak"
                        value={formatNumber(summary.loans_count)}
                        icon={Landmark}
                        statusNote="Jumlah pinjaman unik"
                        scope="Berdasarkan filter aktif"
                    />
                    <StatCard
                        title="Tagihan & Alokasi"
                        value={`${formatRupiah(summary.total_billed)} / ${formatRupiah(summary.total_paid)}`}
                        icon={Receipt}
                        statusNote="Nominal tagihan / sudah dialokasikan"
                        scope="Nilai tersimpan pada jadwal angsuran"
                    />
                    <StatCard
                        title="Keterlambatan Terlama"
                        value={summary.oldest_due_date ? `${formatNumber(summary.max_days_late)} hari` : '—'}
                        icon={CalendarClock}
                        statusNote={summary.oldest_due_date ? `Jatuh tempo tertua: ${formatDate(summary.oldest_due_date)}` : 'Tidak ada tunggakan'}
                        scope="Denda tidak dihitung"
                    />
                </div>

                <div className="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-[11px] leading-relaxed text-slate-600">
                    Tunggakan hanya mencakup angsuran yang sudah melewati tanggal jatuh tempo dan masih memiliki sisa
                    tagihan; angsuran yang belum jatuh tempo tidak dihitung. Denda keterlambatan tidak dihitung karena
                    aturannya belum dikonfirmasi. Sisa tagihan berasal dari nilai alokasi pembayaran yang tersimpan pada
                    jadwal angsuran.
                </div>

                <ReportTable
                    columns={columns}
                    rows={rows.data}
                    pagination={rows}
                    emptyIcon={AlertTriangle}
                    emptyTitle="Tidak ada tunggakan"
                    emptyDescription="Seluruh angsuran pada filter ini belum melewati jatuh tempo atau sudah lunas."
                />
            </div>
        </AdminLayout>
    );
}
