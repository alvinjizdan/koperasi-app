import React from 'react';
import { Link } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import StatCard from '@/Components/Admin/StatCard';
import ReportFilters from '@/Components/Admin/ReportFilters';
import ReportTable from '@/Components/Admin/ReportTable';
import { Banknote, Eye, Landmark, Scale, TrendingUp, Wallet } from 'lucide-react';
import { formatRupiah, formatNumber, formatDate } from '@/utils/format';

const STATUS_TONES = {
    PENDING: 'bg-amber-100 text-amber-800',
    APPROVED: 'bg-blue-100 text-blue-800',
    REJECTED: 'bg-rose-100 text-rose-800',
    DISBURSED: 'bg-indigo-100 text-indigo-800',
    ACTIVE: 'bg-emerald-100 text-emerald-800',
    PAID_OFF: 'bg-slate-200 text-slate-700',
    CANCELLED: 'bg-slate-200 text-slate-600',
};

export default function Pinjaman({ rows, summary, period, filters, options, report_slug, export_route, operational_timezone }) {
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
        { key: 'submission_date', label: 'Pengajuan', render: (row) => formatDate(row.submission_date) },
        { key: 'approved_at', label: 'Persetujuan', render: (row) => (row.approved_at ? formatDate(row.approved_at) : '—') },
        {
            key: 'disbursed_at',
            label: 'Pencairan',
            render: (row) => (
                <div>
                    <p>{row.disbursed_at ? formatDate(row.disbursed_at) : '—'}</p>
                    {row.disbursement_method && (
                        <p className="text-[11px] text-slate-400">{row.disbursement_method}</p>
                    )}
                </div>
            ),
        },
        {
            key: 'amount',
            label: 'Nominal Pokok',
            align: 'right',
            className: 'tabular-nums text-slate-900',
            render: (row) => formatRupiah(row.amount),
        },
        {
            key: 'tenor_months',
            label: 'Tenor',
            align: 'right',
            className: 'tabular-nums text-slate-600',
            render: (row) => `${row.tenor_months} bln`,
        },
        {
            key: 'status',
            label: 'Status',
            render: (row) => (
                <span
                    className={`inline-flex items-center whitespace-nowrap rounded-full px-2 py-0.5 text-[11px] font-semibold ${
                        STATUS_TONES[row.status] || 'bg-slate-100 text-slate-600'
                    }`}
                >
                    {row.status_label}
                </span>
            ),
        },
        {
            key: 'paid_total',
            label: 'Pembayaran Berhasil',
            align: 'right',
            className: 'tabular-nums text-emerald-700',
            render: (row) => formatRupiah(row.paid_total),
        },
        {
            key: 'remaining_balance',
            label: 'Sisa Kewajiban',
            align: 'right',
            className: 'font-semibold tabular-nums text-slate-900',
            render: (row) => (row.remaining_balance === null ? 'Belum dicairkan' : formatRupiah(row.remaining_balance)),
        },
        {
            key: 'next_due_date',
            label: 'Jatuh Tempo Berikutnya',
            render: (row) => (row.next_due_date ? formatDate(row.next_due_date) : '—'),
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
        <AdminLayout title="Laporan Pinjaman" pageTitle="Laporan Pinjaman">
            <div className="space-y-6">
                <ReportFilters
                    routeName="laporan.pinjaman"
                    filters={filters}
                    periodLabel={period?.label}
                    timezone={operational_timezone}
                    exportRoute={export_route}
                    reportSlug={report_slug}
                    fields={[
                        { name: 'search', label: 'Pencarian', type: 'text', placeholder: 'Nomor pinjaman atau nama anggota' },
                        { name: 'status', label: 'Status', type: 'select', options: options.statuses },
                        { name: 'period_basis', label: 'Dasar Periode', type: 'select', options: options.periodBasis },
                    ]}
                />

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard
                        title="Pinjaman Sesuai Filter"
                        value={formatNumber(summary.total)}
                        icon={Landmark}
                        statusNote={`Nominal pengajuan: ${formatRupiah(summary.submitted_amount)}`}
                        scope="Sumber: tabel pinjaman"
                    />
                    <StatCard
                        title="Diajukan / Disetujui"
                        value={`${formatNumber(summary.pending_count)} / ${formatNumber(summary.approved_count)}`}
                        icon={TrendingUp}
                        statusNote="Menunggu / siap dicairkan"
                        scope="Disetujui tidak selalu sudah dicairkan"
                    />
                    <StatCard
                        title="Total Dicairkan"
                        value={formatRupiah(summary.disbursed_amount)}
                        icon={Banknote}
                        available={summary.has_disbursements}
                        statusNote={`${formatNumber(summary.disbursed_count)} pencairan`}
                        scope="Sumber: tabel pencairan pinjaman"
                    />
                    <StatCard
                        title="Sisa Kewajiban"
                        value={formatRupiah(summary.outstanding_amount)}
                        icon={Scale}
                        available={summary.has_disbursements}
                        statusNote={`Sudah dibayar: ${formatRupiah(summary.paid_total)}`}
                        scope="Nominal dicairkan dikurangi pembayaran berhasil"
                    />
                </div>

                <div className="flex flex-wrap gap-2">
                    {summary.by_status.map((group) => (
                        <span
                            key={group.status}
                            className={`inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-[11px] font-semibold ${
                                STATUS_TONES[group.status] || 'bg-slate-100 text-slate-600'
                            }`}
                        >
                            {group.label}
                            <span className="rounded-full bg-white/70 px-1.5 tabular-nums">{formatNumber(group.count)}</span>
                            <span className="tabular-nums">{formatRupiah(group.amount)}</span>
                        </span>
                    ))}
                </div>

                <div className="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-[11px] leading-relaxed text-slate-600">
                    Bunga, denda, dan angka pelunasan final tidak dihitung karena rumusnya belum dikonfirmasi. Sisa
                    kewajiban hanya ditampilkan untuk pinjaman yang sudah dicairkan.
                </div>

                <ReportTable
                    columns={columns}
                    rows={rows.data}
                    pagination={rows}
                    emptyIcon={Landmark}
                    emptyTitle="Tidak ada pinjaman sesuai filter"
                    emptyDescription="Ubah periode, dasar periode, status, atau kata kunci pencarian."
                />
            </div>
        </AdminLayout>
    );
}
