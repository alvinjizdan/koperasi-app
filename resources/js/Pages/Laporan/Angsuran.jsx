import React from 'react';
import { Link } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import StatCard from '@/Components/Admin/StatCard';
import ReportFilters from '@/Components/Admin/ReportFilters';
import ReportTable from '@/Components/Admin/ReportTable';
import { Ban, CheckCircle2, Eye, Receipt } from 'lucide-react';
import { formatRupiah, formatNumber, formatDate } from '@/utils/format';

export default function Angsuran({ rows, summary, period, filters, options, report_slug, export_route, operational_timezone }) {
    const columns = [
        { key: 'payment_number', label: 'Nomor Pembayaran', className: 'font-mono text-xs font-medium text-slate-900' },
        { key: 'loan_number', label: 'Nomor Pinjaman', className: 'font-mono text-xs text-slate-600' },
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
        { key: 'payment_date', label: 'Tanggal', render: (row) => formatDate(row.payment_date) },
        {
            key: 'amount',
            label: 'Nominal',
            align: 'right',
            className: 'font-semibold tabular-nums text-slate-900',
            render: (row) => formatRupiah(row.amount),
        },
        { key: 'operator_name', label: 'Operator', render: (row) => row.operator_name || '-' },
        {
            key: 'status',
            label: 'Status',
            render: (row) => (
                <span
                    className={`inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold ${
                        row.status === 'COMPLETED' ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-600'
                    }`}
                >
                    {row.status_label}
                </span>
            ),
        },
        {
            key: 'notes',
            label: 'Keterangan',
            className: 'text-xs text-slate-500',
            render: (row) => row.notes || '-',
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
        <AdminLayout title="Laporan Pembayaran Angsuran" pageTitle="Laporan Pembayaran Angsuran">
            <div className="space-y-6">
                <ReportFilters
                    routeName="laporan.angsuran"
                    filters={filters}
                    periodLabel={period?.label}
                    timezone={operational_timezone}
                    exportRoute={export_route}
                    reportSlug={report_slug}
                    fields={[
                        { name: 'search', label: 'Pencarian', type: 'text', placeholder: 'Nomor pembayaran, pinjaman, atau anggota' },
                        { name: 'operator_id', label: 'Operator', type: 'select', options: [{ value: 'all', label: 'Semua Operator' }, ...options.operators] },
                        { name: 'status', label: 'Status', type: 'select', options: options.statuses },
                    ]}
                />

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard
                        title="Pembayaran Berhasil"
                        value={formatRupiah(summary.completed_total)}
                        icon={CheckCircle2}
                        statusNote={`${formatNumber(summary.completed_count)} transaksi berhasil`}
                        scope="Hanya transaksi berstatus berhasil"
                    />
                    <StatCard
                        title="Pembayaran Dibatalkan"
                        value={formatRupiah(summary.reversed_total)}
                        icon={Ban}
                        statusNote={`${formatNumber(summary.reversed_count)} transaksi dibatalkan`}
                        scope="Tidak dijumlahkan ke total pembayaran berhasil"
                    />
                    <StatCard
                        title="Total Transaksi Tercatat"
                        value={formatNumber(summary.payments_count)}
                        icon={Receipt}
                        statusNote={period?.label}
                        scope="Seluruh status dalam periode"
                    />
                    <StatCard
                        title="Pinjaman Terkait"
                        value={formatNumber(summary.loans_count)}
                        icon={Receipt}
                        statusNote="Jumlah pinjaman unik"
                        scope="Berdasarkan filter aktif"
                    />
                </div>

                <div className="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-[11px] leading-relaxed text-slate-600">
                    Alokasi pokok dan bunga per pembayaran tidak ditampilkan karena aturan alokasinya belum
                    dikonfirmasi. Setiap transaksi dihitung satu kali berdasarkan nomor pembayarannya.
                </div>

                <ReportTable
                    columns={columns}
                    rows={rows.data}
                    pagination={rows}
                    emptyIcon={Receipt}
                    emptyTitle="Tidak ada pembayaran sesuai filter"
                    emptyDescription="Ubah periode, operator, status, atau kata kunci pencarian."
                />
            </div>
        </AdminLayout>
    );
}
