import React from 'react';
import { Link } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import StatCard from '@/Components/Admin/StatCard';
import ReportFilters from '@/Components/Admin/ReportFilters';
import ReportTable from '@/Components/Admin/ReportTable';
import { ArrowDownCircle, ArrowLeftRight, ArrowUpCircle, Banknote, Eye, Receipt } from 'lucide-react';
import { formatRupiah, formatNumber, formatDate, formatDateTime } from '@/utils/format';

export default function TransaksiKas({ rows, summary, period, filters, options, report_slug, export_route, operational_timezone }) {
    const columns = [
        { key: 'transaction_number', label: 'Nomor Transaksi', className: 'font-mono text-xs font-medium text-slate-900' },
        {
            key: 'date',
            label: 'Tanggal / Waktu',
            render: (row) => (
                <div>
                    <p className="text-slate-700">{formatDate(row.transaction_date)}</p>
                    <p className="text-[11px] text-slate-400">{formatDateTime(row.transacted_at)}</p>
                </div>
            ),
        },
        { key: 'type_label', label: 'Jenis', render: (row) => row.type_label },
        { key: 'category', label: 'Kategori', className: 'text-slate-600', render: (row) => row.category || '-' },
        {
            key: 'amount',
            label: 'Nominal',
            align: 'right',
            className: 'font-semibold tabular-nums',
            render: (row) => (
                <span className={row.increases_cash ? 'text-emerald-700' : 'text-rose-700'}>
                    {row.increases_cash ? '+' : '−'}
                    {formatRupiah(row.amount)}
                </span>
            ),
        },
        { key: 'operator_name', label: 'Operator', render: (row) => row.operator_name || '-' },
        { key: 'session_number', label: 'Sesi', className: 'font-mono text-xs text-slate-500', render: (row) => row.session_number || '-' },
        { key: 'source_label', label: 'Referensi Sumber', className: 'text-xs text-slate-500', render: (row) => row.source_label || '-' },
        {
            key: 'status',
            label: 'Status',
            render: (row) => (
                <span
                    className={`inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold ${
                        row.status === 'POSTED' ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-600'
                    }`}
                >
                    {row.status_label}
                </span>
            ),
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
        <AdminLayout title="Laporan Transaksi Kas" pageTitle="Laporan Transaksi Kas">
            <div className="space-y-6">
                <ReportFilters
                    routeName="laporan.transaksi-kas"
                    filters={filters}
                    periodLabel={period?.label}
                    timezone={operational_timezone}
                    exportRoute={export_route}
                    reportSlug={report_slug}
                    fields={[
                        { name: 'search', label: 'Pencarian', type: 'text', placeholder: 'Nomor transaksi' },
                        { name: 'type', label: 'Jenis', type: 'select', options: options.types },
                        { name: 'category', label: 'Kategori', type: 'select', options: [{ value: 'all', label: 'Semua Kategori' }, ...options.categories] },
                        { name: 'operator_id', label: 'Operator', type: 'select', options: [{ value: 'all', label: 'Semua Operator' }, ...options.operators] },
                        { name: 'status', label: 'Status', type: 'select', options: options.statuses },
                    ]}
                />

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard
                        title="Penerimaan Kas"
                        value={formatRupiah(summary.income_total)}
                        icon={ArrowDownCircle}
                        statusNote={period?.label}
                        scope="Transaksi berstatus berlaku"
                    />
                    <StatCard
                        title="Pengeluaran Kas"
                        value={formatRupiah(summary.expense_total)}
                        icon={ArrowUpCircle}
                        statusNote={period?.label}
                        scope="Transaksi berstatus berlaku"
                    />
                    <StatCard
                        title="Setoran Kasir"
                        value={formatRupiah(summary.deposit_total)}
                        icon={Banknote}
                        statusNote="Perpindahan kas internal"
                        scope="Tidak dicampur dengan penerimaan/pengeluaran eksternal"
                    />
                    <StatCard
                        title="Perubahan Kas Fisik"
                        value={formatRupiah(summary.drawer_movement)}
                        icon={ArrowLeftRight}
                        statusNote={`${formatNumber(summary.posted_count)} transaksi berlaku`}
                        scope="Penerimaan − pengeluaran − setoran internal"
                    />
                </div>

                {summary.reversed_count > 0 && (
                    <div className="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-[11px] leading-relaxed text-slate-600">
                        Terdapat {formatNumber(summary.reversed_count)} transaksi dibatalkan senilai{' '}
                        {formatRupiah(summary.reversed_total)} pada filter ini. Transaksi tersebut tidak dihitung pada
                        total penerimaan maupun pengeluaran.
                    </div>
                )}

                <ReportTable
                    columns={columns}
                    rows={rows.data}
                    pagination={rows}
                    emptyIcon={Receipt}
                    emptyTitle="Tidak ada transaksi kas"
                    emptyDescription="Ubah periode, jenis, kategori, operator, atau status."
                />
            </div>
        </AdminLayout>
    );
}
